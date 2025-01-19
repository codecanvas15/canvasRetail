<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Item;
use App\ItemDetail;
use App\Location;
use App\StockAdjustment;
use App\StockOpnameDetail;
use App\StockUsage;
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
            'items.*.id.required' => 'Each item must have an ID.',
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
            return response()->json([
                "status" => false,
                "message" => $validator->errors()
            ]);
        }

        $error = 0;
        $errorMsg = [];

        $date = strtotime($request->transaction_date);

        $transactionDate = date('Y-m-d H:i:s',$date);

        DB::beginTransaction();
        try
        {
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
                            'item_code'             => $item['code'],
                            'location_id'           => $item['location_id'],
                            'qty'                   => $item['qty'],
                            'transaction_date'      => $transactionDate,
                            'created_by'            => auth()->user()->id,
                            'updated_by'            => auth()->user()->id,
                            'status'                => 1,
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

            return response()->json([
                "status" => true,
                "message" => "Stock Opname Success"
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
