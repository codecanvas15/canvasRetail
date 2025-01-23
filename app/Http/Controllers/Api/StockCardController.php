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
            FROM (
                SELECT
                    i.item_code,
                    i.name as item_name,
                    l.name as location_name,
                    p.procurement_date,
                    pd.qty as procurement_qty,
                    pd.price as procurement_price,
                    pd.total as procurement_total,
                    null as sales_date,
                    null as sales_qty,
                    null as sales_price,
                    null as sales_total,
                    null as adjustment_date,
                    null as adjustment_qty,
                    null as usage_date,
                    null as usage_qty,
                    pd.created_at as created_at
                FROM
                    items i
                    JOIN items_details id ON i.item_code = id.item_code and id.status = 1
                    RIGHT JOIN procurement_details pd ON id.id = pd.item_detail_id and pd.status = 1
                    LEFT OUTER JOIN procurements p ON pd.procurement_id = p.id and p.status = 1
                    JOIN locations l ON id.location_id = l.id and l.status = 1
                WHERE
                    i.status = 1
                UNION ALL
                SELECT
                    i.item_code,
                    i.name as item_name,
                    l.name as location_name,
                    null procurement_date,
                    null as procurement_qty,
                    null as procurement_price,
                    null as procurement_total,
                    s.sales_date,
                    sd.qty as sales_qty,
                    sd.price as sales_price,
                    sd.total as sales_total,
                    null as adjustment_date,
                    null as adjustment_qty,
                    null as usage_date,
                    null as usage_qty,
                    sd.created_at as created_at
                FROM
                    items i
                    JOIN items_details id ON i.item_code = id.item_code and id.status = 1
                    RIGHT JOIN sales_details sd ON id.id = sd.item_detail_id and sd.status = 1
                    LEFT OUTER JOIN sales s ON sd.sales_id = s.id and s.status = 1
                    JOIN locations l ON id.location_id = l.id and l.status = 1
                WHERE
                    i.status = 1
                UNION ALL
                SELECT
                    i.item_code,
                    i.name as item_name,
                    l.name as location_name,
                    null as procurement_date,
                    null as procurement_qty,
                    null as procurement_price,
                    null as procurement_total,
                    null as sales_date,
                    null as sales_qty,
                    null as sales_price,
                    null as sales_total,
                    sa.transaction_date as adjustment_date,
                    sa.qty as adjustment_qty,
                    null as usage_date,
                    null as usage_qty,
                    sa.created_at as created_at
                FROM
                    items i
                    JOIN items_details id ON i.item_code = id.item_code and id.status = 1
                    RIGHT JOIN stock_adjustment sa ON id.id = sa.item_detail_id and sa.status = 1
                    JOIN locations l ON id.location_id = l.id and l.status = 1
                WHERE
                    i.status = 1
                UNION ALL
                SELECT
                    i.item_code,
                    i.name as item_name,
                    l.name as location_name,
                    null as procurement_date,
                    null as procurement_qty,
                    null as procurement_price,
                    null as procurement_total,
                    null as sales_date,
                    null as sales_qty,
                    null as sales_price,
                    null as sales_total,
                    null as adjustment_date,
                    null as adjustment_qty,
                    su.transaction_date as usage_date,
                    su.qty as usage_qty,
                    su.created_at as created_at
                FROM
                    items i
                    JOIN items_details id ON i.item_code = id.item_code and id.status = 1
                    RIGHT JOIN stock_usage su ON id.id = su.item_detail_id and su.status = 1
                    JOIN locations l ON id.location_id = l.id and l.status = 1
                WHERE
                    i.status = 1
                ) a
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
                (sum(IFNULL(a.procurement_qty, 0))-sum(IFNULL(a.sales_qty,0))+sum(IFNULL(a.adjustment_qty,0))-sum(IFNULL(a.usage_qty,0))) as saldo_qty,
                (sum(IFNULL(a.procurement_total, 0))-sum(IFNULL(a.sales_total,0))) as saldo_nominal
            FROM (
                SELECT
                    i.item_code,
                    pd.qty as procurement_qty,
                    pd.total as procurement_total,
                    null as sales_qty,
                    null as sales_total,
                    null as adjustment_qty,
                    null as usage_qty,
                    p.procurement_date as tx_date,
                    p.created_at as created_at
                FROM
                    items i
                    JOIN items_details id ON i.item_code = id.item_code and id.status = 1
                    RIGHT JOIN procurement_details pd ON id.id = pd.item_detail_id and pd.status = 1
                    LEFT OUTER JOIN procurements p ON pd.procurement_id = p.id and p.status = 1
                    JOIN locations l ON id.location_id = l.id and l.status = 1
                WHERE
                    i.status = 1
                UNION ALL
                SELECT
                    i.item_code,
                    null as procurement_qty,
                    null as procurement_total,
                    sd.qty as sales_qty,
                    sd.total as sales_total,
                    null as adjustment_qty,
                    null as usage_qty,
                    s.sales_date as tx_date,
                    s.created_at created_at
                FROM
                    items i
                    JOIN items_details id ON i.item_code = id.item_code and id.status = 1
                    RIGHT JOIN sales_details sd ON id.id = sd.item_detail_id and sd.status = 1
                    LEFT OUTER JOIN sales s ON sd.sales_id = s.id and s.status = 1
                    JOIN locations l ON id.location_id = l.id and l.status = 1
                WHERE
                    i.status = 1
                UNION ALL
                SELECT
                    i.item_code,
                    null as procurement_qty,
                    null as procurement_total,
                    null as sales_qty,
                    null as sales_total,
                    sa.qty as adjustment_qty,
                    null as usage_qty,
                    sa.transaction_date as tx_date,
                    sa.created_at as created_at
                FROM
                    items i
                    JOIN items_details id ON i.item_code = id.item_code and id.status = 1
                    RIGHT JOIN stock_adjustment sa ON id.id = sa.item_detail_id and sa.status = 1
                    JOIN locations l ON id.location_id = l.id and l.status = 1
                WHERE
                    i.status = 1
                UNION ALL
                SELECT
                    i.item_code,
                    null as procurement_qty,
                    null as procurement_total,
                    null as sales_qty,
                    null as sales_total,
                    null as adjustment_qty,
                    su.qty as usage_qty,
                    su.transaction_date as tx_date,
                    su.created_at as created_at
                FROM
                    items i
                    JOIN items_details id ON i.item_code = id.item_code and id.status = 1
                    RIGHT JOIN stock_usage su ON id.id = su.item_detail_id and su.status = 1
                    JOIN locations l ON id.location_id = l.id and l.status = 1
                WHERE
                    i.status = 1
            ) a
            WHERE
                a.tx_date <= STR_TO_DATE(?, '%m-%Y')
            GROUP BY a.item_code
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

            $saldoQty       = 0;
            $saldoNominal   = 0;
            $value = 0;

            if(sizeof($itemStockAwal) > 0)
            {
                $saldoQty        = $itemStockAwal[0]->saldo_qty;
                $saldoNominal    = $itemStockAwal[0]->saldo_nominal;
            }

            $itemStock = array_values(array_filter($stock, function($k) use ($item_code) {
                return $k->item_code == $item_code;
            }));

            for($j = 0; $j < sizeof($itemStock); $j++)
            {
                $saldoQty = $saldoQty + ($itemStock[$j]->procurement_qty == null ? 0 : $itemStock[$j]->procurement_qty) - ($itemStock[$j]->sales_qty == null ? 0 : $itemStock[$j]->sales_qty) + ($itemStock[$j]->adjustment_qty == null ? 0 : $itemStock[$j]->adjustment_qty) - ($itemStock[$j]->usage_qty == null ? 0 : $itemStock[$j]->usage_qty);

                if ($value == 0)
                {
                    $saldoNominal = ($itemStock[$j]->procurement_total == null ? 0 : $itemStock[$j]->procurement_total) - ($itemStock[$j]->sales_total == null ? 0 : $itemStock[$j]->sales_total);
                }
                else
                {
                    $saldoNominal = $value * $saldoQty;
                }

                if ($saldoQty == 0)
                {
                    $value = 0;
                }
                else
                {
                    $value = $saldoNominal / $saldoQty;
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
                "total" => $items->total(),
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
            FROM (
                SELECT
                    i.item_code,
                    i.name as item_name,
                    l.name as location_name,
                    p.procurement_date,
                    pd.qty as procurement_qty,
                    pd.price as procurement_price,
                    pd.total as procurement_total,
                    null as sales_date,
                    null as sales_qty,
                    null as sales_price,
                    null as sales_total,
                    null as adjustment_qty,
                    null as adjustment_date,
                    null as usage_qty,
                    null as usage_date,
                    pd.created_at as created_at
                FROM
                    items i
                    JOIN items_details id ON i.item_code = id.item_code and id.status = 1
                    RIGHT JOIN procurement_details pd ON id.id = pd.item_detail_id and pd.status = 1
                    LEFT OUTER JOIN procurements p ON pd.procurement_id = p.id and p.status = 1
                    JOIN locations l ON id.location_id = l.id and l.status = 1
                WHERE
                    i.status = 1
                UNION ALL
                SELECT
                    i.item_code,
                    i.name as item_name,
                    l.name as location_name,
                    null procurement_date,
                    null as procurement_qty,
                    null as procurement_price,
                    null as procurement_total,
                    s.sales_date,
                    sd.qty as sales_qty,
                    sd.price as sales_price,
                    sd.total as sales_total,
                    null as adjustment_qty,
                    null as adjustment_date,
                    null as usage_qty,
                    null as usage_date,
                    sd.created_at as created_at
                FROM
                    items i
                    JOIN items_details id ON i.item_code = id.item_code and id.status = 1
                    RIGHT JOIN sales_details sd ON id.id = sd.item_detail_id and sd.status = 1
                    LEFT OUTER JOIN sales s ON sd.sales_id = s.id and s.status = 1
                    JOIN locations l ON id.location_id = l.id and l.status = 1
                WHERE
                    i.status = 1
                UNION ALL
                SELECT
                    i.item_code,
                    i.name as item_name,
                    l.name as location_name,
                    null procurement_date,
                    null as procurement_qty,
                    null as procurement_price,
                    null as procurement_total,
                    null as sales_date,
                    null as sales_qty,
                    null as sales_price,
                    null as sales_total,
                    sa.qty as adjustment_qty,
                    sa.transaction_date as adjustment_date,
                    null as usage_qty,
                    null as usage_date,
                    sa.created_at as created_at
                FROM
                    items i
                    JOIN items_details id ON i.item_code = id.item_code and id.status = 1
                    RIGHT JOIN stock_adjustment sa ON id.id = sa.item_detail_id and sa.status = 1
                    JOIN locations l ON id.location_id = l.id and l.status = 1
                WHERE
                    i.status = 1
                UNION ALL
                SELECT
                    i.item_code,
                    i.name as item_name,
                    l.name as location_name,
                    null procurement_date,
                    null as procurement_qty,
                    null as procurement_price,
                    null as procurement_total,
                    null as sales_date,
                    null as sales_qty,
                    null as sales_price,
                    null as sales_total,
                    null as adjustment_qty,
                    null as adjustment_date,
                    su.qty as usage_qty,
                    su.transaction_date as usage_date,
                    su.created_at as created_at
                FROM
                    items i
                    JOIN items_details id ON i.item_code = id.item_code and id.status = 1
                    RIGHT JOIN stock_usage su ON id.id = su.item_detail_id and su.status = 1
                    JOIN locations l ON id.location_id = l.id and l.status = 1
                WHERE
                    i.status = 1
            ) a
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
                (sum(IFNULL(a.procurement_total, 0))-sum(IFNULL(a.sales_total,0))) as saldo_nominal
            FROM (
                SELECT
                    i.item_code,
                    pd.qty as procurement_qty,
                    pd.total as procurement_total,
                    null as sales_qty,
                    null as sales_total,
                    p.procurement_date as tx_date,
                    p.created_at as created_at,
                    null as adjustment_qty,
                    null as usage_qty
                FROM
                    items i
                    JOIN items_details id ON i.item_code = id.item_code and id.status = 1
                    RIGHT JOIN procurement_details pd ON id.id = pd.item_detail_id and pd.status = 1
                    LEFT OUTER JOIN procurements p ON pd.procurement_id = p.id and p.status = 1
                    JOIN locations l ON id.location_id = l.id and l.status = 1
                WHERE
                    i.status = 1
                UNION ALL
                SELECT
                    i.item_code,
                    null as procurement_qty,
                    null as procurement_total,
                    sd.qty as sales_qty,
                    sd.total as sales_total,
                    s.sales_date as tx_date,
                    s.created_at created_at,
                    null as adjustment_qty,
                    null as usage_qty
                FROM
                    items i
                    JOIN items_details id ON i.item_code = id.item_code and id.status = 1
                    RIGHT JOIN sales_details sd ON id.id = sd.item_detail_id and sd.status = 1
                    LEFT OUTER JOIN sales s ON sd.sales_id = s.id and s.status = 1
                    JOIN locations l ON id.location_id = l.id and l.status = 1
                WHERE
                    i.status = 1
                UNION ALL
                SELECT
                    i.item_code,
                    null as procurement_qty,
                    null as procurement_total,
                    null as sales_qty,
                    null as sales_total,
                    sa.qty as adjustment_qty,
                    null as usage_qty,
                    sa.transaction_date as tx_date,
                    sa.created_at as created_at
                FROM
                    items i
                    JOIN items_details id ON i.item_code = id.item_code and id.status = 1
                    RIGHT JOIN stock_adjustment sa ON id.id = sa.item_detail_id and sa.status = 1
                    JOIN locations l ON id.location_id = l.id and l.status = 1
                WHERE
                    i.status = 1
                UNION ALL
                SELECT
                    i.item_code,
                    null as procurement_qty,
                    null as procurement_total,
                    null as sales_qty,
                    null as sales_total,
                    null as adjustment_qty,
                    su.qty as usage_qty,
                    su.transaction_date as tx_date,
                    su.created_at as created_at
                FROM
                    items i
                    JOIN items_details id ON i.item_code = id.item_code and id.status = 1
                    RIGHT JOIN stock_usage su ON id.id = su.item_detail_id and su.status = 1
                    JOIN locations l ON id.location_id = l.id and l.status = 1
                WHERE
                    i.status = 1
            ) a
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

            for($j = 0; $j < sizeof($item); $j++)
            {
                $saldoQty = $saldoQty + ($item[$j]->procurement_qty == null ? 0 : $item[$j]->procurement_qty) - ($item[$j]->sales_qty == null ? 0 : $item[$j]->sales_qty) + ($item[$j]->adjustment_qty == null ? 0 : $item[$j]->adjustment_qty) - ($item[$j]->usage_qty == null ? 0 : $item[$j]->usage_qty);

                if ($value == 0)
                {
                    $saldoNominal = ($item[$j]->procurement_total == null ? 0 : $item[$j]->procurement_total) - ($item[$j]->sales_total == null ? 0 : $item[$j]->sales_total);
                }
                else
                {
                    $saldoNominal = $value * $saldoQty;
                }

                if ($saldoQty == 0)
                {
                    $value = 0;
                }
                else
                {
                    $value = $saldoNominal / $saldoQty;
                }

                $result[$i]['items'][] = [
                    'procurement_date' => $item[$j]->procurement_date,
                    'procurement_qty' => $item[$j]->procurement_qty,
                    'procurement_total' => $item[$j]->procurement_total,
                    'sales_date' => $item[$j]->sales_date,
                    'sales_qty' => $item[$j]->sales_qty,
                    'sales_total' => $item[$j]->sales_total,
                    'adjustment_date' => $item[$j]->adjustment_date,
                    'adjustment_qty' => $item[$j]->adjustment_qty,
                    'usage_date' => $item[$j]->usage_date,
                    'usage_qty' => $item[$j]->usage_qty,
                    'saldo_qty' => $saldoQty,
                    'saldo_nominal' => sprintf("%01.2f", $saldoNominal),
                    'value' => $value
                ];
            }
        }

        return response()->json([
            "status"    => true,
            "data"      => $result
        ]);
    }
}
