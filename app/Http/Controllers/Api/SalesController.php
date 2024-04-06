<?php

namespace App\Http\Controllers\Api;

use App\Contact;
use App\Http\Controllers\Controller;
use App\Item;
use App\ItemDetail;
use App\Location;
use App\Payment;
use App\Sales;
use App\SalesDetail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalesController extends Controller
{
    //
    public function addSales(Request $request)
    {
        $request->validate([
            "contact_id"        => "required",
            "location_id"       => "required",
            "total_amount"      => "required",
            "items"             => "required",
            "sales_date"        => "required"
        ]);

        if (!Contact::where('id', $request->contact_id)->where('status', 1)->exists())
        {
            return response()->json([
                "status" => false,
                "message" => "Contact not found"
            ], 404);
        }

        if (!Location::where('id', $request->location_id)->where('status', 1)->exists())
        {
            return response()->json([
                "status" => false,
                "message" => "Location not found"
            ], 404);
        }

        $error = 0;
        $errorMsg = [];

        foreach ($request->items as $item)
        {
            if (!Item::where('item_code', $item['item_code'])->where('status', 1)->exists())
            {
                $error++;
                array_push($errorMsg,'Item ' . $item['item_code'] . ' is not exist !');
            }
        }

        if ($error > 0)
        {
            return response()->json([
                "status" => false,
                "message" => $errorMsg
            ], 404);
        }

        DB::beginTransaction();
        try
        {
            $date = strtotime($request->sales_date);
            $salesDate = date('Y-m-d H:i:s',$date);

            $outstanding = $request->total_amount - $request->pay_amount;

            $paymentStatus = '';
            if ($outstanding > 0)
            {
                $paymentStatus = 'Partially Paid';
            }
            else if ($outstanding < 0)
            {
                return response()->json([
                    "status" => false,
                    "message" => "Total Payment amount is greater than sales amount."
                ], 400);
            }
            else if ($outstanding == 0)
            {
                $paymentStatus = 'Paid';
            }

            if ($request->pay_amount == 0)
            {
                $paymentStatus = 'Unpaid';
            }

            // insert sales
            $sales = Sales::create([
                'contact_id' => $request->contact_id,
                'sales_date' => $salesDate,
                'amount'     => $request->total_amount,
                'pay_status' => $paymentStatus,
                'created_by' => auth()->user()->id,
                'updated_by' => auth()->user()->id,
                'status'     => 1,
            ]);

            foreach ($request->items as $item)
            {
                $itemDet = ItemDetail::where('item_code', $item['item_code'])->where('location_id', $request->location_id)->where('status', 1)->first();

                // insert and update item details
                if ($itemDet == null)
                {
                    $itemDet = ItemDetail::create([
                        'item_code'     => $item['item_code'],
                        'location_id'   => $request->location_id,
                        'qty'           => $item['qty'] * -1,
                        'price'         => $item['price'],
                        'created_by'    => auth()->user()->id,
                        'updated_by'    => auth()->user()->id,
                        'status'        => 1
                    ]);
                }
                else
                {
                    $itemDet->update([
                        'qty'           => $itemDet->qty - $item['qty'],
                        'updated_by'    => auth()->user()->id,
                        'updated_at'    => date("Y-m-d H:i:s"),
                        'status'        => 1
                    ]);
                }

                // insert sales detail
                SalesDetail::create([
                    'sales_id' => $sales->id,
                    'item_detail_id' => $itemDet['id'],
                    'qty' => $item['qty'],
                    'price' => $item['price'],
                    'total' => $item['total'],
                    'tax_ids' => $request->tax_ids,
                    'created_by' => auth()->user()->id,
                    'updated_by' => auth()->user()->id,
                    'status' => 1,
                ]);
            }

            Payment::create([
                'sales_id'      => $sales->id,
                'type'          => "OUT",
                'amount'        => $request->pay_amount,
                'pay_date'      => date("Y-m-d H:i:s"),
                'created_by'    => auth()->user()->id,
                'updated_by'    => auth()->user()->id,
                'status'        => 1,
                'pay_desc'      => "Initial Payment"
            ]);

            DB::commit();

            return response()->json([
                "status" => true,
                "message" => "Sales Success"
            ]);
        }
        catch (\Throwable $th)
        {
            DB::rollBack();

            return response()->json([
                "status" => false,
                "message" => $th
            ], 500);
        }
    }

    public function updateSales(Request $request, $id)
    {
        $request->validate([
            "delivery_status"    => "required"
        ]);

        if(Sales::where('id', $id)->where('status', 1)->exists())
        {
            $sales = Sales::where('id', $id)->where('status', 1);

            $sales->update([
                'delivery_status' => $request->delivery_status,
                'updated_by'      => auth()->user()->id,
                'updated_at'      => date("Y-m-d H:i:s")
            ]);

            return response()->json([
                "status" => true,
                "message" => "Sales Updated"
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

    public function getSales()
    {
        $sales = Sales::where('status', 1)->get();

        return response()->json([
            "status"    => true,
            "data"      => $sales
        ]);
    }

    public function getSalesById($id)
    {
        if(Sales::where('id', $id)->where('status',1)->exists())
        {
            $sales = Sales::where('id', $id)->where('status', 1)->first();

            $salesDet = SalesDetail::where('sales_id', $id)->where('status', 1)->get();

            $paymentDet = Payment::where('sales_id', $id)->where('status', 1)->get();

            $data = $sales;
            $data['details'] = $salesDet;
            $data['payments'] = $paymentDet;

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

    public function deleteSales($id)
    {
        if (Sales::where('id', $id)->where('status', 1)->exists())
        {
            $sales = Sales::where('id', $id)->where('status', 1)->first();

            $sales->update([
                'status'        => 0,
                'updated_by'    => auth()->user()->id,
                'updated_at'    => date("Y-m-d H:i:s")
            ]);

            $salesDet = SalesDetail::where('sales_id', $id)->where('status', 1);

            $salesDet->update([
                'status'        => 0,
                'updated_by'    => auth()->user()->id,
                'updated_at'    => date("Y-m-d H:i:s")
            ]);

            foreach ($salesDet as $item)
            {
                $itemDet = ItemDetail::where('id', $item->item_detail_id)->where('status', 1)->first();

                $itemDet->update([
                    'qty'           => $itemDet->qty + $item->qty,
                    'updated_by'    => auth()->user()->id,
                    'updated_at'    => date("Y-m-d H:i:s"),
                ]);
            }

            return response()->json([
                "status"    => true,
                "message" => "Sales deleted"
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
}
