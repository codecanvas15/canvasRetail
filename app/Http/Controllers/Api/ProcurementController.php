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
use App\VoidDetails;
use App\VoidTransaction;
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
            "procurement_date"  => "required"
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

            $documentNumber = '';
            $countDocNo = 1;

            while ($countDocNo > 0)
            {
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

                $countDocNo = DB::select("
                    SELECT
                        count(doc_number) as seq
                    FROM
                        procurements
                    WHERE
                        doc_number = ?
                ", [$documentNumber])[0]->seq;
            }
            
            $taxes = explode(',', $request->tax_ids);

            $tax = Tax::whereIn('id', $taxes)->sum('value');
            $taxes = Tax::whereIn('id', $taxes)->get();

            $totalTax = [];
            foreach ($taxes as $key)
            {
                $totalTax[] = $key->value;
            }

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
                'location_id'           => $request->location_id
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
                        'qty'           => 0,
                        'price'         => 0,
                        'created_by'    => auth()->user()->id,
                        'updated_by'    => auth()->user()->id,
                        'status'        => 1
                    ]);
                }
                // else
                // {
                //     $itemDet->update([
                //         'qty'           => $item['qty'] + $itemDet->qty,
                //         'updated_by'    => auth()->user()->id,
                //         'updated_at'    => date("Y-m-d H:i:s"),
                //         'status'        => 1
                //     ]);
                // }

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
                    'discount'          => implode('|', $discounts),
                    'initial_price'     => $item['price']
                ]);
                
                $totalAmount += ($total + $total * ($tax/100));
            }

            $totalAmount += $request->round;

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

            // $outstanding = $roundedAmount - $request->pay_amount;

            // $paymentStatus = '';
            // if ($outstanding > 0)
            // {
            //     $paymentStatus = 'Partially Paid';
            // }
            // else if ($outstanding < 0)
            // {
            //     DB::rollBack();
                
            //     return response()->json([
            //         "status" => false,
            //         "message" => "Total Payment amount is greater than procurement amount."
            //     ], 400);
            // }
            // else if ($outstanding == 0)
            // {
            //     $paymentStatus = 'Paid';
            // }

            // if ($request->pay_amount == 0)
            // {
                $paymentStatus = 'Unpaid';
            // }

            $procurement->update([
                'amount'        => $roundedAmount,
                'pay_status'    => $paymentStatus,
                'updated_by'    => auth()->user()->id,
                'updated_at'    => date("Y-m-d H:i:s"),
                'tax'           => implode('|', $totalTax)
            ]);

            // Payment::create([
            //     'procurement_id'=> $procurement->id,
            //     'type'          => "OUT",
            //     'amount'        => $request->pay_amount,
            //     'pay_date'      => date("Y-m-d H:i:s"),
            //     'created_by'    => auth()->user()->id,
            //     'updated_by'    => auth()->user()->id,
            //     'status'        => 1,
            //     'pay_desc'      => "Initial Payment"
            // ]);

            $description = "Create Procurement\n
            request : " . json_encode($request->all()) . "
            response : Procurement Success". "\n
            on " . date("Y-m-d H:i:s") . "
            by " . auth()->user()->username;

            $this->history('procurement', 'create procurement', $description);

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

    public function approveProcurement(Request $request)
    {
        $validator = Validator::make($request->all(), [
            "procurement_id"    => "required",
            "is_approve"        => "required|in:1,0",
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
        
        $procurement = Procurement::where('id', $request->procurement_id)->where('status', 1)->first();
        
        if ($procurement == null)
        {
            return response()->json([
                "status" => false,
                "message" => "Procurement not found or aleady approved"
            ], 404);
        }

        DB::beginTransaction();
        try
        {
            if (!$request->is_approve)
            {
                $procurement->update([
                    'status'        => 3,
                    'updated_by'    => auth()->user()->id,
                    'updated_at'    => date("Y-m-d H:i:s")
                ]);

                $description = "Approve Procurement\n
                request : " . json_encode($request->all()) . "
                response : Procurement Rejected". "\n
                on " . date("Y-m-d H:i:s") . "
                by " . auth()->user()->username;

                $this->history('procurement', 'approve procurement', $description);
    
                DB::commit();
    
                return response()->json([
                    "status" => true,
                    "message" => "Procurement Rejected"
                ]);
            }

            $procurement->update([
                'status'        => 2,
                'updated_by'    => auth()->user()->id,
                'updated_at'    => date("Y-m-d H:i:s")
            ]);
            
            $procurementDet = ProcurementDetail::where('procurement_id', $request->procurement_id)->where('status', 1)->get();
    
            foreach ($procurementDet as $item)
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
                        'qty'           => $itemDet->qty + $item->qty,
                        'updated_by'    => auth()->user()->id,
                        'updated_at'    => date("Y-m-d H:i:s"),
                    ]);
                }
            }

            $description = "Approve Procurement\n
            request : " . json_encode($request->all()) . "
            response : Procurement Approved". "\n
            on " . date("Y-m-d H:i:s") . "
            by " . auth()->user()->username;
            $this->history('procurement', 'approve procurement', $description);
    
            DB::commit();
    
            return response()->json([
                "status" => true,
                "message" => "Procurement Approved"
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
            'itemDetails'                 => 'array',
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
        
        if(Procurement::where('id', $id)->whereIn('status', [1])->exists())
        {
            $procurement = Procurement::where('id', $id)->whereIn('status', [1,3])->first();
            $date = strtotime($request->procurement_date ?? $procurement->procurement_date);

            $procurementDate = date('Y-m-d H:i:s',$date);

            if($request->tax_ids == null && $procurement->tax != null)
            {
                $taxesVal = explode('|', $procurement->tax);

                $taxes = Tax::whereIn('value', $taxesVal)->pluck('id')->toArray();
            }
            else
            {
                $taxes = explode(',', $request->tax_ids);
            }

            $tax = Tax::whereIn('id', $taxes)->sum('value');
            $taxes = Tax::whereIn('id', $taxes)->get();
            
            $totalTax = [];
            foreach ($taxes as $key)
            {
                $totalTax[] = $key->value;
            }

            $totalAmount = 0;
            
            DB::beginTransaction();
            try
            {
                $procurement->update([
                    'contact_id'            => $request->contact_id ?? $procurement->contact_id,
                    'procurement_date'      => $procurementDate,
                    'status'                => 1,
                    'include_tax'           => $request->include_tax ?? $procurement->include_tax,
                    'external_doc_no'       => $request->external_doc_no ?? $procurement->external_doc_no,
                    'delivery_status'       => $request->delivery_status ?? $procurement->delivery_status,
                    'updated_by'            => auth()->user()->id,
                    'updated_at'            => date("Y-m-d H:i:s"),
                    'location_id'           => $request->location_id ?? $procurement->location_id,
                ]);
    
                if ($request->itemDetails != null)
                {
                    foreach ($request->itemDetails as $item)
                    {
                        $procurementDet = DB::table('procurement_details')
                                        ->join('items_details', 'procurement_details.item_detail_id', '=', 'items_details.id')
                                        ->where('items_details.item_code', $item['item_code'])
                                        ->where('procurement_details.status', 1)
                                        ->where('items_details.status', 1)
                                        ->where('procurement_details.procurement_id', $procurement->id)
                                        ->select('procurement_details.id as id', 'procurement_details.item_detail_id as item_detail_id')
                                        ->first();
        
                        if ($procurementDet == null)
                        {
                            DB::rollBack();
                            return response()->json([
                                "status" => false,
                                "message" => "Procurement item not found"
                            ], 404);
                        }
        
                        if ($request->location_id != null)
                        {
                            $itemDet = ItemDetail::where('item_code', $item['item_code'])->where('location_id', $request->location_id)->where('status', 1)->first();
                        }
                        else
                        {
                            $itemDet = ItemDetail::where('id', $procurementDet->item_detail_id)->where('status', 1)->first();
                        }
    
                        $procurementDet = ProcurementDetail::where('id', $procurementDet->id)->where('status', 1)->first();
        
                        $discounts = $item['discounts'] ?? explode('|', $procurementDet->discount);
        
                        if ($request->include_tax)
                        {
                            $priceAfterDiscount = $item['price'] ?? $procurementDet->initial_price;
                            foreach ($discounts as $discount)
                            {
                                $discount = $discount ?? 0 ? ($discount/100) * $priceAfterDiscount : 0;
                                
                                $priceAfterDiscount = $priceAfterDiscount - $discount;
                            }
        
                            $itemPrice = $priceAfterDiscount / (1 + $tax/100);
                            
                            $total = ($item['qty'] ?? $procurementDet->qty) * $itemPrice;
                        }
                        else
                        {
                            $priceAfterDiscount = $item['price'] ?? $procurementDet->initial_price;
                            foreach ($discounts as $discount)
                            {
                                $discount = $discount ?? 0 ? ($discount/100) * $priceAfterDiscount : 0;
                                
                                $priceAfterDiscount = $priceAfterDiscount - $discount;
                            }
        
                            $itemPrice = $priceAfterDiscount;
        
                            $total = ($item['qty'] ?? $procurementDet->qty) * $itemPrice;
                        }
        
                        $procurementDet->update([
                            'status'        => 0,  
                            'updated_by'    => auth()->user()->id,
                            'updated_at'    => date("Y-m-d H:i:s")
                        ]);

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
                            'discount'          => implode('|', $discounts),
                            'initial_price'     => $item['price']
                        ]);
                        
                        $totalAmount += ($total + $total * ($tax/100));
                    }

                    if ($request->round != null)
                    {
                        $totalAmount += $procurement->rounding;
                    }
                }
                else
                {
                    if ($request->location_id != $procurement->location_id)
                    {
                        $procurementDet = ProcurementDetail::where('procurement_id', $procurement->id)->where('status', 1)->get();
        
                        foreach ($procurementDet as $item)
                        {
                            $itemDet = ItemDetail::where('id', $item->item_detail_id)->where('status', 1)->select('item_code')->first();
                            
                            if ($itemDet == null)
                            {
                                DB::rollBack();
                                return response()->json([
                                    "status" => false,
                                    "message" => "Item not found"
                                ], 404);
                            }
        
                            $itemDet = ItemDetail::where('item_code', $itemDet->item_code)->where('location_id', $request->location_id)->where('status', 1)->first();

                            if ($itemDet == null)
                            {
                                $itemDet = ItemDetail::create([
                                    'item_code'     => $itemDet->item_code,
                                    'location_id'   => $request->location_id,
                                    'qty'           => 0,
                                    'price'         => 0,
                                    'created_by'    => auth()->user()->id,
                                    'updated_by'    => auth()->user()->id,
                                    'status'        => 1
                                ]);
                            }
                            
                            $item->update([
                                'item_detail_id'    => $itemDet->id,
                                'updated_by'        => auth()->user()->id,
                                'updated_at'        => date("Y-m-d H:i:s")
                            ]);
                        }

                        $procurementDet->update([
                            'location_id'   => $request->location_id,
                            'updated_by'    => auth()->user()->id,
                            'updated_at'    => date("Y-m-d H:i:s")
                        ]);
                    }

                    $totalAmount = $procurement->amount; 

                    if ($request->round != null)
                    {
                        $totalAmount -= $procurement->rounding;
                        $totalAmount += $request->round;
                    }
                }

    
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

                $paymentStatus = 'Unpaid';
                
                $procurement->update([
                    'rounding'      => (float)($request->round ?? $procurement->rounding),
                    'amount'        => $roundedAmount,
                    'pay_status'    => $paymentStatus,
                    'updated_by'    => auth()->user()->id,
                    'updated_at'    => date("Y-m-d H:i:s"),
                    'tax'           => implode('|', $totalTax)
                ]);

                $description = "Update Procurement\n
                request : " . json_encode($request->all()) . "
                response : Procurement Updated". "\n
                on " . date("Y-m-d H:i:s") . "
                by " . auth()->user()->username;

                $this->history('procurement', 'update procurement', $description);
    
                DB::commit();
                return response()->json([
                    "status" => true,
                    "message" => "Procurement Updated"
                ]);
            }
            catch (\Throwable $e)
            {
                dd($e);
                DB::rollBack();
    
                return response()->json([
                    "status" => false,
                    "message" => $e
                ], 500);
            }
        }
        else
        {
            return response()->json([
                "status" => false,
                "message" => "Procurement not found"
            ], 404);
        }
    }

    public function void(Request $request)
    {
        $validator = Validator::make($request->all(), [
            "procurement_id"    => "required"
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

        $procurement = Procurement::where('id', $request->procurement_id)->where('status', 2)->first();

        if ($procurement == null)
        {
            return response()->json([
                "status" => false,
                "message" => "Procurement not found"
            ], 404);
        }

        DB::beginTransaction();
        try
        {
            $procurement->update([
                'status'        => 4,
                'updated_by'    => auth()->user()->id,
                'updated_at'    => date("Y-m-d H:i:s")
            ]);

            $void = VoidTransaction::create([
                'ref_id'            => $procurement->doc_number,
                'procurement_id'    => $request->procurement_id,
                'reason'            => $request->reason,
                'status'            => 1,
                'updated_by'        => auth()->user()->id,
                'updated_at'        => date("Y-m-d H:i:s")
            ]);

            $procurementDet = ProcurementDetail::where('procurement_id', $request->procurement_id)->where('status', 1)->get();

            foreach ($procurementDet as $item)
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

            $description = "Void Procurement\n
            request : " . json_encode($request->all()) . "
            response : Procurement Voided". "\n
            on " . date("Y-m-d H:i:s") . "
            by " . auth()->user()->username;

            $this->history('procurement', 'void procurement', $description);

            DB::commit();

            return response()->json([
                "status" => true,
                "message" => "Procurement Voided"
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

    public function getProcurement(Request $request)
    {
        $sortBy = $request->input('sort_by', 'procurement_date');
        $sortOrder = $request->input('sort_order', 'desc');
        $search = $request->input('search', null);
        $searchVendor = $request->input('search_vendor', null);
        $searchDocNumber = $request->input('search_doc_number', null);
        $searchStatus = $request->input('search_status', null);
        $searchPaymentStatus = $request->input('search_payment_status', null);

        $startProcurementDate = $request->input('search_start_procurement_date', null);
        $endProcurementDate = $request->input('search_end_procurement_date', null);

        if ($startProcurementDate > $endProcurementDate) {
            return response()->json([
                "status" => false,
                "message" => "The start date cannot be after the end date."
            ], 400);
        }


        if (!in_array($sortOrder, ['asc', 'desc'])) {
            $sortOrder = 'desc'; // Default to ascending if invalid
        }

        $query  = Procurement::query();

        $query->join('contacts', 'procurements.contact_id', '=', 'contacts.id')
                ->select('procurements.*', 'contacts.name as contact_name');

        if ($search != null)
        {
            $query->where('contacts.name', 'like', '%' . $search . '%');
            $query->orWhere('procurements.doc_number', 'like', '%' . $search . '%');
            $query->orWhere('procurements.external_doc_no', 'like', '%' . $search . '%');
            $query->orWhere('procurements.pay_status', 'like', '%' . $search . '%');
        }
        else
        {
            if ($searchVendor != null)
            {
                $query->where('contacts.id', $searchVendor);
            }

            if ($searchDocNumber != null)
            {
                $query->where('procurements.doc_number', 'like', '%' . $searchDocNumber . '%');
                $query->orWhere('procurements.external_doc_no', 'like', '%' . $searchDocNumber . '%');
            }

            if ($startProcurementDate != null && $endProcurementDate != null)
            {
                $query->whereBetween('procurements.procurement_date', [$startProcurementDate, $endProcurementDate]);
            }

            if ($searchVendor == null && $searchDocNumber == null && $startProcurementDate == null && $endProcurementDate == null)
            {
                $startProcurementDate = (new DateTime($startProcurementDate))->modify('first day of this month')->format('Y-m-d');
                $date = new DateTime('now');
                $endProcurementDate = $date->format('Y-m-d');
                $query->whereBetween('procurements.procurement_date', [$startProcurementDate, $endProcurementDate]);
            }

            if ($searchStatus != null)
            {
                $query->where('procurements.status', $searchStatus);
            }

            if ($searchPaymentStatus != null)
            {
                $query->where('procurements.pay_status', $searchPaymentStatus);
            }
        }

        $procurement = $query->orderBy($sortBy, $sortOrder)->orderBy('id', 'desc')->paginate(10);
        $procurement->appends([
            'sort_by' => $sortBy,
            'sort_order' => $sortOrder,
        ]);

        $procurement->appends($request->all());

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
                ->join('items', 'items_details.item_code', '=', 'items.item_code')
                ->join('locations', 'items_details.location_id', '=', 'locations.id')
                ->select('procurements.procurement_date', 'contacts.name as contact_name', 'items_details.item_code', 'items.unit', 'locations.name as location_name', 'procurements.doc_number', 'procurement_details.qty as procurement_qty', 'procurement_details.price', 'procurement_details.total', 'procurements.status');

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
        if (Procurement::where('id', $id)->exists())
        {
            $procurement = Procurement::where('id', $id)->first();

            $contact = Contact::where('id', $procurement->contact_id)->select('name')->first();

            $procurementDet = DB::table('procurement_details')
                            ->join('items_details', 'procurement_details.item_detail_id', '=', 'items_details.id')
                            ->join('items', 'items_details.item_code', '=', 'items.item_code')
                            ->join('locations', 'items_details.location_id', '=', 'locations.id')
                            ->where('procurement_details.procurement_id', $id)
                            ->where('procurement_details.status', 1)
                            ->select('items.item_code', 'items.unit', 'procurement_details.qty', 'procurement_details.price', 'procurement_details.initial_price', 'procurement_details.total', 'procurement_details.tax_ids', 'procurement_details.discount', 'items.name as item_name', 'items.image as item_image', 'items.category', 'locations.name as location_name', 'locations.id as location_id')
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

            $description = "Delete Procurement " . $id ."\n
            response : Procurement Deleted". "\n
            on " . date("Y-m-d H:i:s") . "
            by " . auth()->user()->username;

            $this->history('procurement', 'delete procurement', $description);

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
}
