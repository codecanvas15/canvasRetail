<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Item;
use App\ItemDetail;
use App\Location;
use App\StockUsage;
use App\StockUsageHeader;
use App\VoidDetails;
use App\VoidTransaction;
use DateTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class StockUsageController extends Controller
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

    public function usage(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'transaction_date'      => 'required',
            'reason'                => 'required',
            'user_item_name'        => 'required',
            'items'                 => 'required|array', // Ensure 'items' is a required array
            'items.*.code'          => 'required', // 'code' must be an integer
            'items.*.location_id'   => 'required|integer', // 'location_id' must be an integer
            'items.*.qty'           => 'required|integer|min:0', // 'qty' must be an integer
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
                stock_usage_header
            WHERE
                DATE_FORMAT(created_at, '%m%y') <= STR_TO_DATE(?, '%m%y')
                AND doc_number IS NOT NULL
        ", [$month]);

        $docDate = $date->format('dmY');
        $documentNumber = 'STK-'.$docDate.'-'.str_pad(($seq[0]->seq+1), 4, '0', STR_PAD_LEFT);

        DB::beginTransaction();
        try
        {
            $header = StockUsageHeader::create([
                'transaction_date'  => $transactionDate,
                'reason'            => $request->reason,
                'doc_number'        => $documentNumber,
                'user_item_name'    => $request->user_item_name,
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
                        // $itemDet->update([
                        //     'qty'           => $itemDet->qty - $item['qty'],
                        //     'updated_by'    => auth()->user()->id,
                        //     'updated_at'    => date("Y-m-d H:i:s"),
                        //     'status'        => 1
                        // ]);

                        StockUsage::create([
                            'stock_usage_id'        => $header->id,
                            'item_detail_id'        => $itemDet->id,
                            'qty'                   => $item['qty'],
                            'created_by'            => auth()->user()->id,
                            'updated_by'            => auth()->user()->id,
                            'status'                => 1
                        ]);
                    }
                    else
                    {
                        $location = Location::where('id', $item['location_id'])->where('status', 1)->first();

                        $error++;
                        array_push($errorMsg, 'Item ' . $item['code'] . ' at ' . $location->name . ' not found !');
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

            $description = "Create Stock Usage\n
            request : " . json_encode($request->all()) . "
            response : Stock Usage Success". "\n
            on " . date("Y-m-d H:i:s") . "
            by " . auth()->user()->username;

            $this->history('stock_usage', 'create stock usage', $description);

            DB::commit();

            return response()->json([
                "status" => true,
                "message" => "Stock Usage Success"
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

    public function approveUsage(Request $request)
    {
        $validator = Validator::make($request->all(), [
            "usage_id"    => "required",
            "is_approve"  => "required||in:1,0",
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

        $stockUsage = StockUsageHeader::where('id', $request->usage_id)->where('status', 1)->first();
        
        if ($stockUsage == null)
        {
            return response()->json([
                "status" => false,
                "message" => "Stock Usage not found"
            ], 404);
        }
        
        DB::beginTransaction();

        try
        {
            if (!$request->is_approve)
            {
                $stockUsage->update([
                    'status'        => 3,
                    'updated_by'    => auth()->user()->id,
                    'updated_at'    => date("Y-m-d H:i:s")
                ]);

                $description = "Approve Stock Usage\n
            request : " . json_encode($request->all()) . "
            response : Stock Usage Rejected". "\n
            on " . date("Y-m-d H:i:s") . "
            by " . auth()->user()->username;

            $this->history('stock_usage', 'approve stock usage', $description);
    
                DB::commit();
    
                return response()->json([
                    "status" => true,
                    "message" => "Stock Usage Rejected"
                ]);
            }

            $stockUsage->update([
                'status'        => 2,
                'updated_by'    => auth()->user()->id,
                'updated_at'    => date("Y-m-d H:i:s")
            ]);
            
            $usageDetail = StockUsage::where('stock_usage_id', $request->usage_id)->where('status', 1)->get();
    
            foreach ($usageDetail as $item)
            {
                $itemDet = ItemDetail::where('id', $item->item_detail_id)->where('status', 1)->first();
                
                if ($itemDet == null)
                {
                    $itemDet = ItemDetail::create([
                        'item_code'     => $item['item_code'],
                        'location_id'   => $request->location_id,
                        'qty'           => $item['qty'],
                        'price'         => $item['price'],
                        'created_by'    => auth()->user()->id,
                        'updated_by'    => auth()->user()->id,
                        'status'        => 1
                    ]);
                }
                else
                {
                    $itemDet->update([
                        'qty'           => $itemDet->qty - $item->qty,
                        'updated_by'    => auth()->user()->id,
                        'updated_at'    => date("Y-m-d H:i:s"),
                    ]);
                }
            }

            $description = "Approve Stock Usage\n
            request : " . json_encode($request->all()) . "
            response : Stock Usage Approved". "\n
            on " . date("Y-m-d H:i:s") . "
            by " . auth()->user()->username;

            $this->history('stock_usage', 'approve stock usage', $description);
    
            DB::commit();
    
            return response()->json([
                "status" => true,
                "message" => "Stock Usage Approved"
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

    public function getUsage(Request $request)
    {
        $sortBy = $request->input('sort_by', 'transaction_date');
        $sortOrder = $request->input('sort_order', 'desc');

        $search = $request->input('search', null);
        $reason = $request->input('search_reason', null);
        $searchDocNumber = $request->input('search_doc_number', null);
        $searchStatus = $request->input('search_status', null);

        $startUsageDate = $request->input('search_start_usage_date', null);
        $endUsageDate = $request->input('search_end_usage_date', null);

        if ($startUsageDate > $endUsageDate) {
            return response()->json([
                "status" => false,
                "message" => "The start date cannot be after the end date."
            ], 400);
        }

        if (!in_array($sortOrder, ['asc', 'desc'])) {
            $sortOrder = 'desc'; // Default to ascending if invalid
        }

        $query  = StockUsageHeader::query();

        if ($search != null)
        {
            $query->orWhere('stock_usage_header.doc_number', 'like', '%' . $searchDocNumber . '%');
            $query->orWhere('stock_usage_header.reason', 'like', '%' . $reason . '%');
        }
        else
        {
            if ($searchDocNumber != null)
            {
                $query->where('stock_usage_header.doc_number', 'like', '%' . $searchDocNumber . '%');
            }

            if ($reason != null)
            {
                $query->where('stock_usage_header.reason', 'like', '%' . $reason . '%');
            }

            if ($startUsageDate != null && $endUsageDate != null)
            {
                $query->whereBetween('stock_usage_header.transaction_date', [$startUsageDate, $endUsageDate]);
            }

            if ($reason == null && $searchDocNumber == null && $startUsageDate == null && $endUsageDate == null)
            {
                $startUsageDate = (new DateTime($startUsageDate))->modify('first day of this month')->format('Y-m-d');
                $date = new DateTime('now');
                $endUsageDate = $date->format('Y-m-d');
                $query->whereBetween('stock_usage_header.transaction_date', [$startUsageDate, $endUsageDate]);
            }

            if ($searchStatus != null)
            {
                $query->where('stock_usage_header.status', $searchStatus);
            }
        }

        $stockUsage = $query->orderBy($sortBy, $sortOrder)->orderBy('id', 'desc')->paginate(10);
        $stockUsage->appends([
            'sort_by' => $sortBy,
            'sort_order' => $sortOrder,
        ]);

        return response()->json([
            'status' => true,
            'data' => $stockUsage->items(),
            'pagination' => [
                'current_page' => $stockUsage->currentPage(),
                'total_pages' => $stockUsage->lastPage(),
                'next_page' => $stockUsage->nextPageUrl(),
                'prev_page' => $stockUsage->previousPageUrl(),
                'total_data' => $stockUsage->total(),
            ],
        ]);
    }

    public function getUsageDetail($id)
    {
        if(StockUsageHeader::where('id', $id)->where('status',1)->exists())
        {
            $usg = StockUsageHeader::where('id', $id)->where('status', 1)->first();

            $usgDet = DB::table('stock_usage')
                            ->join('items_details', 'stock_usage.item_detail_id', '=', 'items_details.id')
                            ->join('items', 'items_details.item_code', '=', 'items.item_code')
                            ->join('locations', 'items_details.location_id', '=', 'locations.id')
                            ->where('stock_usage.stock_usage_id', $id)
                            ->select('items.item_code', 'stock_usage.qty', 'items.name as item_name', 'items.image as item_image', 'items.category', 'locations.name as location_name', 'locations.id as location_id')
                            ->get();

            $data = $usg;
            $data['details'] = $usgDet;

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

    public function rejectUsage($id)
    {
        $stockUsage = StockUsage::where('id', $id)->where('status', 1)->first();

        if ($stockUsage == null)
        {
            return response()->json([
                "status" => false,
                "message" => "Stock Usage not found or inactive !"
            ], 404);
        }
        
        DB::beginTransaction();
        try
        {
            $stockUsage->update([
                'status' => 3,
                'updated_by' => auth()->user()->id,
                'updated_at' => date("Y-m-d H:i:s")
            ]);
            
            DB::commit();

            return response()->json([
                "status" => true,
                "message" => "Stock Usage Rejected"
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

    public function updateUsage(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            "items"    => "array"
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

        if (!StockUsageHeader::where('id', $id)->where('status', 1)->exists())
        {
            return response()->json([
                "status" => false,
                "message" => "Stock Adjustment not found"
            ], 404);
        }

        $stockUsage = StockUsageHeader::where('id', $id)->whereIn('status', [1, 3])->first();

        if ($stockUsage == null)
        {
            return response()->json([
                "status" => false,
                "message" => "Stock Usage not found or already approved !"
            ], 404);
        }

        $date = strtotime($request->transaction_date ?? $stockUsage->transaction_date);
        $transactionDate = date('Y-m-d H:i:s',$date);

        DB::beginTransaction();
        try
        {
            $stockUsage->update([
                'transaction_date'  => $transactionDate,
                'reason'            => $request->reason ?? $stockUsage->reason,
                'updated_by'        => auth()->user()->id,
                'updated_at'        => date("Y-m-d H:i:s"),
                'status'            => 1
            ]);

            if ($request->items != null)
            {
                foreach ($request->items as $item)
                {
                    $stockUsageDet = DB::table('stock_usage')
                                ->join('items_details', 'stock_usage.item_detail_id', '=', 'items_details.id')
                                ->where('stock_usage.stock_usage_id', $id)
                                ->where('items_details.item_code', $item['code'])
                                ->where('items_details.status', 1)
                                ->where('stock_usage.status', 1)
                                ->select('stock_usage.item_detail_id as item_detail_id', 'stock_usage.id as id')
                                ->first();
    
                    if ($stockUsageDet == null)
                    {
                        DB::rollBack();
                        return response()->json([
                            "status" => false,
                            "message" => "Stock Usage item not found"
                        ], 404);
                    }
    
                    if (isset($item['location_id']))
                    {
                        $itemDet = ItemDetail::where('item_code', $item['code'])->where('location_id', $item['location_id'])->where('status', 1)->first();
                    }
                    else
                    {
                        $itemDet = ItemDetail::where('id', $stockUsageDet->item_detail_id)->where('status', 1)->first();
                    }
    
                    $stockUsageDet = StockUsage::where('id', $stockUsageDet->id)->where('status', 1)->first();
    
                    $stockUsageDet->update([
                        'item_detail_id'        => $itemDet->id,
                        'location_id'           => $item['location_id'],
                        'qty'                   => $item['qty'],
                        'created_by'            => auth()->user()->id,
                        'updated_by'            => auth()->user()->id,
                        'status'                => 1
                    ]);
                }
            }

            $description = "Update Stock Usage\n
            request : " . json_encode($request->all()) . "
            response : Stock Usage Updated". "\n
            on " . date("Y-m-d H:i:s") . "
            by " . auth()->user()->username;

            $this->history('stock_usage', 'update stock usage', $description);

            DB::commit();
            return response()->json([
                "status" => true,
                "message" => "Stock Usage Updated"
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

    public function void(Request $request)
    {
        $validator = Validator::make($request->all(), [
            "usage_id"    => "required"
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

        $usage = StockUsageHeader::where('id', $request->usage_id)->where('status', 2)->first();

        if ($usage == null)
        {
            return response()->json([
                "status" => false,
                "message" => "Stock Usage not found"
            ], 404);
        }

        DB::beginTransaction();
        try
        {
            $usage->update([
                'status'        => 4,
                'updated_by'    => auth()->user()->id,
                'updated_at'    => date("Y-m-d H:i:s")
            ]);

            $void = VoidTransaction::create([
                'ref_id'            => $usage->doc_number,
                'usage_id'          => $request->usage_id,
                'reason'            => $request->reason,
                'status'            => 1,
                'updated_by'        => auth()->user()->id,
                'updated_at'        => date("Y-m-d H:i:s")
            ]);

            $usageDet = StockUsage::where('stock_usage_id', $request->usage_id)->where('status', 1)->get();

            foreach ($usageDet as $item)
            {
                $itemDet = ItemDetail::where('id', $item->item_detail_id)->where('status', 1)->first();

                if ($itemDet == null)
                {
                    DB::rollBack();
                    return response()->json([
                        "status" => false,
                        "message" => "Item not found"
                    ], 404);
                }

                $itemDet->update([
                    'qty'           => $itemDet->qty - $item->qty,
                    'updated_by'    => auth()->user()->id,
                    'updated_at'    => date("Y-m-d H:i:s"),
                ]);

                $voidDetail = VoidDetails::create([
                    'void_id'       => $void->id,
                    'item_detail_id'=> $item->item_detail_id,
                    'qty'           => $item->qty,
                    'updated_by'    => auth()->user()->id,
                    'updated_at'    => date("Y-m-d H:i:s"),
                    'status'        => 1
                ]);
            }

            $description = "Void Stock Usage\n
            request : " . json_encode($request->all()) . "
            response : Stock Usage Voided". "\n
            on " . date("Y-m-d H:i:s") . "
            by " . auth()->user()->username;

            $this->history('stock_usage', 'void stock usage', $description);

            DB::commit();

            return response()->json([
                "status" => true,
                "message" => "Stock Usage Voided"
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
