<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Item;
use App\ItemDetail;
use App\Location;
use App\StockAdjustment;
use App\StockAdjustmentHeader;
use DateTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class StockAdjustmentController extends Controller
{
    //
    public function messages()
    {
        return [
            'items.required' => 'The items array is required.',
            'items.*.code.required' => 'Each item must have an CODE.',
            'items.*.location_id.required' => 'Each item must have a location ID.',
            'items.*.qty.required' => 'Each item must have a quantity.',
        ];
    }

    public function adjustment(Request $request)
    {
        $validator = Validator::make($request->all(), [
            "transaction_date"      => "required",
            'reason'                => 'required',
            'items'                 => 'required|array', // Ensure 'items' is a required array
            'items.*.code'          => 'required', // 'code' must be an integer
            'items.*.location_id'   => 'required|integer', // 'location_id' must be an integer
            'items.*.qty'           => 'required|integer', // 'qty' must be an integer
        ]);

        if ($validator->fails()) {
            $errorMsg = '';
            
            foreach ($validator->errors()->all() as $error)
            {
                $errorMsg .= $error . '<br>';
            }
            
            return response()->json([
                "status" => false,
                "message" => $errorMsg
            ], 400);
        }

        $error = 0;
        $errorMsg = [];

        $date = strtotime($request->transaction_date);
        $transactionDate = date('Y-m-d H:i:s',$date);

        $date = new DateTime('now');
        $month = $date->format('my');

        $seq = DB::select("
            SELECT
                count(doc_number) as seq
            FROM
                stock_adjustment_header
            WHERE
                DATE_FORMAT(created_at, '%m%y') <= STR_TO_DATE(?, '%m%y')
                AND doc_number IS NOT NULL
        ", [$month]);

        $docDate = $date->format('dmY');
        $documentNumber = 'ADJ-'.$docDate.'-'.str_pad(($seq[0]->seq+1), 4, '0', STR_PAD_LEFT);

        DB::beginTransaction();
        try
        {
            $header = StockAdjustmentHeader::create([
                'transaction_date'  => $transactionDate,
                'reason'            => $request->reason,
                'doc_number'        => $documentNumber,
                'created_by'        => auth()->user()->id,
                'updated_by'        => auth()->user()->id,
                'status'            => 1
            ]);

            foreach ($request->items as $item)
            {
                if (!Item::where('item_code', $item['code'])->where('status', 1)->exists())
                {
                    $error++;
                    array_push($errorMsg,'Item ' . $item['code'] . ' is not exist !');
                }

                if ($error == 0)
                {
                    $itemDet = ItemDetail::where('item_code', $item['code'])->where('location_id', $item['location_id'])->where('status', 1)->first();

                    // insert to item detail
                    if ($itemDet != null)
                    {
                        $itemDet->update([
                            'qty'           => $itemDet->qty + $item['qty'],
                            'updated_by'    => auth()->user()->id,
                            'updated_at'    => date("Y-m-d H:i:s"),
                            'status'        => 1
                        ]);

                        StockAdjustment::create([
                            'stock_adjustment_id'   => $header->id,
                            'item_detail_id'        => $itemDet->id,
                            'qty'                   => $item['qty'],
                            'created_by'            => auth()->user()->id,
                            'updated_by'            => auth()->user()->id,
                            'status'                => 1
                        ]);
                    }
                    else
                    {

                        $itemDet = ItemDetail::create([
                            'item_code'     => $item['item_code'],
                            'location_id'   => $item['location_id'],
                            'qty'           => $item['qty'],
                            'price'         => null,
                            'created_by'    => auth()->user()->id,
                            'updated_by'    => auth()->user()->id,
                            'status'        => 1
                        ]);
                    }
                }
            }

            if ($error > 0)
            {
                DB::rollBack();

                return response()->json([
                    "status" => false,
                    "message" => $errorMsg
                ], 404);
            }

            DB::commit();

            return response()->json([
                "status" => true,
                "message" => "Stock Adjustment Success"
            ]);
        }
        catch (\Throwable $e)
        {
            DB::rollBack();

            return response()->json([
                "status" => false,
                "message" => $e
            ], 500);
        }
    }

    public function getAdjustment(Request $request)
    {
        $sortBy = $request->input('sort_by', 'transaction_date');
        $sortOrder = $request->input('sort_order', 'desc');

        $search = $request->input('search', null);
        $reason = $request->input('search_reason', null);
        $searchDocNumber = $request->input('search_doc_number', null);

        $startAdjDate = $request->input('search_start_adj_date', null);
        $endAdjDate = $request->input('search_end_adj_date', null);

        if ($startAdjDate > $endAdjDate) {
            return response()->json([
                "status" => false,
                "message" => "The start date cannot be after the end date."
            ], 400);
        }

        if (!in_array($sortOrder, ['asc', 'desc'])) {
            $sortOrder = 'desc'; // Default to ascending if invalid
        }

        $query  = StockAdjustmentHeader::query();

        if ($search != null)
        {
            $query->orWhere('stock_adjustment_header.doc_number', 'like', '%' . $searchDocNumber . '%');
            $query->orWhere('stock_adjustment_header.reason', 'like', '%' . $reason . '%');
        }
        else
        {
            if ($searchDocNumber != null)
            {
                $query->where('stock_adjustment_header.doc_number', 'like', '%' . $searchDocNumber . '%');
            }

            if ($reason != null)
            {
                $query->where('stock_adjustment_header.reason', 'like', '%' . $reason . '%');
            }

            if ($startAdjDate != null && $endAdjDate != null)
            {
                $query->whereBetween('stock_adjustment_header.transaction_date', [$startAdjDate, $endAdjDate]);
            }

            if ($reason == null && $searchDocNumber == null && $startAdjDate == null && $endAdjDate == null)
            {
                $startAdjDate = (new DateTime($startAdjDate))->modify('first day of this month')->format('Y-m-d');
                $date = new DateTime('now');
                $endAdjDate = $date->format('Y-m-d');
                $query->whereBetween('sales.sales_date', [$startAdjDate, $endAdjDate]);
            }
        }

        $stockAdjustment = $query->orderBy($sortBy, $sortOrder)->orderBy('id', 'desc')->paginate(10);
        $stockAdjustment->appends([
            'sort_by' => $sortBy,
            'sort_order' => $sortOrder,
        ]);

        return response()->json([
            'status' => true,
            'data' => $stockAdjustment->items(),
            'pagination' => [
                'current_page' => $stockAdjustment->currentPage(),
                'total_pages' => $stockAdjustment->lastPage(),
                'next_page' => $stockAdjustment->nextPageUrl(),
                'prev_page' => $stockAdjustment->previousPageUrl(),
            ],
        ]);
    }

    public function getAdjustmentDetail($id)
    {
        if(StockAdjustmentHeader::where('id', $id)->where('status',1)->exists())
        {
            $adj = StockAdjustmentHeader::where('id', $id)->where('status', 1)->first();

            $adjDet = DB::table('stock_adjustment')
                            ->join('items_details', 'stock_adjustment.item_detail_id', '=', 'items_details.id')
                            ->join('items', 'items_details.item_code', '=', 'items.item_code')
                            ->join('locations', 'items_details.location_id', '=', 'locations.id')
                            ->where('stock_adjustment.stock_adjustment_id', $id)
                            ->select('items.item_code', 'stock_adjustment.qty', 'items.name as item_name', 'items.image as item_image', 'items.category', 'locations.name as location_name', 'locations.id as location_id')
                            ->get();

            $data = $adj;
            $data['details'] = $adjDet;

            return response()->json([
                "status"    => true,
                "data"      => $data
            ]);
        }
        else
        {
            return response()->json([
                "status" => false,
                "message" => "Sales not found"
            ], 404);
        }
    }

    public function rejectAdjustment($id)
    {
        $stockAdjustment = StockAdjustment::where('id', $id)->where('status', 1)->first();

        if ($stockAdjustment == null)
        {
            return response()->json([
                "status" => false,
                "message" => "Stock Adjustment not found or inactive !"
            ], 404);
        }
        
        DB::beginTransaction();
        try
        {
            $stockAdjustment->update([
                'status' => 2
            ]);
            
            $itemDet = ItemDetail::where('id', $stockAdjustment->item_detail_id)->where('status', 1)->first();

            if ($itemDet == null)
            {
                DB::rollBack();

                return response()->json([
                    "status" => false,
                    "message" => "Item not found or already deleted !"
                ], 404);
            }
            
            $itemDet->update([
                'qty'           => $itemDet->qty - $stockAdjustment->qty,
                'updated_by'    => auth()->user()->id,
                'updated_at'    => date("Y-m-d H:i:s"),
                'status'        => 1
            ]);
            
            DB::commit();

            return response()->json([
                "status" => true,
                "message" => "Stock Adjustment Rejected"
            ]);
        }
        catch (\Throwable $e)
        {
            DB::rollBack();

            return response()->json([
                "status" => false,
                "message" => $e
            ], 500);
        }
    }
}
