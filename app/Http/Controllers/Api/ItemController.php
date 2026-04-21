<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Item;
use App\ItemDetail;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ItemController extends Controller
{
    public function addItem(Request $request)
    {
        $validator = Validator::make($request->all(), [
            "item_code" => "required|unique:items",
            "name" => "required"
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

        $imagePath = '';
        if($request->image)
        {
            $validator = Validator::make($request->all(), [
                'image' => 'mimes:jpeg,jpg,png,gif|max:2048', // Validation rules
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
                ], 422);
            }

            if ($request->hasFile('image')) {
                $image = $request->file('image');
                $imageName = time().'.'.$image->getClientOriginalExtension(); // Generate a unique name for the image
                $image->storeAs('public/images', $imageName); // Store in the 'storage/public/images' directory
                $imagePath =asset('storage/images/' . $imageName);
            }
        }

        Item::create([
            'item_code'  => $request->item_code,
            'name'       => $request->name,
            'image'      => $imagePath,
            'category'   => $request->category ? $request->category : '',
            'created_by' => auth()->user()->id,
            'updated_by' => auth()->user()->id,
            'status'     => 1,
            'unit'       => $request->unit ? $request->unit : ''
        ]);

        return response()->json([
            "status" => true,
            "message" => "Item registered successfully"
        ]);
    }

    public function getItem(Request $request)
    {
        $sortBy = $request->input('sort_by', 'item_code');
        $sortOrder = $request->input('sort_order', 'desc');
        $perPage = $request->input('per_page', 20);

        if (!in_array($sortOrder, ['asc', 'desc'])) {
            $sortOrder = 'desc';
        }

        $query = Item::where('status', 1);

        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->search . '%')
                  ->orWhere('item_code', 'like', '%' . $request->search . '%');
            });
        }

        if ($request->category) {
            $query->where('category', $request->category);
        }

        $items = $query->orderBy($sortBy, $sortOrder)->paginate($perPage);

        $itemCodes = $items->pluck('item_code')->toArray();

        if (empty($itemCodes)) {
            $data = [];
        } else {
            Config::set('database.connections.'. config('database.default') .'.strict', false);
            DB::reconnect();

            // Step 1: Get initial saldo from stock_value_sum (same as getStockCardDetails)
            $stockAwal = DB::select("
                SELECT
                    a.item_code,
                    a.item_unit,
                    COALESCE(a.procurement_qty, a.adjustment_qty) as saldo_qty,
                    IFNULL(a.procurement_total, 0) as saldo_nominal
                FROM 
                    stock_value_sum a
                WHERE
                    a.item_code IN (" . implode(',', array_fill(0, count($itemCodes), '?')) . ")
                ORDER BY a.item_code, a.tx_date
            ", $itemCodes);

            // Step 2: Get all stock_value transactions (same as getStockCardDetails)
            $stockTransactions = DB::select("
                SELECT
                    a.item_code,
                    a.item_unit,
                    a.item_name,
                    a.location_name,
                    COALESCE(a.procurement_date, a.sales_date, a.adjustment_date, a.usage_date) as tx_date,
                    a.procurement_date,
                    a.procurement_qty,
                    a.procurement_total,
                    a.sales_date,
                    a.sales_qty,
                    a.sales_total,
                    a.adjustment_date,
                    a.adjustment_qty,
                    a.adjustment_total,
                    a.usage_date,
                    a.usage_qty,
                    a.doc_number
                FROM 
                    stock_value a
                WHERE
                    a.item_code IN (" . implode(',', array_fill(0, count($itemCodes), '?')) . ")
                ORDER BY a.item_code, COALESCE(a.procurement_date, a.sales_date, a.adjustment_date, a.usage_date)
            ", $itemCodes);

            // Group data by item_code
            $stockAwalByItem = [];
            foreach ($stockAwal as $row) {
                $stockAwalByItem[$row->item_code][] = $row;
            }

            $stockTxByItem = [];
            foreach ($stockTransactions as $row) {
                $stockTxByItem[$row->item_code][] = $row;
            }

            // Get qty from items_details (sum of active records per item)
            $itemDetailsQty = DB::table('items_details')
                ->whereIn('item_code', $itemCodes)
                ->where('status', 1)
                ->groupBy('item_code')
                ->select('item_code', DB::raw('SUM(qty) as total_qty'))
                ->pluck('total_qty', 'item_code');

            $data = [];
            foreach ($items as $item) {
                $itemCode = $item->item_code;

                // Calculate initial saldo from stock_value_sum (same as getStockCardDetails)
                $itemStockAwal = $stockAwalByItem[$itemCode] ?? [];
                $value          = 0;
                $saldoQty       = 0;
                $saldoNominal   = 0;

                // dd($itemStockAwal);

                foreach ($itemStockAwal as $itemDet) {
                    if ($itemDet->saldo_nominal == 0) {
                        $saldoNominal += $value * $itemDet->saldo_qty;
                    } else {
                        $saldoNominal += $itemDet->saldo_nominal;
                    }

                    $saldoQty += $itemDet->saldo_qty;

                    if ($saldoQty != 0) {
                        $value = $saldoNominal / $saldoQty;
                    }
                }

                // Process stock_value transactions to get latest value (same as getStockCardDetails)
                $itemTx = $stockTxByItem[$itemCode] ?? [];
                $saldoMasuk = 0;

                foreach ($itemTx as $txRow) {
                    $initQty = $saldoQty;
                    $saldoQty = $saldoQty
                        + ((float)$txRow->procurement_qty == null ? 0 : $txRow->procurement_qty)
                        - ((float)$txRow->sales_qty == null ? 0 : $txRow->sales_qty)
                        + ((float)$txRow->adjustment_qty == null ? 0 : $txRow->adjustment_qty)
                        - ((float)$txRow->usage_qty == null ? 0 : $txRow->usage_qty);

                    if ($txRow->procurement_total != null || $txRow->adjustment_total != null) {
                        $saldoNominal = (($value * $initQty) + $txRow->procurement_total + ($txRow->adjustment_total != null ? $txRow->adjustment_total : 0));

                        if ($value == 0 && $txRow->procurement_qty != null && $txRow->procurement_qty != 0) {
                            $value = $txRow->procurement_total / $txRow->procurement_qty;
                        } else if ($saldoQty != 0) {
                            $value = $saldoNominal / $saldoQty;
                        }
                    }

                    if ($txRow->sales_total != null || $txRow->sales_qty != null) {
                        $txRow->sales_total = $value * $txRow->sales_qty;
                    }

                    if ($txRow->procurement_qty != null) {
                        $saldoMasuk = $txRow->procurement_qty;
                    } else if ($txRow->adjustment_date != null && $txRow->adjustment_qty > 0) {
                        $saldoMasuk = $txRow->adjustment_qty;
                    } else {
                        $saldoMasuk = null;
                    }

                    if ($saldoMasuk > 0 && $txRow->procurement_total == null) {
                        $saldoNominal += $value * $saldoMasuk;
                    }

                    if ($txRow->sales_qty != null) {
                        $saldoKeluar = $txRow->sales_qty;
                    } else if ($txRow->adjustment_date != null && $txRow->adjustment_qty < 0) {
                        $saldoKeluar = $txRow->adjustment_qty;
                    } else if ($txRow->usage_date != null) {
                        $saldoKeluar = $txRow->usage_qty;
                    } else {
                        $saldoKeluar = null;
                    }

                    if ($saldoKeluar > 0) {
                        $saldoNominal -= $value * $saldoKeluar;
                    }
                }

                $data[] = [
                    'item_code'      => $itemCode,
                    'name'           => $item->name,
                    'image'          => $item->image,
                    'category'       => $item->category,
                    'unit'           => $item->unit,
                    'qty'            => $itemDetailsQty[$itemCode] ?? 0,
                    'value'          => sprintf("%01.2f", $value),
                ];
            }
        }

        $items->appends($request->all());

        return response()->json([
            'status' => true,
            'data' => $data,
            'pagination' => [
                'per_page'       => $items->perPage(),
                'current_page'   => $items->currentPage(),
                'last_page'      => $items->lastPage(),
                'next_page_url'  => $items->nextPageUrl(),
                'prev_page_url'  => $items->previousPageUrl(),
                'total_data'     => $items->total(),
            ]
        ]);
    }

    public function updateItem(Request $request)
    {
        $validator = Validator::make($request->all(), [
            "item_code" => "required"
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

        $item_code = $request->item_code;

        if (Item::where('item_code', $item_code)->exists())
        {
            $item = Item::where('item_code', $item_code)->first();

            $imagePath = '';
            if($request->image)
            {
                $validator = Validator::make($request->all(), [
                    'image' => 'mimes:jpeg,jpg,png,gif|max:2048', // Validation rules
                ]);

                if ($validator->fails()) {
                    return response()->json($validator->errors(), 422);
                }

                if ($request->hasFile('image')) {
                    $image = $request->file('image');
                    $imageName = time().'.'.$image->getClientOriginalExtension(); // Generate a unique name for the image
                    $image->storeAs('public/images', $imageName); // Store in the 'storage/public/images' directory
                    $imagePath =asset('storage/images/' . $imageName);
                }
            }
            // dd($item);

            Item::where('item_code', $item_code)
            ->update([
                'item_code'  => $request->new_item_code ? $request->new_item_code : $item->item_code,
                'name'       => $request->name ? $request->name : $item->name,
                'image'      => $imagePath == '' ? $item->image : $imagePath,
                'category'   => $request->category ? $request->category : $item->category,
                'updated_at' => date("Y-m-d H:i:s"),
                'updated_by' => auth()->user()->id,
                'unit'       => $request->unit ? $request->unit : $item->unit
            ]);            

            return response()->json([
                "status" => true,
                "message" => "Item updated successfully"
            ]);
        }
        else
        {
            return response()->json([
                "status" => false,
                "message" => "Item not found"
            ], 404);
        }
    }

    public function deleteItem(Request $request)
    {
        $validator = Validator::make($request->all(), [
            "item_code" => "required"
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

        $item_code = $request->item_code;

        if (Item::where('item_code', $item_code)->exists())
        {
            Item::where('item_code', $item_code)
            ->update([
                'status'     => 0,
                'updated_at' => date("Y-m-d H:i:s"),
                'updated_by' => auth()->user()->id
            ]);

            return response()->json([
                "status" => true,
                "message" => "Item deleted successfully"
            ]);
        }
        else
        {
            return response()->json([
                "status" => false,
                "message" => "Item not found"
            ], 404);
        }
    }

    public function getItemById(Request $request)
    {
        $validator = Validator::make($request->all(), [
            "item_code" => "required"
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

        $item_code = $request->item_code;

        if (Item::where('item_code', $item_code)->where('status', 1)->exists())
        {
            $item = Item::where('item_code', $item_code)->first();

            $itemDet = DB::table('items_details')
                        ->join('locations', 'items_details.location_id', '=', 'locations.id')
                        ->where('items_details.status', 1)
                        ->where('items_details.item_code', $item_code)
                        ->select('items_details.qty', 'items_details.price', 'locations.name AS location')
                        ->get();

            $data = $item;
            $data['item_details'] = $itemDet;

            return response()->json([
                "status" => true,
                "data" => $data
            ]);
        }
        else
        {
            return response()->json([
                "status" => false,
                "message" => "Item not found"
            ], 404);
        }
    }

    public function getUniqueCategories()
    {
        $categories = Item::where('status', 1)->distinct()->pluck('category');

        return response()->json([
            "status" => true,
            "data"  => $categories
        ]);
    }

    public function getUniqueUnits()
    {
        $units = Item::where('status', 1)->distinct()->pluck('unit');

        return response()->json([
            "status" => true,
            "data"  => $units
        ]);
    }
}
