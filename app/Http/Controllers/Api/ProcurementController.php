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
use App\Tax;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf as PDF;
use DateTime;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Validator;

class ProcurementController extends Controller
{
    //
    public function addProcurement(Request $request)
    {
        $validator = Validator::make($request->all(), [
            "contact_id"        => "required",
            "location_id"       => "required",
            "items"             => "required",
            "procurement_date"  => "required",
            "pay_amount"        => "required"
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

            // document number
            $date = new DateTime('now');
            $date = $date->format('dmY');
            Config::set('database.connections.'. config('database.default') .'.strict', false);
            DB::reconnect();

            $seq = DB::select("
                SELECT
                    count(doc_number) as seq
                FROM
                    procurements
                WHERE
                    DATE_FORMAT(created_at, '%d%m%Y') <= STR_TO_DATE(?, '%d%m%Y')
                    AND doc_number IS NOT NULL
            ", [$date]);
            
            $documentNumber = 'PO-'.$date.'-'.str_pad(($seq[0]->seq+1), 4, '0', STR_PAD_LEFT);
            
            $taxes = explode(',', $request->tax_ids);

            $tax = Tax::whereIn('id', $taxes)->sum('value');

            $totalAmount = 0;
            // insert procurement
            $procurement = Procurement::create([
                'contact_id'            => $request->contact_id,
                'procurement_date'      => $procurementDate,
                'amount'                => 0,
                'pay_status'            => null,
                'created_by'            => auth()->user()->id,
                'updated_by'            => auth()->user()->id,
                'status'                => 1,
                'doc_number'            => $documentNumber,
                'include_tax'           => $request->include_tax == 1 ? true : false,
                'rounding'              => (float)($request->round),
                'external_doc_no'       => $request->external_doc_no,
            ]);

            foreach ($request->items as $item)
            {
                $itemDet = ItemDetail::where('item_code', $item['item_code'])->where('location_id', $request->location_id)->where('status', 1)->first();

                $discounts = $item['discounts'] ?? [];

                if ($request->include_tax)
                {
                    $priceAfterDiscount = $item['price'];
                    foreach ($discounts as $discount)
                    {
                        $discount = $discount ?? 0 ? ($discount/100) * $priceAfterDiscount : 0;
                        
                        $priceAfterDiscount = $priceAfterDiscount - $discount;
                    }

                    $itemPrice = $priceAfterDiscount / (1 + $tax/100);
                    
                    $total = $item['qty'] * $itemPrice;
                }
                else
                {
                    $priceAfterDiscount = $item['price'];
                    foreach ($discounts as $discount)
                    {
                        $discount = $discount ?? 0 ? ($discount/100) * $priceAfterDiscount : 0;
                        
                        $priceAfterDiscount = $priceAfterDiscount - $discount;
                    }

                    $itemPrice = $priceAfterDiscount;

                    $total = $item['qty'] * $itemPrice;
                }

                // insert to item detail
                if ($itemDet == null)
                {
                    $itemDet = ItemDetail::create([
                        'item_code'     => $item['item_code'],
                        'location_id'   => $request->location_id,
                        'qty'           => $item['qty'],
                        'price'         => $itemPrice,
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

                if ($request->include_tax)
                {
                    $total = $item['qty'] * $itemPrice;
                }
                else
                {
                    $total = $item['qty'] * ($item['price']);
                }

                // insert to procurement detail
                ProcurementDetail::create([
                    'procurement_id'    => $procurement->id,
                    'item_detail_id'    => $itemDet['id'],
                    'qty'               => $item['qty'],
                    'price'             => $itemPrice,
                    'total'             => round($total, 2),
                    'tax_ids'           => $request->tax_ids,
                    'created_by'        => auth()->user()->id,
                    'updated_by'        => auth()->user()->id,
                    'status'            => 1,
                    'discount'          => implode('|', $discounts)
                ]);

                $totalAmount += ($total + $total * ($tax/100));
            }

            // $totalAmount += $request->round;

            if ($request->rounding === 'down') 
            {
                $roundedAmount = floor($totalAmount);
            } 
            elseif ($request->rounding === 'up') 
            {
                $roundedAmount = ceil($totalAmount);
            } 
            else 
            {
                $roundedAmount = round($totalAmount,2);
            }

            $outstanding = $roundedAmount - $request->pay_amount;

            $paymentStatus = '';
            if ($outstanding > 0)
            {
                $paymentStatus = 'Partially Paid';
            }
            else if ($outstanding < 0)
            {
                DB::rollBack();
                
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

            $procurement->update([
                'amount'        => $roundedAmount,
                'pay_status'    => $paymentStatus,
                'updated_by'    => auth()->user()->id,
                'updated_at'    => date("Y-m-d H:i:s")
            ]);

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
        $validator = Validator::make($request->all(), [
            "delivery_status"    => "required"
        ]);

        if ($validator->fails()) {
            return response()->json([
                "status" => false,
                "message" => $validator->errors()
            ]);
        }

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

    public function getProcurement(Request $request)
    {
        $sortBy = $request->input('sort_by', 'procurement_date');
        $sortOrder = $request->input('sort_order', 'desc');
        $search = $request->input('search', null);
        $searchVendor = $request->input('search_vendor', null);
        $searchProcurementDate = $request->input('search_procurement_date', null);
        $searchDocNumber = $request->input('search_doc_number', null);

        if (!in_array($sortOrder, ['asc', 'desc'])) {
            $sortOrder = 'desc'; // Default to ascending if invalid
        }

        $query  = Procurement::query();

        $query->join('contacts', 'procurements.contact_id', '=', 'contacts.id')
                ->join('procurement_details', 'procurements.id', '=', 'procurement_details.procurement_id')
                ->select('procurements.*', 'contacts.name as contact_name');

        if ($search != null)
        {
            $query->where('contacts.name', 'like', '%' . $search . '%');
            $query->orWhere('procurements.procurement_date', 'like', '%' . $search . '%');
            $query->orWhere('procurements.doc_number', 'like', '%' . $search . '%');
            $query->orWhere('procurements.external_doc_no', 'like', '%' . $search . '%');
        }
        else
        {
            if ($searchVendor != null)
            {
                $query->where('contacts.name', 'like', '%' . $searchVendor . '%');
            }

            if ($searchProcurementDate != null)
            {
                $query->where('procurements.procurement_date', 'like', '%' . $searchProcurementDate . '%');
            }

            if ($searchDocNumber != null)
            {
                $query->where('procurements.doc_number', 'like', '%' . $searchDocNumber . '%');
                $query->orWhere('procurements.external_doc_no', 'like', '%' . $searchDocNumber . '%');
            }
        }

        $procurement = $query->orderBy($sortBy, $sortOrder)->orderBy('id', 'desc')->paginate(10);
        $procurement->appends([
            'sort_by' => $sortBy,
            'sort_order' => $sortOrder,
        ]);

        return response()->json([
            'status' => true,
            'data' => $procurement->items(),
            'pagination' => [
                'current_page' => $procurement->currentPage(),
                'total_pages' => $procurement->lastPage(),
                'next_page' => $procurement->nextPageUrl(),
                'prev_page' => $procurement->previousPageUrl(),
                'total_data' => $procurement->total(),
            ],
        ]);
    }

    public function getItemProcurement(Request $request)
    {
        $validator = Validator::make($request->all(), [
            "search_item_code"        => "required"
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

        $sortBy = $request->input('sort_by', 'procurement_date');
        $sortOrder = $request->input('sort_order', 'desc');
        $search = $request->input('search', null);
        $searchVendor = $request->input('search_vendor', null);
        $searchProcurementDate = $request->input('search_procurement_date', null);
        $searchDocNumber = $request->input('search_doc_number', null);
        $searchItemCode = $request->input('search_item_code', null);

        if (!in_array($sortOrder, ['asc', 'desc'])) {
            $sortOrder = 'desc'; // Default to ascending if invalid
        }

        $query  = Procurement::query();

        $query->join('contacts', 'procurements.contact_id', '=', 'contacts.id')
                ->join('procurement_details', 'procurements.id', '=', 'procurement_details.procurement_id')
                ->join('items_details', 'procurement_details.item_detail_id', '=', 'items_details.id')
                ->join('locations', 'items_details.location_id', '=', 'locations.id')
                ->select('procurements.procurement_date', 'contacts.name as contact_name', 'items_details.item_code', 'locations.name as location_name', 'procurements.doc_number', 'procurement_details.qty as procurement_qty', 'procurement_details.price', 'procurement_details.total');

        if ($search != null)
        {
            $query->where('contacts.name', 'like', '%' . $search . '%');
            $query->orWhere('procurements.procurement_date', 'like', '%' . $search . '%');
            $query->orWhere('procurements.doc_number', 'like', '%' . $search . '%');
            $query->orWhere('items_details.item_code', 'like', '%' . $search . '%');
        }
        else
        {
            if ($searchVendor != null)
            {
                $query->where('contacts.name', 'like', '%' . $searchVendor . '%');
            }

            if ($searchProcurementDate != null)
            {
                $query->where('procurements.procurement_date', 'like', '%' . $searchProcurementDate . '%');
            }

            if ($searchDocNumber != null)
            {
                $query->where('procurements.doc_number', 'like', '%' . $searchDocNumber . '%');
            }

            if ($searchItemCode != null)
            {
                $query->where('items_details.item_code', 'like', '%' . $searchItemCode . '%');
            }
        }

        $procurement = $query->orderBy($sortBy, $sortOrder)->orderBy('procurement_date', 'desc')->paginate(10);
        $procurement->appends([
            'sort_by' => $sortBy,
            'sort_order' => $sortOrder,
        ]);

        return response()->json([
            'status' => true,
            'data' => $procurement->items(),
            'pagination' => [
                'current_page' => $procurement->currentPage(),
                'total_pages' => $procurement->lastPage(),
                'next_page' => $procurement->nextPageUrl(),
                'prev_page' => $procurement->previousPageUrl(),
                'total_data' => $procurement->total(),
            ],
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
                            ->select('items.item_code', 'procurement_details.qty', 'procurement_details.price', 'procurement_details.total', 'procurement_details.tax_ids', 'procurement_details.discount', 'items.name as item_name', 'items.image as item_image', 'items.category', 'locations.name as location_name', 'locations.id as location_id')
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

    public function createDocs(Request $request)
    {
        $path = public_path() . '/pdf/' . time() . '.pdf';

        $pdf = PDF::loadView('pdf');

        $pdf->save($path);

        return response()->download($path);
    }
}
