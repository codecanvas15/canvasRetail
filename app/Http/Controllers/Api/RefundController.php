<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Payment;
use App\Procurement;
use App\Sales;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class RefundController extends Controller
{
    // refund
    public function refund(Request $request)
    {
        $validator = Validator::make($request->all(), [
            "payment_id"    => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json([
                "status" => false,
                "message" => $validator->errors()
            ]);
        }

        if (Payment::where('id', $request->payment_id)->where('status', 1)->exists())
        {
            $payment = Payment::where('id', $request->payment_id)->where('status', 1)->first();

            DB::beginTransaction();
            try
            {
                $description = "Refund payment " . $request->payment_id . "at" . date("Y-m-d H:i:s") . " by " . auth()->user()->username;

                $this->history('payment', 'refund', $description);

                if ($payment->procurement_id != null && $payment->sales_id == null)
                {
                    Payment::create([
                        'procurement_id'=> $payment->procurement_id,
                        'type'          => "REFUND",
                        'amount'        => $payment->amount,
                        'pay_date'      => date("Y-m-d H:i:s"),
                        'created_by'    => auth()->user()->id,
                        'updated_by'    => auth()->user()->id,
                        'status'        => 1,
                        'pay_desc'      => $description
                    ]);

                    $procurement = Procurement::where('id', $payment->procurement_id)->where('status', 1)->first();
                    $paymentTotal = Payment::where('procurement_id', $payment->procurement_id)->where('status', 1)->where('type', 'not like', 'REFUND')->sum('amount');

                    $paymentStatus = '';

                    if($paymentTotal >= $procurement->amount)
                    {
                        $paymentStatus = 'Paid';
                    }
                    else if ($paymentTotal == 0)
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
                    Payment::create([
                        'sales_id'      => $payment->sales_id,
                        'type'          => "REFUND",
                        'amount'        => $payment->amount,
                        'pay_date'      => date("Y-m-d H:i:s"),
                        'created_by'    => auth()->user()->id,
                        'updated_by'    => auth()->user()->id,
                        'status'        => 1,
                        'pay_desc'      => $description
                    ]);

                    $sales = Sales::where('id', $payment->sales_id)->where('status', 1)->first();
                    $paymentTotal = Payment::where('sales_id', $payment->sales_id)->where('status', 1)->where('type', 'not like', 'REFUND')->sum('amount');

                    $paymentStatus = '';

                    if($paymentTotal >= $sales->amount)
                    {
                        $paymentStatus = 'Paid';
                    }
                    else if ($paymentTotal == 0)
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

                $payment->update([
                    'status'         => 0,
                    'updated_by'     => auth()->user()->id,
                    'updated_at'     => date("Y-m-d H:i:s")
                ]);

                DB::commit();

                return response()->json([
                    "status" => true,
                    "message" => "Refund Success"
                ]);
            }
            catch (\Throwable $th)
            {
                dd($th);
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

    public function getRefund(Request $request)
    {
        $sortBy = $request->input('sort_by', 'pay_date');
        $sortOrder = $request->input('sort_order', 'desc');

        if (!in_array($sortOrder, ['asc', 'desc'])) {
            $sortOrder = 'desc'; // Default to ascending if invalid
        }

        $query  = Payment::query();
        $payment = $query->where('status', 1)->where('type', 'REFUND')->orderBy($sortBy, $sortOrder)->paginate(10);
        $payment->appends([
            'sort_by' => $sortBy,
            'sort_order' => $sortOrder,
        ]);

        return response()->json([
            'status' => true,
            'data' => $payment->items(),
            'pagination' => [
                'current_page' => $payment->currentPage(),
                'total_pages' => $payment->lastPage(),
                'next_page' => $payment->nextPageUrl(),
                'prev_page' => $payment->previousPageUrl(),
            ],
        ]);
    }
}
