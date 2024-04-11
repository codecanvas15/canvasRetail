<?php

namespace App\Http\Controllers\Api;

use App\Contact;
use App\Http\Controllers\Controller;
use App\Item;
use App\ItemDetail;
use App\Location;
use App\Payment;
use App\Procurement;
use App\ProcurementDetail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf as PDF;
use DateTime;
// use Barryvdh\DomPDF\Facade as PDF;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;

class ProcurementController extends Controller
{
    //
    public function addProcurement(Request $request)
    {
        $request->validate([
            "contact_id"        => "required",
            "location_id"       => "required",
            "total_amount"      => "required",
            "items"             => "required",
            "procurement_date"  => "required",
            "pay_amount"        => "required"
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
            $date = strtotime($request->procurement_date);

            $procurementDate = date('Y-m-d H:i:s',$date);

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
                    "message" => "Total Payment amount is greater than procurement amount."
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

            // document number
            $date = new DateTime('now');
            $month = $date->format('my');

            Config::set('database.connections.'. config('database.default') .'.strict', false);
            DB::reconnect();

            $seq = DB::select(DB::raw("
                SELECT
                    count(doc_number) as seq
                FROM
                    procurements
                WHERE
                    DATE_FORMAT(created_at, '%m%y') <= STR_TO_DATE('".$month."', '%m%y')
                    AND doc_number IS NOT NULL
            "));

            $documentNumber = 'PR-'.$month.'-'.str_pad(($seq[0]->seq+1), 3, '0', STR_PAD_LEFT);

            // insert procurement
            $procurement = Procurement::create([
                'contact_id'        => $request->contact_id,
                'procurement_date'  => $procurementDate,
                'amount'            => $request->total_amount,
                'pay_status'        => $paymentStatus,
                'created_by'        => auth()->user()->id,
                'updated_by'        => auth()->user()->id,
                'status'            => 1,
                'doc_number'    => $documentNumber
            ]);

            foreach ($request->items as $item)
            {
                $itemDet = ItemDetail::where('item_code', $item['item_code'])->where('location_id', $request->location_id)->where('status', 1)->first();

                // insert to item detail
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
                        'qty'           => $item['qty'] + $itemDet->qty,
                        'updated_by'    => auth()->user()->id,
                        'updated_at'    => date("Y-m-d H:i:s"),
                        'status'        => 1
                    ]);
                }

                // insert to procurement detail
                ProcurementDetail::create([
                    'procurement_id'    => $procurement->id,
                    'item_detail_id'    => $itemDet['id'],
                    'qty'               => $item['qty'],
                    'price'             => $item['price'],
                    'total'             => $item['total'],
                    'tax_ids'           => $request->tax_ids,
                    'created_by'        => auth()->user()->id,
                    'updated_by'        => auth()->user()->id,
                    'status'            => 1,
                ]);
            }

            Payment::create([
                'procurement_id'=> $procurement->id,
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
                "message" => "Procurement Success"
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

    public function updateProcurement(Request $request, $id)
    {
        $request->validate([
            "delivery_status"    => "required"
        ]);

        if(Procurement::where('id', $id)->where('status', 1)->exists())
        {
            $procurement = Procurement::where('id', $id)->where('status', 1);

            $procurement->update([
                'delivery_status' => $request->delivery_status,
                'updated_by'      => auth()->user()->id,
                'updated_at'      => date("Y-m-d H:i:s")
            ]);

            return response()->json([
                "status" => true,
                "message" => "Procurement Updated"
            ]);
        }
        else
        {
            return response()->json([
                "status" => false,
                "message" => "Procurement not found"
            ], 404);
        }
    }

    public function getProcurement()
    {
        $procurements = Procurement::where('status', 1)->get();

        return response()->json([
            "status"    => true,
            "data"      => $procurements
        ]);
    }

    public function getProcurementById($id)
    {
        if (Procurement::where('id', $id)->where('status', 1)->exists())
        {
            $procurement = Procurement::where('id', $id)->where('status', 1)->first();

            $contact = Contact::where('id', $procurement->contact_id)->select('name')->first();

            $procurementDet = DB::table('procurement_details')
                            ->join('items_details', 'procurement_details.item_detail_id', '=', 'items_details.id')
                            ->join('items', 'items_details.item_code', '=', 'items.item_code')
                            ->join('locations', 'items_details.location_id', '=', 'locations.id')
                            ->where('procurement_details.procurement_id', $id)
                            ->select('items.item_code', 'procurement_details.qty', 'procurement_details.price', 'procurement_details.total', 'procurement_details.tax_ids', 'items.name as item_name', 'items.image as item_image', 'items.category', 'locations.name as location_name')
                            ->get();

            $paymentDet = Payment::where('procurement_id', $id)->where('status', 1)->get();

            $data = $procurement;
            $data['contact_name'] = $contact->name;
            $data['details'] = $procurementDet;
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
                "message" => "Procurement not found"
            ], 404);
        }
    }

    public function deleteProcurement($id)
    {
        if (Procurement::where('id', $id)->where('status', 1)->exists())
        {
            $procurement = Procurement::where('id', $id)->where('status', 1)->first();

            $procurement->update([
                'status'        => 0,
                'updated_by'    => auth()->user()->id,
                'updated_at'    => date("Y-m-d H:i:s")
            ]);

            $procurementDet = ProcurementDetail::where('procurement_id', $id)->where('status', 1);

            $procurementDet->update([
                'status'        => 0,
                'updated_by'    => auth()->user()->id,
                'updated_at'    => date("Y-m-d H:i:s")
            ]);

            foreach ($procurementDet as $item)
            {
                $itemDet = ItemDetail::where('id', $item->item_detail_id)->where('status', 1)->first();

                $itemDet->update([
                    'qty'           => $itemDet->qty - $item->qty,
                    'updated_by'    => auth()->user()->id,
                    'updated_at'    => date("Y-m-d H:i:s"),
                ]);
            }

            return response()->json([
                "status"    => true,
                "message" => "Procurement deleted"
            ]);
        }
        else
        {
            return response()->json([
                "status" => false,
                "message" => "Procurement not found"
            ], 404);
        }
    }

    public function createPO(Request $request)
    {

        $path = public_path() . '/pdf/' . time() . '.pdf';

        $pdf = PDF::loadView('pdf');

        $pdf->save($path);

        return response()->download($path);
    }
}
