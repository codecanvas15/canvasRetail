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
use App\Tax;
use App\VoidDetails;
use App\VoidTransaction;
use Barryvdh\DomPDF\Facade\Pdf as PDF;
use DateTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Claims\Custom;

class SalesController extends Controller
{
    //
    public function addSales(Request $request)
    {
        $validator = Validator::make($request->all(), [
            "contact_id"        => "required",
            "location_id"       => "required",
            "items"             => "required",
            "sales_date"        => "required"
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
            $date = strtotime($request->sales_date);
            $salesDate = date('Y-m-d H:i:s',$date);

            // document number
            $date = new DateTime('now');
            $date = $date->format('dmY');

            Config::set('database.connections.'. config('database.default') .'.strict', false);
            DB::reconnect();

            
            $documentNumber = "";
            $countDocNo = 1;

            while ($countDocNo > 0)
            {
                $seq = DB::select("
                    SELECT
                        count(doc_number) as seq
                    FROM
                        sales
                    WHERE
                        DATE_FORMAT(created_at, '%d%m%Y') <= STR_TO_DATE(?, '%d%m%Y')
                        AND doc_number IS NOT NULL
                ", [$date]);

                $documentNumber = 'INV-'.$date.'-'.str_pad(($seq[0]->seq+1), 4, '0', STR_PAD_LEFT);

                $countDocNo = DB::select("
                    SELECT
                        count(doc_number) as seq
                    FROM
                        sales
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

            $due = Contact::where('id', $request->contact_id)->select('due_date')->first();

            // Convert $salesDate to DateTime object
            $salesDateTime = new DateTime($salesDate);

            // Add the number of days from $due->due_date
            $salesDateTime->modify('+' . $due->due_date . ' days');

            // Format the resulting date
            $dueDate = $salesDateTime->format('Y-m-d H:i:s');

            // insert sales
            $sales = Sales::create([
                'contact_id'    => $request->contact_id,
                'sales_date'    => $salesDate,
                'amount'        => 0,
                'pay_status'    => null,
                'created_by'    => auth()->user()->id,
                'updated_by'    => auth()->user()->id,
                'status'        => 1,
                'doc_number'    => $documentNumber,
                'bank_id'       => $request->bank_id ?? null,
                'rounding'      => (float)($request->round),
                'location_id'   => $request->location_id,
                'reason'        => $request->notes ?? null,
                'due_date'      => $dueDate,
            ]);

            $totalAmount = 0.00;

            foreach ($request->items as $item)
            {
                $itemDet = ItemDetail::where('item_code', $item['item_code'])->where('location_id', $request->location_id)->where('status', 1)->first();

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

                if ($request->include_tax)
                {
                    $discount = $item['discount'] ?? 0 ? ($item['discount']/100) * $item['price'] : 0;
                    
                    $priceAfterDiscount = $item['price'] - $discount;

                    $itemPrice = $priceAfterDiscount / (1 + $tax/100);

                    $total = $item['qty'] * $itemPrice;
                }
                else
                {
                    $discount = $item['discount'] ?? 0 ? ($item['discount']/100) * $item['price'] : 0;

                    $priceAfterDiscount = $item['price'] - $discount;

                    $itemPrice = $priceAfterDiscount;

                    $total = $item['qty'] * $itemPrice;
                }

                if ($request->include_tax)
                {
                    $total = $item['qty'] * $itemPrice;
                }
                else
                {
                    $total = $item['qty'] * $item['price'];
                }

                // insert sales detail
                SalesDetail::create([
                    'sales_id'          => $sales->id,
                    'item_detail_id'    => $itemDet['id'],
                    'qty'               => $item['qty'],
                    'price'             => $itemPrice,
                    'total'             => $total,
                    'tax_ids'           => $request->tax_ids,
                    'created_by'        => auth()->user()->id,
                    'updated_by'        => auth()->user()->id,
                    'status'            => 1,
                    'discount'          => $discount,
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
                $roundedAmount = round($totalAmount);
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
            //         "message" => "Total Payment amount is greater than sales amount."
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
            // dd($totalTax)

            $sales->update([
                'amount'        => $roundedAmount,
                'pay_status'    => $paymentStatus,
                'updated_by'    => auth()->user()->id,
                'updated_at'    => date("Y-m-d H:i:s"),
                'tax'           => implode('|', $totalTax)
            ]);

            // Payment::create([
            //     'sales_id'      => $sales->id,
            //     'type'          => "IN",
            //     'amount'        => $request->pay_amount ?? 0,
            //     'pay_date'      => date("Y-m-d H:i:s"),
            //     'created_by'    => auth()->user()->id,
            //     'updated_by'    => auth()->user()->id,
            //     'status'        => 1,
            //     'pay_desc'      => "Initial Payment"
            // ]);

            $description = "Create Sales\n
            request : " . json_encode($request->all()) . "
            response : Sales Success". "\n
            on " . date("Y-m-d H:i:s") . "
            by " . auth()->user()->username;

            $this->history('sales', 'create sales', $description);

            DB::commit();

            $faktur = $this->generateFaktur($sales->id);

            return response()->json([
                "status" => true,
                "message" => "Sales Success",
                "sales_id" => $sales->id,
                "faktur" => $faktur
            ]);
        }
        catch (\Throwable $th)
        {
            // dd($th);
            DB::rollBack();

            return response()->json([
                "status" => false,
                "message" => $th
            ], 500);
        }
    }

    public function updateSales(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            "itemDetails"    => "array"
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

        $sales = Sales::where('id', $id)->where('status', 1)->first();
        
        if ($sales == null)
        {
            return response()->json([
                "status" => false,
                "message" => "Sales not found"
            ], 404);
        }

        $date = strtotime($request->sales_date ?? $sales->sales_date);
        $salesDate = date('Y-m-d H:i:s',$date);

        // document number
        $date = new DateTime('now');
        $date = $date->format('dmY');

        $due = Contact::where('id', $request->contact_id)->select('due_date')->first();

            // Convert $salesDate to DateTime object
        $salesDateTime = new DateTime($salesDate);

        // Add the number of days from $due->due_date
        $salesDateTime->modify('+' . $due->due_date . ' days');

        // Format the resulting date
        $dueDate = $salesDateTime->format('Y-m-d H:i:s');

        if ($request->tax_ids == null)
        {
            $taxes = explode('|', $sales->tax);
            $tax = array_sum($taxes);

            $totalTax = $sales->tax;
        }
        else
        {
            $taxes = explode(',', $request->tax_ids);

            $tax = Tax::whereIn('id', $taxes)->sum('value');
            $taxes = Tax::whereIn('id', $taxes)->get();
            
            $totalTax = [];
            foreach ($taxes as $key)
            {
                $totalTax[] = $key->value;
            }

            $totalTax = implode('|', $totalTax);
        }
        
        DB::beginTransaction();
        try
        {
            $sales->update([
                'contact_id'    => $request->contact_id ?? $sales->contact_id,
                'sales_date'    => $salesDate,
                'updated_by'    => auth()->user()->id,
                'status'        => 1,
                'bank_id'       => $request->bank_id ?? $sales->bank_id,
                'rounding'      => (float)($request->round) ?? $sales->rounding,
                'location_id'   => $request->location_id ?? $sales->location_id,
                'reason'        => $request->notes ?? $sales->reason,
                'due_date'      => $dueDate,
                'tax'           => $totalTax
            ]);
            
            $salesDet = SalesDetail::where('sales_id', $id)->where('status', 1)->get();
            $totalAmount = 0;
            
            if ($request->itemDetails != null)
            {
                foreach ($request->itemDetails as $item)
                {
                    $salesDet = DB::table('sales_details')
                                    ->join('items_details', 'sales_details.item_detail_id', '=', 'items_details.id')
                                    ->where('items_details.item_code', $item['item_code'])
                                    ->where('sales_details.status', 1)
                                    ->where('items_details.status', 1)
                                    ->select('sales_details.id as id', 'sales_details.item_detail_id as item_detail_id')
                                    ->first();
    
                    if ($salesDet == null)
                    {
                        DB::rollBack();
                        return response()->json([
                            "status" => false,
                            "message" => "Sales item not found"
                        ], 404);
                    }
    
                    if ($request->location_id != null)
                    {
                        $itemDet = ItemDetail::where('item_code', $item['item_code'])->where('location_id', $request->location_id)->where('status', 1)->first();
                    }
                    else
                    {
                        $itemDet = ItemDetail::where('id', $salesDet->item_detail_id)->where('status', 1)->first();
                    }
    
                    if ($request->include_tax)
                    {
                        $discount = $item['discount'] ?? 0 ? ($item['discount']/100) * $item['price'] : 0;
                        
                        $priceAfterDiscount = $item['price'] - $discount;
    
                        $itemPrice = $priceAfterDiscount / (1 + $tax/100);
    
                        $total = $item['qty'] * $itemPrice;
                    }
                    else
                    {
                        $discount = $item['discount'] ?? 0 ? ($item['discount']/100) * $item['price'] : 0;
    
                        $priceAfterDiscount = $item['price'] - $discount;
    
                        $itemPrice = $priceAfterDiscount;
    
                        $total = $item['qty'] * $itemPrice;
                    }
    
                    if ($request->include_tax)
                    {
                        $total = $item['qty'] * $itemPrice;
                    }
                    else
                    {
                        $total = $item['qty'] * $item['price'];
                    }
    
                    $salesDet = SalesDetail::where('id', $salesDet->id)->where('status', 1)->first();
                    
                    $salesDet->update([
                        'status'            => 0,
                        'updated_by'        => auth()->user()->id,
                        'updated_at'        => date("Y-m-d H:i:s")
                    ]);
    
                    // insert sales detail
                    SalesDetail::create([
                        'sales_id'          => $sales->id,
                        'item_detail_id'    => $itemDet['id'],
                        'qty'               => $item['qty'],
                        'price'             => $itemPrice,
                        'total'             => $total,
                        'tax_ids'           => $request->tax_ids,
                        'created_by'        => auth()->user()->id,
                        'updated_by'        => auth()->user()->id,
                        'status'            => 1,
                        'discount'          => $discount,
                        'initial_price'     => $item['price']
                    ]);

                    $totalAmount += ($total + $total * ($tax/100));
                }
            }
            else
            {
                if ($request->location_id != $sales->location_id)
                {
                    $salesDet = SalesDetail::where('sales_id', $sales->id)->where('status', 1)->get();
    
                    foreach ($salesDet as $item)
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

                    $salesDet->update([
                        'location_id'   => $request->location_id,
                        'updated_by'    => auth()->user()->id,
                        'updated_at'    => date("Y-m-d H:i:s")
                    ]);
                }

                $totalAmount = $sales->amount;
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
                $roundedAmount = round($totalAmount);
            }

            $paymentStatus = 'Unpaid';

            $sales->update([
                'amount'        => $roundedAmount,
                'pay_status'    => $paymentStatus,
                'updated_by'    => auth()->user()->id,
                'updated_at'    => date("Y-m-d H:i:s")
            ]);

            $description = "Update Sales\n
            request : " . json_encode($request->all()) . "
            response : Sales Updated". "\n
            on " . date("Y-m-d H:i:s") . "
            by " . auth()->user()->username;

            $this->history('sales', 'update sales', $description);

            DB::commit();

            $faktur = $this->generateFaktur($sales->id);

            return response()->json([
                "status" => true,
                "message" => "Sales Updated",
                "sales_id" => $sales->id,
                "faktur" => $faktur
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

    public function approveSales(Request $request)
    {
        $validator = Validator::make($request->all(), [
            "sales_id"    => "required",
            "is_approve"  => "required|in:1,0",
        ]);

        if ($validator->fails()) {
            return response()->json([
                "status" => false,
                "message" => $validator->errors()
            ]);
        }

        $sales = Sales::where('id', $request->sales_id)->where('status', 1)->first();

        if ($sales == null)
        {
            return response()->json([
                "status" => false,
                "message" => "Sales not found or already approved"
            ], 404);
        }

        DB::beginTransaction();

        try
        {
            if (!$request->is_approve)
            {
                $sales->update([
                    'status'        => 3,
                    'updated_by'    => auth()->user()->id,
                    'updated_at'    => date("Y-m-d H:i:s")
                ]);
                
                $description = "Approve Sales\n
            request : " . json_encode($request->all()) . "
            response : Sales Rejected". "\n
            on " . date("Y-m-d H:i:s") . "
            by " . auth()->user()->username;

            $this->history('sales', 'approve sales', $description);
    
                DB::commit();
    
                return response()->json([
                    "status" => true,
                    "message" => "Sales Rejected"
                ]);
            }
    
            $sales->update([
                'status'            => 2,
                'delivery_status'   => $request->delivery_status ?? $sales->delivery_status,
                'updated_by'        => auth()->user()->id,
                'updated_at'        => date("Y-m-d H:i:s")
            ]);


            $salesDet = SalesDetail::where('status', 1)->where('sales_id', $request->sales_id)->get();
    
            foreach ($salesDet as $item)
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

            $description = "Approve Sales\n
            request : " . json_encode($request->all()) . "
            response : Sales Approved". "\n
            on " . date("Y-m-d H:i:s") . "
            by " . auth()->user()->username;

            $this->history('sales', 'approve sales', $description);
    
            DB::commit();
            return response()->json([
                "status" => true,
                "message" => "Sales Approved"
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

    public function getSales(Request $request)
    {
        $sortBy = $request->input('sort_by', 'sales_date');
        $sortOrder = $request->input('sort_order', 'desc');
        $search = $request->input('search', null);
        $searchCustomer = $request->input('search_customer', null);
        $searchDocNumber = $request->input('search_doc_number', null);
        $searchPaymentStatus = $request->input('search_payment_status', null);
        $searchStatus = $request->input('search_status', null);

        $startSalesDate = $request->input('search_start_sales_date', null);
        $endSalesDate = $request->input('search_end_sales_date', null);

        if ($startSalesDate != null && $endSalesDate == null)
        {
            return response()->json([
                "status" => false,
                "message" => "The end date cannot be empty."
            ], 400);
        }
        else if ($startSalesDate == null && $endSalesDate != null)
        {
            return response()->json([
                "status" => false,
                "message" => "The start date cannot be empty."
            ], 400);
        }

        if ($startSalesDate > $endSalesDate) {
            return response()->json([
                "status" => false,
                "message" => "The start date cannot be after the end date."
            ], 400);
        }

        if (!in_array($sortOrder, ['asc', 'desc'])) {
            $sortOrder = 'desc'; // Default to ascending if invalid
        }

        $query  = Sales::query();

        $query->join('contacts', 'sales.contact_id', '=', 'contacts.id')
              ->select('sales.*', 'contacts.name as contact_name');

        if ($search != null)
        {
            $query->where('contacts.name', 'like', '%' . $search . '%');
            $query->orWhere('sales.doc_number', 'like', '%' . $search . '%');
            $query->orWhere('sales.pay_status', 'like', '%' . $search . '%');
        }
        else
        {
            if ($searchCustomer != null)
            {
                $query->where('contacts.id', $searchCustomer);
            }
            if ($searchDocNumber != null)
            {
                $query->where('sales.doc_number', 'like', '%' . $searchDocNumber . '%');
            }

            if ($startSalesDate != null && $endSalesDate != null)
            {
                $query->whereBetween('sales.sales_date', [$startSalesDate, $endSalesDate]);
            }

            if ($searchPaymentStatus != null)
            {
                $query->where('sales.pay_status', 'like',  $searchPaymentStatus);
            }

            if ($searchCustomer == null && $searchDocNumber == null && $startSalesDate == null && $endSalesDate == null)
            {
                $startSalesDate = (new DateTime($startSalesDate))->modify('first day of this month')->format('Y-m-d');
                $date = new DateTime('now');
                $endSalesDate = $date->format('Y-m-d');
                $query->whereBetween('sales.sales_date', [$startSalesDate, $endSalesDate]);
            }

            if ($searchStatus != null)
            {
                $query->where('sales.status', $searchStatus);
            }
        }

        $sales = $query->orderBy($sortBy, $sortOrder)->orderBy('id', 'desc')->paginate(10);
        $sales->appends([
            'sort_by' => $sortBy,
            'sort_order' => $sortOrder,
        ]);

        $sales->appends($request->all());

        return response()->json([
            'status' => true,
            'data' => $sales->items(),
            'pagination' => [
                'current_page' => $sales->currentPage(),
                'total_pages' => $sales->lastPage(),
                'next_page' => $sales->nextPageUrl(),
                'prev_page' => $sales->previousPageUrl(),
                'total_data' => $sales->total() 
            ],
        ]);
    }

    public function getSalesById($id)
    {
        if(Sales::where('id', $id)->exists())
        {
            $sales = Sales::where('id', $id)->first();

            $contact = Contact::where('id', $sales->contact_id)->select('name')->first();

            $salesDet = DB::table('sales_details')
                            ->join('items_details', 'sales_details.item_detail_id', '=', 'items_details.id')
                            ->join('items', 'items_details.item_code', '=', 'items.item_code')
                            ->join('locations', 'items_details.location_id', '=', 'locations.id')
                            ->where('sales_details.sales_id', $id)
                            ->where('sales_details.status', 1)
                            ->select('items.item_code', 'items.unit', 'sales_details.qty', 'sales_details.price', 'sales_details.initial_price', 'sales_details.total', 'sales_details.tax_ids', 'sales_details.discount', 'items.name as item_name', 'items.image as item_image', 'items.category', 'locations.name as location_name', 'locations.id as location_id')
                            ->get();

            $paymentDet = Payment::where('sales_id', $id)->where('status', 1)->get();

            $data = $sales;
            $data['contact_name'] = $contact->name;
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

    public function getItemSales(Request $request)
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

        $sortBy = $request->input('sort_by', 'sales_date');
        $sortOrder = $request->input('sort_order', 'desc');
        $search = $request->input('search', null);
        $searchVendor = $request->input('search_vendor', null);
        $searchSalesDate = $request->input('search_sales_date', null);
        $searchDocNumber = $request->input('search_doc_number', null);
        $searchItemCode = $request->input('search_item_code', null);

        if (!in_array($sortOrder, ['asc', 'desc'])) {
            $sortOrder = 'desc'; // Default to ascending if invalid
        }

        $query  = Sales::query();

        $query->join('contacts', 'sales.contact_id', '=', 'contacts.id')
                ->join('sales_details', 'sales.id', '=', 'sales_details.sales_id')
                ->join('items_details', 'sales_details.item_detail_id', '=', 'items_details.id')
                ->join('items', 'items_details.item_code', '=', 'items.item_code')
                ->join('locations', 'items_details.location_id', '=', 'locations.id')
                ->select('sales.sales_date', 'contacts.name as contact_name', 'items_details.item_code', 'items.unit', 'locations.name as location_name', 'sales.doc_number', 'sales_details.qty as sales_qty', 'sales_details.price', 'sales_details.total', 'sales.status');

        if ($search != null)
        {
            $query->where('contacts.name', 'like', '%' . $search . '%');
            $query->orWhere('sales.sales_date', 'like', '%' . $search . '%');
            $query->orWhere('sales.doc_number', 'like', '%' . $search . '%');
            $query->orWhere('items_details.item_code', 'like', '%' . $search . '%');
        }
        else
        {
            if ($searchVendor != null)
            {
                $query->where('contacts.name', 'like', '%' . $searchVendor . '%');
            }

            if ($searchSalesDate != null)
            {
                $query->where('sales.sales_date', 'like', '%' . $searchSalesDate . '%');
            }

            if ($searchDocNumber != null)
            {
                $query->where('sales.doc_number', 'like', '%' . $searchDocNumber . '%');
            }

            if ($searchItemCode != null)
            {
                $query->where('items_details.item_code', 'like', '%' . $searchItemCode . '%');
            }
        }

        $sales = $query->orderBy($sortBy, $sortOrder)->orderBy('sales_date', 'desc')->paginate(10);
        $sales->appends([
            'sort_by' => $sortBy,
            'sort_order' => $sortOrder,
        ]);

        return response()->json([
            'status' => true,
            'data' => $sales->items(),
            'pagination' => [
                'current_page' => $sales->currentPage(),
                'total_pages' => $sales->lastPage(),
                'next_page' => $sales->nextPageUrl(),
                'prev_page' => $sales->previousPageUrl(),
                'total_data' => $sales->total(),
            ],
        ]);
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

            $description = "delete Sales " . $id . " \n
            response : Sales deleted". "\n
            on " . date("Y-m-d H:i:s") . "
            by " . auth()->user()->username;

            $this->history('sales', 'delete sales', $description);

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

    public function createDocs(Request $request)
    {
        $path = public_path() . '/pdf/' . time() . '.pdf';

        $pdf = PDF::loadView('pdf');

        $pdf->save($path);

        return response()->download($path);
    }

    public function faktur(Request $request)
    {
        $validator = Validator::make($request->all(), [
            "sales_id"        => "required"
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

        $id = $request->sales_id;

        if(Sales::where('id', $id)->whereIn('status', [1,2,3,4])->exists())
        {
            $sales = Sales::where('id', $id)->whereIn('status', [1,2,3,4])->first();

            $contact = Contact::where('id', $sales->contact_id)->first();

            $salesDet = DB::table('sales_details')
                            ->join('items_details', 'sales_details.item_detail_id', '=', 'items_details.id')
                            ->join('items', 'items_details.item_code', '=', 'items.item_code')
                            ->join('locations', 'items_details.location_id', '=', 'locations.id')
                            ->where('sales_details.sales_id', $id)
                            ->select('items.item_code', 'items.unit', 'sales_details.qty', 'sales_details.price', 'sales_details.initial_price', 'sales_details.total', 'sales_details.tax_ids', 'sales_details.discount', 'items.name as item_name', 'items.item_code as item_code', 'items.image as item_image', 'items.category', 'locations.name as location_name', 'locations.id as location_id')
                            ->get();

            $paymentDet = Payment::where('sales_id', $id)->where('status', 1)->get();

            $location = Location::where('id', $sales->location_id)->first();

            $data = $sales;
            $data['contact_name'] = $contact->name;
            $data['contact_address'] = $contact->address;
            $data['location_name'] = $location->name;
            $data['details'] = $salesDet;
            $data['payments'] = $paymentDet;
            $data['total'] = $salesDet->sum('total');
            $tax = explode('|', $sales->tax);
            $data['ppn'] = array_sum($tax)/100 * $data['total'];
            $data['grand_total'] = $sales['amount'];

            // Split sales details into chunks of 10 items each
            $chunks = $salesDet->chunk(10);

            $pdfPaths = [];
            $data['details'] = $chunks;
            $htmlContent = view('faktur', ['data' => $data])->render();
            $htmlPath = public_path() . '/html/' . $sales['doc_number'] . time() . '.html';
            file_put_contents($htmlPath, $htmlContent);

            // Generate PDF from the saved HTML file
            $pdfPath = public_path() . '/pdf/' . $sales['doc_number'] . time() . '.pdf';
            $pdf = PDF::loadHTML($htmlContent)
                ->setPaper('a5', 'landscape'); // Set paper size to A5 and orientation to landscape
            $pdf->save($pdfPath);

            $pdfPaths = url('/pdf/' . basename($pdfPath));

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

    public function generateFaktur($id)
    {
        if(Sales::where('id', $id)->exists())
        {
            $sales = Sales::where('id', $id)->first();

            $contact = Contact::where('id', $sales->contact_id)->first();

            $salesDet = DB::table('sales_details')
                            ->join('items_details', 'sales_details.item_detail_id', '=', 'items_details.id')
                            ->join('items', 'items_details.item_code', '=', 'items.item_code')
                            ->join('locations', 'items_details.location_id', '=', 'locations.id')
                            ->where('sales_details.sales_id', $id)
                            ->select('items.item_code', 'items.unit', 'sales_details.qty', 'sales_details.price', 'sales_details.initial_price', 'sales_details.total', 'sales_details.tax_ids', 'sales_details.discount', 'items.name as item_name', 'items.item_code as item_code', 'items.image as item_image', 'items.category', 'locations.name as location_name', 'locations.id as location_id')
                            ->get();

            $paymentDet = Payment::where('sales_id', $id)->where('status', 1)->get();

            $location = Location::where('id', $sales->location_id)->first();

            $data = $sales;
            $data['contact_name'] = $contact->name;
            $data['contact_address'] = $contact->address;
            $data['location_name'] = $location->name;
            $data['details'] = $salesDet;
            $data['payments'] = $paymentDet;
            $data['total'] = $salesDet->sum('total');
            $tax = explode('|', $sales->tax);
            $data['ppn'] = array_sum($tax)/100 * $data['total'];
            $data['grand_total'] = $sales['amount'];

            // Split sales details into chunks of 10 items each
            $chunks = $salesDet->chunk(10);

            $pdfPaths = [];
            $data['details'] = $chunks;
            $htmlContent = view('faktur', ['data' => $data])->render();
            $htmlPath = public_path() . '/html/' . $sales['doc_number'] . time() . '.html';
            file_put_contents($htmlPath, $htmlContent);

            // Generate PDF from the saved HTML file
            $pdfPath = public_path() . '/pdf/' . $sales['doc_number'] . time() . '.pdf';
            $pdf = PDF::loadHTML($htmlContent)
                ->setPaper('a5', 'landscape'); // Set paper size to A5 and orientation to landscape
            $pdf->save($pdfPath);

            $pdfPaths = url('/pdf/' . basename($pdfPath));

            return $pdfPaths;
        }
        else
        {
            return response()->json([
                "status" => false,
                "message" => "Sales not found"
            ], 404);
        }
    }

    public function void(Request $request)
    {
        $validator = Validator::make($request->all(), [
            "sales_id"    => "required"
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

        $sales = Sales::where('id', $request->sales_id)->where('status', 2)->first();

        if ($sales == null)
        {
            return response()->json([
                "status" => false,
                "message" => "Sales not found"
            ], 404);
        }

        DB::beginTransaction();
        try
        {
            $sales->update([
                'status'        => 4,
                'updated_by'    => auth()->user()->id,
                'updated_at'    => date("Y-m-d H:i:s")
            ]);

            $void = VoidTransaction::create([
                'ref_id'            => $sales->doc_number,
                'sales_id'          => $request->sales_id,
                'reason'            => $request->reason,
                'status'            => 1,
                'updated_by'        => auth()->user()->id,
                'updated_at'        => date("Y-m-d H:i:s")
            ]);

            $salesDet = SalesDetail::where('sales_id', $request->sales_id)->where('status', 1)->get();

            foreach ($salesDet as $item)
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

            $description = "Void Sales\n
            request : " . json_encode($request->all()) . "
            response : Sales Voided". "\n
            on " . date("Y-m-d H:i:s") . "
            by " . auth()->user()->username;

            $this->history('sales', 'void sales', $description);

            DB::commit();

            return response()->json([
                "status" => true,
                "message" => "Sales Voided"
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
