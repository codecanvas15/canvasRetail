<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Item;
use App\ItemDetail;
use DateTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class StockCardController extends Controller
{
    //
    public function getStockCardList(Request $request)
    {
        $validator = Validator::make($request->all(), [
            "month_year"  => ['regex:/^(0[1-9]|1[0-2])-\d{4}$/']
        ]);

        if ($validator->fails()) {
            return response()->json([
                "status" => false,
                "message" => $validator->errors()
            ]);
        }

        $date = new DateTime('now');
        $filterMonth = $date->format('m-Y');

        if ($request->month_year)
        {
            $filterMonth = $request->month_year;
        }

        Config::set('database.connections.'. config('database.default') .'.strict', false);
        DB::reconnect();

        $stock = DB::select("
            SELECT
                a.item_code,
                a.item_name,
                a.location_name,
                a.procurement_date,
                a.procurement_qty,
                a.procurement_total,
                a.sales_date,
                a.sales_qty,
                a.sales_total,
                a.adjustment_date,
                a.adjustment_qty,
                a.usage_date,
                a.usage_qty
            FROM 
                stock_value a
            WHERE
                a.procurement_date >= STR_TO_DATE(?, '%m-%Y')
                or a.sales_date >= STR_TO_DATE(?, '%m-%Y')
                or a.adjustment_date >= STR_TO_DATE(?, '%m-%Y')
                or a.usage_date >= STR_TO_DATE(?, '%m-%Y')
            ORDER BY a.item_code, a.created_at, a.procurement_date, a.sales_date, a.adjustment_date, a.usage_date
        ", [$filterMonth, $filterMonth, $filterMonth, $filterMonth]);

        $stockAwal = DB::select("
            SELECT
                a.item_code,
                COALESCE(a.procurement_qty, a.sales_qty * -1, a.adjustment_qty, a.usage_qty * -1) as saldo_qty,
                IFNULL(a.procurement_total, 0) as saldo_nominal
            FROM 
                stock_value_sum a
            WHERE
                a.tx_date <= STR_TO_DATE(?, '%m-%Y')
            ORDER BY a.item_code, a.created_at
        ", [$filterMonth]);

        $items = Item::where('status', 1)->paginate(10); // Add pagination here

        $stockList = [];

        foreach ($items as $item)
        {
            $item_code = $item->item_code;

            $itemStockAwal = array_values(array_filter($stockAwal, function($k) use ($item_code) {
                return $k->item_code == $item_code;
            }));

            $value = 0;
            $saldoQty       = 0;
            $saldoNominal   = 0;
            $procurement_qty = 0;

            foreach ($itemStockAwal as $key => $itemDet) 
            {
                if ($itemDet->saldo_nominal == 0)
                {
                    $saldoNominal += $value * $itemDet->saldo_qty;
                }
                else
                {
                    $saldoNominal += $itemDet->saldo_nominal;
                }
                
                $saldoQty += $itemDet->saldo_qty;
                
                if ($saldoQty != 0)
                {
                    $value = $saldoNominal / $saldoQty;
                }
            }


            $saldoNominal = ($value < 0 ? $value * -1 : $value) * $saldoQty;

            if(sizeof($itemStockAwal) > 0)
            {
                $saldoQty        = $saldoQty;
                $saldoNominal    = $saldoNominal;
            }

            $itemStock = array_values(array_filter($stock, function($k) use ($item_code) {
                return $k->item_code == $item_code;
            }));

            for($j = 0; $j < sizeof($itemStock); $j++)
            {
                $saldoQty = $saldoQty + ($itemStock[$j]->procurement_qty == null ? 0 : $itemStock[$j]->procurement_qty) - ($itemStock[$j]->sales_qty == null ? 0 : $itemStock[$j]->sales_qty) + ($itemStock[$j]->adjustment_qty == null ? 0 : $itemStock[$j]->adjustment_qty) - ($itemStock[$j]->usage_qty == null ? 0 : $itemStock[$j]->usage_qty);

                if ($itemStock[$j]->procurement_total != null)
                {
                    // $saldoNominal += $itemStock[$j]->procurement_total;
                    $procurement_qty += $itemStock[$j]->procurement_qty;
    
                    if ($procurement_qty == 0)
                    {
                        $value = 0;
                    }
                    else
                    {
                        $value = ($saldoNominal == null ? $itemStock[$j]->procurement_total : $saldoNominal) / $procurement_qty;
                    }
                }

                if ($itemStock[$j]->sales_total != null)
                {
                    $itemStock[$j]->sales_total = $value;
                }

                if($itemStock[$j]->procurement_date != null)
                {
                    $saldoMasuk = $itemStock[$j]->procurement_qty;
                }
                else if ($itemStock[$j]->adjustment_date != null && $itemStock[$j]->adjustment_qty > 0)
                {
                    $saldoMasuk = $itemStock[$j]->adjustment_qty;
                }
                else
                {
                    $saldoMasuk = null;
                }

                if ($saldoMasuk > 0)
                {
                    $saldoNominal += $value * $saldoMasuk;
                }

                if($itemStock[$j]->sales_qty != null)
                {
                    $saldoKeluar = $itemStock[$j]->sales_qty;
                }
                else if ($itemStock[$j]->adjustment_date != null && $itemStock[$j]->adjustment_qty < 0)
                {
                    $saldoKeluar = $itemStock[$j]->adjustment_qty;
                }
                else if ($itemStock[$j]->usage_date != null)
                {
                    $saldoKeluar = $itemStock[$j]->usage_qty;
                }
                else
                {
                    $saldoKeluar = null;
                }

                if ($saldoKeluar > 0)
                {
                    $saldoNominal += $value * $saldoKeluar;
                }

            }

            $stockList[] = [
                'item_code' => $item_code,
                'item_name' => $item->name,
                'item_image' => $item->image,
                'saldo_qty' => $saldoQty,
                'saldo_nominal' => sprintf("%01.2f", $saldoNominal)
            ];
        }

        return response()->json([
            "status" => true,
            "data" => $stockList,
            "pagination" => [
                "total" => sizeof($stockList),
                "per_page" => $items->perPage(),
                "current_page" => $items->currentPage(),
                "last_page" => $items->lastPage(),
                "next_page_url" => $items->nextPageUrl(),
                "prev_page_url" => $items->previousPageUrl(),
            ]
        ]);
    }

    public function getStockCardDetails(Request $request)
    {
        $validator = Validator::make($request->all(), [
            "month_year"  => ['regex:/^(0[1-9]|1[0-2])-\d{4}$/'],
            "item_code"   => ['required']
        ]);

        if ($validator->fails()) {
            return response()->json([
                "status" => false,
                "message" => $validator->errors()
            ]);
        }

        $date = new DateTime('now');
        $filterMonth = $date->format('m-Y');

        if ($request->month_year)
        {
            $filterMonth = $request->month_year;
        }

        Config::set('database.connections.'. config('database.default') .'.strict', false);
        DB::reconnect();

        $stock = DB::select("
            SELECT
                a.item_code,
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
                a.usage_date,
                a.usage_qty,
                a.doc_number
            FROM 
                stock_value a
            WHERE
                a.item_code = ?
                AND (
                    a.procurement_date >= STR_TO_DATE(?, '%m-%Y')
                    or a.sales_date >= STR_TO_DATE(?, '%m-%Y')
                    or a.adjustment_date >= STR_TO_DATE(?, '%m-%Y')
                    or a.usage_date >= STR_TO_DATE(?, '%m-%Y')
                )
            ORDER BY a.item_code, a.created_at, a.procurement_date, a.sales_date
        ", [$request->item_code, $filterMonth, $filterMonth, $filterMonth, $filterMonth]);

        $stockAwal = DB::select("
            SELECT
                a.item_code,
                (sum(IFNULL(a.procurement_qty, 0))-sum(IFNULL(a.sales_qty,0))+sum(IFNULL(a.adjustment_qty,0))-sum(IFNULL(a.usage_qty,0))) as saldo_qty,
                sum(IFNULL(a.procurement_total, 0)) as saldo_nominal
            FROM 
                stock_value_sum a
            WHERE
                a.tx_date <= STR_TO_DATE(?, '%m-%Y')
                AND a.item_code = ?
            GROUP BY a.item_code
            ORDER BY a.item_code, a.created_at
        ", [$filterMonth, $request->item_code]);

        $result = [];

        $items = Item::where('status', 1)->where('item_code', $request->item_code)->get();

        for ($i = 0; $i < sizeof($items); $i++)
        {
            $item_code = $items[$i]->item_code;

            $item = array_values(array_filter($stockAwal, function($k) use ($item_code) {
                return $k->item_code == $item_code;
            }));

            $result[$i]['item_code'] = $item_code;
            $result[$i]['item_name'] = $items[$i]->name;
            $result[$i]['item_image'] = $items[$i]->image;

            if(sizeof($item) > 0)
            {
                $result[$i]['saldo_qty'] = $item[0]->saldo_qty;
                $result[$i]['saldo_nominal'] = $item[0]->saldo_nominal;
            }
            else
            {
                $result[$i]['saldo_qty'] = 0;
                $result[$i]['saldo_nominal'] = 0;
            }

            $item = array_values(array_filter($stock, function($k) use ($item_code) {
                return $k->item_code == $item_code;
            }));

            $saldoQty = $result[$i]['saldo_qty'];
            $saldoNominal = $result[$i]['saldo_nominal'];
            $value = 0;
            $result[$i]['items'] = [];
            $saldoMasuk = 0;

            for($j = 0; $j < sizeof($item); $j++)
            {
                $initQty = $saldoQty;
                $saldoQty = $saldoQty + ($item[$j]->procurement_qty == null ? 0 : $item[$j]->procurement_qty) - ($item[$j]->sales_qty == null ? 0 : $item[$j]->sales_qty) + ($item[$j]->adjustment_qty == null ? 0 : $item[$j]->adjustment_qty) - ($item[$j]->usage_qty == null ? 0 : $item[$j]->usage_qty);

                if ($item[$j]->procurement_total != null)
                {                    
                    $saldoNominal = (($value * $initQty) + $item[$j]->procurement_total);
                    $value = $saldoNominal / $saldoQty;
                }


                if ($item[$j]->sales_total != null)
                {
                    $item[$j]->sales_total = $value;
                }

                $adjustment_total = null;
                $usage_total = null;

                if ($item[$j]->adjustment_qty != null)
                {
                    $adjustment_total = $value;
                }

                if ($item[$j]->adjustment_qty != null)
                {
                    $usage_total = $value;
                }

                if($item[$j]->procurement_date != null)
                {
                    $saldoMasuk = $item[$j]->procurement_qty;
                }
                else if ($item[$j]->adjustment_date != null && $item[$j]->adjustment_qty > 0)
                {
                    $saldoMasuk = $item[$j]->adjustment_qty;
                }
                else
                {
                    $saldoMasuk = null;
                }

                if ($saldoMasuk > 0 && $item[$j]->procurement_total == null)
                {
                    $saldoNominal += $value * $saldoMasuk;
                }

                if($item[$j]->sales_qty != null)
                {
                    $saldoKeluar = $item[$j]->sales_qty;
                }
                else if ($item[$j]->adjustment_date != null && $item[$j]->adjustment_qty < 0)
                {
                    $saldoKeluar = $item[$j]->adjustment_qty;
                }
                else if ($item[$j]->usage_date != null)
                {
                    $saldoKeluar = $item[$j]->usage_qty;
                }
                else
                {
                    $saldoKeluar = null;
                }

                if ($saldoKeluar > 0)
                {
                    $saldoNominal -= $value * $saldoKeluar;
                }

                $result[$i]['items'][] = [
                    'transaction_date' => $item[$j]->tx_date,
                    'doc_number' => $item[$j]->doc_number,
                    'saldo_keluar' => $saldoKeluar,
                    'saldo_masuk' => $saldoMasuk,
                    'value' => sprintf("%01.2f", $value),
                    'saldo_qty' => $saldoQty,
                    'saldo_nominal' => sprintf("%01.2f", $saldoNominal),
                    'procurement_date' => $item[$j]->procurement_date,
                    'procurement_qty' => $item[$j]->procurement_qty,
                    'procurement_total' => $item[$j]->procurement_total,
                    'sales_date' => $item[$j]->sales_date,
                    'sales_qty' => $item[$j]->sales_qty,
                    'sales_total' => sprintf("%01.2f", $item[$j]->sales_total),
                    'adjustment_date' => $item[$j]->adjustment_date,
                    'adjustment_qty' => $item[$j]->adjustment_qty,
                    'adjustment_total' => sprintf("%01.2f", $adjustment_total),
                    'usage_date' => $item[$j]->usage_date,
                    'usage_qty' => $item[$j]->usage_qty,
                    'usage_total' => sprintf("%01.2f", $usage_total)
                ];
            }
        }

        return response()->json([
            "status"    => true,
            "data"      => $result
        ]);
    }
}
