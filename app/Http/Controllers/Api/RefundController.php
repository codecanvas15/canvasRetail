<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Payment;
use App\Procurement;
use App\Sales;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RefundController extends Controller
{
    // refund
    public function refund(Request $request)
    {
        $request->validate([
            "payment_id"    => 'required'
        ]);

        if (Payment::where('id', $request->payment_id)->where('status', 1)->exists())
        {
            $payment = Payment::where('id', $request->payment_id)->where('status', 1)->first();

            DB::beginTransaction();
            try
            {
                $payment->update([
                    'status'        => 0,
                    'updated_by'    => auth()->user()->id,
                    'updated_at'    => date("Y-m-d H:i:s")
                ]);

                $description = "Refund payment " . $request->payment_id . " at " . date("Y-m-d H:i:s") . " by " . auth()->user()->username;

                $this->history('payment', 'refund', $description);

                if ($payment->procurement_id != null && $payment->sales_id == null)
                {
                    $procurement = Procurement::where('id', $payment->procurement_id)->where('status', 1)->first();
                    $payment = Payment::where('procurement_id', $payment->procurement_id)->where('status', 1)->sum('amount');

                    $paymentStatus = '';

                    if($payment >= $procurement->amount)
                    {
                        $paymentStatus = 'Paid';
                    }
                    else if ($payment == 0)
                    {
                        $paymentStatus = 'Unpaid';
                    }
                    else
                    {
                        $paymentStatus = 'Partialy Paid';
                    }

                    $procurement->update([
                        'pay_status'     => $paymentStatus,
                        'updated_by'     => auth()->user()->id,
                        'updated_at'     => date("Y-m-d H:i:s")
                    ]);
                }
                else if ($payment->sales_id != null && $payment->procurement_id == null)
                {
                    $sales = Sales::where('id', $payment->sales_id)->where('status', 1)->first();
                    $payment = Payment::where('sales_id', $payment->sales_id)->where('status', 1)->sum('amount');

                    $paymentStatus = '';

                    if($payment >= $sales->amount)
                    {
                        $paymentStatus = 'Paid';
                    }
                    else if ($payment == 0)
                    {
                        $paymentStatus = 'Unpaid';
                    }
                    else
                    {
                        $paymentStatus = 'Partialy Paid';
                    }

                    $sales->update([
                        'pay_status'     => $paymentStatus,
                        'updated_by'     => auth()->user()->id,
                        'updated_at'     => date("Y-m-d H:i:s")
                    ]);
                }

                DB::commit();

                return response()->json([
                    "status" => true,
                    "message" => "Refund Success"
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
        else
        {
            return response()->json([
                "status" => false,
                "message" => "Payment not found or already refunded."
            ], 404);
        }
    }

    public function getRefund()
    {
        $refund = Payment::where('status', 0)->get();

        return response()->json([
            "status" => true,
            "data" => $refund
        ]);
    }
}
