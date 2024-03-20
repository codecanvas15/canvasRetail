<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Payment;
use App\Procurement;
use App\Sales;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    // payment
    public function payment(Request $request)
    {
        $request->validate([
            "amount"    => 'required',
            "pay_date"  => 'required'
        ]);

        if ($request->procurement_id != null && $request->sales_id != null)
        {
            return response()->json([
                "status" => false,
                "message" => "Procurement and Sales cannot be paid at the same time."
            ], 400);
        }
        else if ($request->procurement_id != null || $request->sales_id != null)
        {
            $date = strtotime($request->pay_date);
            $payDate = date('Y-m-d H:i:s',$date);
            $type = '';

            if ($request->procurement_id == null && $request->sales_id != null)
            {
                $type = 'IN';
            }
            else if ($request->procurement_id != null && $request->sales_id == null)
            {
                $type = 'OUT';
            }

            if ($type == 'IN')
            {
                $sales = Sales::where('id', $request->sales_id)->where('status', 1)->first();
                $payment = Payment::where('sales_id', $request->sales_id)->where('status', 1)->sum('amount');

                $outstanding = $sales->amount - ($request->amount + $payment);

                $paymentStatus = '';

                if ($outstanding > 0)
                {
                    $paymentStatus = 'Partially Paid';
                }
                else if ($outstanding > $sales->amount)
                {
                    return response()->json([
                        "status" => false,
                        "message" => "Total Payment amount is greater than sales amount."
                    ], 400);
                }
                else
                {
                    $paymentStatus = 'Paid';
                }

                DB::beginTransaction();
                try
                {
                    $sales->update([
                        'pay_status'     => $paymentStatus,
                        'updated_by'     => auth()->user()->id,
                        'updated_at'     => date("Y-m-d H:i:s")
                    ]);

                    Payment::create([
                        'sales_id'      => $request->sales_id,
                        'type'          => $type,
                        'amount'        => $request->amount,
                        'pay_date'      => $payDate,
                        'created_by'    => auth()->user()->id,
                        'updated_by'    => auth()->user()->id,
                        'status'        => 1,
                    ]);

                    $description = "Payment at " . date("Y-m-d H:i:s") . " by " . auth()->user()->username;

                    $this->history('payment', 'payment', $description);

                    DB::commit();

                    return response()->json([
                        "status" => true,
                        "message" => "Sales Payment Success"
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
            else if ($type == 'OUT')
            {
                $procurement = Procurement::where('id', $request->procurement_id)->where('status', 1)->first();
                $payment = Payment::where('procurement_id', $request->procurement_id)->where('status', 1)->sum('amount');

                $outstanding = $procurement->amount - ($request->amount + $payment);

                $paymentStatus = '';

                if ($outstanding > 0)
                {
                    $paymentStatus = 'Partially Paid';
                }
                else if ($outstanding > $procurement->amount)
                {
                    return response()->json([
                        "status" => false,
                        "message" => "Total Payment amount is greater than procurement amount."
                    ], 400);
                }
                else
                {
                    $paymentStatus = 'Paid';
                }

                DB::beginTransaction();
                try
                {
                    $procurement->update([
                        'pay_status'     => $paymentStatus,
                        'updated_by'     => auth()->user()->id,
                        'updated_at'     => date("Y-m-d H:i:s")
                    ]);

                    Payment::create([
                        'procurement_id'    => $request->procurement_id,
                        'type'              => $type,
                        'amount'            => $request->amount,
                        'pay_date'          => $payDate,
                        'created_by'        => auth()->user()->id,
                        'updated_by'        => auth()->user()->id,
                        'status'            => 1,
                    ]);

                    $description = "Payment at " . date("Y-m-d H:i:s") . " by " . auth()->user()->username;

                    $this->history('payment', 'payment', $description);

                    DB::commit();

                    return response()->json([
                        "status" => true,
                        "message" => "Procurement Payment Success"
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
        }
        else
        {
            return response()->json([
                "status" => false,
                "message" => "Procurement or Sales id is required."
            ], 400);
        }
    }

    public function getPayment()
    {
        $payment = Payment::where('status', 1)->get();

        return response()->json([
            "status" => true,
            "data" => $payment
        ]);
    }
}
