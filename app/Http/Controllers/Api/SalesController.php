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
use DateTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

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

            $taxes = explode(',', $request->tax_ids);

            $tax = Tax::whereIn('id', $taxes)->sum('value');
            $taxes = Tax::whereIn('id', $taxes)->get();
            
            $totalTax = [];
            foreach ($taxes as $key)
            {
                $totalTax[] = $key->value;
            }

            // insert sales
            $sales = Sales::create([
                'contact_id' => $request->contact_id,
                'sales_date' => $salesDate,
                'amount'     => 0,
                'pay_status' => null,
                'created_by' => auth()->user()->id,
                'updated_by' => auth()->user()->id,
                'status'     => 1,
                'doc_number' => $documentNumber,
                'bank_id'    => $request->bank_id ?? null,
                'rounding'   => (float)($request->round)
            ]);

            $totalAmount = 0.00;

            foreach ($request->items as $item)
            {
                $itemDet = ItemDetail::where('item_code', $item['item_code'])->where('location_id', $request->location_id)->where('status', 1)->first();

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
            // dd($totalTax)

            $sales->update([
                'amount'        => $roundedAmount,
                'pay_status'    => $paymentStatus,
                'updated_by'    => auth()->user()->id,
                'updated_at'    => date("Y-m-d H:i:s"),
                'tax'           => implode('|', $totalTax)
            ]);

            Payment::create([
                'sales_id'      => $sales->id,
                'type'          => "IN",
                'amount'        => $request->pay_amount ?? 0,
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
            dd($th);
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
            "delivery_status"    => "required"
        ]);

        if ($validator->fails()) {
            return response()->json([
                "status" => false,
                "message" => $validator->errors()
            ]);
        }

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

    public function getSales(Request $request)
    {
        $sortBy = $request->input('sort_by', 'sales_date');
        $sortOrder = $request->input('sort_order', 'desc');
        $search = $request->input('search', null);
        $searchVendor = $request->input('search_vendor', null);
        $searchsalesDate = $request->input('search_sales_date', null);
        $searchDocNumber = $request->input('search_doc_number', null);


        if (!in_array($sortOrder, ['asc', 'desc'])) {
            $sortOrder = 'desc'; // Default to ascending if invalid
        }

        $query  = Sales::query();

        $query->join('contacts', 'sales.contact_id', '=', 'contacts.id')
              ->select('sales.*', 'contacts.name as contact_name');

        if ($search != null)
        {
            $query->where('contacts.name', 'like', '%' . $search . '%');
            $query->orWhere('sales.sales_date', 'like', '%' . $search . '%');
            $query->orWhere('sales.doc_number', 'like', '%' . $search . '%');
        }
        else
        {
            if ($searchVendor != null)
            {
                $query->where('contacts.name', 'like', '%' . $searchVendor . '%');
            }

            if ($searchsalesDate != null)
            {
                $query->where('sales.sales_date', 'like', '%' . $searchsalesDate . '%');
            }

            if ($searchDocNumber != null)
            {
                $query->where('sales.doc_number', 'like', '%' . $searchDocNumber . '%');
            }
        }

        $sales = $query->orderBy($sortBy, $sortOrder)->orderBy('id', 'desc')->paginate(10);
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
                'total_data' => $sales->total() 
            ],
        ]);
    }

    public function getSalesById($id)
    {
        if(Sales::where('id', $id)->where('status',1)->exists())
        {
            $sales = Sales::where('id', $id)->where('status', 1)->first();

            $contact = Contact::where('id', $sales->contact_id)->select('name')->first();

            $salesDet = DB::table('sales_details')
                            ->join('items_details', 'sales_details.item_detail_id', '=', 'items_details.id')
                            ->join('items', 'items_details.item_code', '=', 'items.item_code')
                            ->join('locations', 'items_details.location_id', '=', 'locations.id')
                            ->where('sales_details.sales_id', $id)
                            ->select('items.item_code', 'sales_details.qty', 'sales_details.price', 'sales_details.initial_price', 'sales_details.total', 'sales_details.tax_ids', 'sales_details.discount', 'items.name as item_name', 'items.image as item_image', 'items.category', 'locations.name as location_name', 'locations.id as location_id')
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
                ->join('locations', 'items_details.location_id', '=', 'locations.id')
                ->select('sales.sales_date', 'contacts.name as contact_name', 'items_details.item_code', 'locations.name as location_name', 'sales.doc_number', 'sales_details.qty as sales_qty', 'sales_details.price', 'sales_details.total');

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
