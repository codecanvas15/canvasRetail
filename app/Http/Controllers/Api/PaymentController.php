<?php

namespace App\Http\Controllers\Api;

use App\Bank;
use App\Contact;
use App\Http\Controllers\Controller;
use App\Payment;
use App\Procurement;
use App\Sales;
use Barryvdh\DomPDF\Facade\Pdf as PDF;
use DateTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PaymentController extends Controller
{
    // payment
    public function payment(Request $request)
    {
        $validator = Validator::make($request->all(), [
            "amount"    => 'required',
            "pay_date"  => 'required'
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
                $sales = Sales::where('id', $request->sales_id)->where('status', 2)->first();

                if ($sales == null)
                {
                    return response()->json([
                        "status" => false,
                        "message" => "Sales not found or not approved yet."
                    ], 404);
                }

                $payment = Payment::where('sales_id', $request->sales_id)->where('status', 1)->sum('amount');

                $outstanding = $sales->amount - ($request->amount + $payment);

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
                $procurement = Procurement::where('id', $request->procurement_id)->where('status', 2)->first();

                if ($procurement == null)
                {
                    return response()->json([
                        "status" => false,
                        "message" => "Procurement not found or not approved yet."
                    ], 404);
                }

                $payment = Payment::where('procurement_id', $request->procurement_id)->where('status', 1)->sum('amount');

                $outstanding = $procurement->amount - ($request->amount + $payment);

                $paymentStatus = '';

                if ($outstanding > 0)
                {
                    $paymentStatus = 'Partially Paid';
                }
                else if ($outstanding < 0)
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

    public function getPayment(Request $request)
    {
        $sortBy = $request->input('sort_by', 'pay_date');
        $sortOrder = $request->input('sort_order', 'desc');

        if (!in_array($sortOrder, ['asc', 'desc'])) {
            $sortOrder = 'desc'; // Default to ascending if invalid
        }

        $query  = Payment::query();
        $payment = $query->where('status', 1)->orderBy($sortBy, $sortOrder)->paginate(10);
        $payment->appends([
            'sort_by' => $sortBy,
            'sort_order' => $sortOrder,
        ]);

        $payment->appends($request->all());

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

    public function payReceipt(Request $request)
    {
        $validator = Validator::make($request->all(), [
            "sales_id"        => "required",
            "bank_id"         => "required",
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

        $id = explode(',', $request->sales_id);

        $sales = Sales::whereIn('id', $id)->first();

        if($sales != null)
        {
            $bank = Bank::where('id', $request->bank_id)->first();

            if ($bank == null)
            {
                return response()->json([
                    "status" => false,
                    "message" => "Bank not found"
                ], 404);
            }

            $contact = Contact::where('id', $sales->contact_id)->first();
            $paymentDet = DB::table('sales')
                ->leftJoin('payment', 'sales.id', '=', 'payment.sales_id')
                ->select('sales.id', 'sales.doc_number', 'sales.sales_date', DB::raw('COALESCE(SUM(sales.amount), 0) as amount'), DB::raw('sales.amount - COALESCE(SUM(payment.amount), 0) as outstanding'), 'sales.status')
                ->whereIn('sales.id', $id)
                ->groupBy('sales.id', 'sales.doc_number', 'sales.sales_date', 'sales.amount', 'sales.status')
                ->get();

            $date = new DateTime('now');
            $date = $date->format('d/m/Y');

            $data = $sales;
            $data['contact_name'] = $contact->name;
            $data['contact_address'] = $contact->address;
            $data['payments'] = $paymentDet;
            $data['grand_total'] = $sales['amount'];
            $data['date'] = $date;
            $data['bank'] = $bank;

            // Save HTML content to a file
            $htmlContent = view('tandaTerima', ['data' => $data])->render();
            $htmlPath = public_path() . '/html/payment-' . $sales['doc_number'] . time() . '.html';
            file_put_contents($htmlPath, $htmlContent);

            $path = public_path() . '/pdf/payment-' . $sales['doc_number'] . time() . '.pdf';

            $pdf = PDF::loadView('tandaTerima', ['data' => $data])->setPaper('a5', 'landscape');;

            $pdf->save($path);

            $pdfPaths = url('/pdf/' . basename($path));

            return response()->json([
                "status"    => true,
                "data"      => $pdfPaths
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
