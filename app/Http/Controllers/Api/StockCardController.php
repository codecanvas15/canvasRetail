<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Item;
use App\ItemDetail;
use DateTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class StockCardController extends Controller
{
    //
    public function getStockCard(Request $request)
    {
        $request->validate([
            "month_year"    => 'required'
        ]);

        Config::set('database.connections.'. config('database.default') .'.strict', false);
        DB::reconnect();

        $stock = DB::select(DB::raw("
            SELECT
                a.item_code,
                a.item_name,
                a.location_name,
                a.procurement_date,
                a.procurement_qty,
                a.procurement_total,
                a.sales_date,
                a.sales_qty,
                a.sales_total
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
                    sd.created_at as created_at
                FROM
                    items i
                    JOIN items_details id ON i.item_code = id.item_code and id.status = 1
                    RIGHT JOIN sales_details sd ON id.id = sd.item_detail_id and sd.status = 1
                    LEFT OUTER JOIN sales s ON sd.sales_id = s.id and s.status = 1
                    JOIN locations l ON id.location_id = l.id and l.status = 1
                WHERE
                    i.status = 1
            ) a
            WHERE
                a.procurement_date >= STR_TO_DATE('04-2024', '%m-%Y') or a.sales_date >= STR_TO_DATE('04-2024', '%m-%Y')
            ORDER BY a.item_code, a.procurement_date, a.sales_date
        "));
        // dd($stock);

        $stockAwal = DB::select(DB::raw("
            SELECT
                a.item_code,
                (sum(IFNULL(a.procurement_qty, 0))-sum(IFNULL(a.sales_qty,0))) as saldo_qty,
                (sum(IFNULL(a.procurement_total, 0))-sum(IFNULL(a.sales_total,0))) as saldo_nominal,
                ((sum(IFNULL(a.procurement_total, 0))-sum(IFNULL(a.sales_total,0)))/(sum(IFNULL(a.procurement_qty, 0))-sum(IFNULL(a.sales_qty,0)))) as value
            FROM (
                SELECT
                    i.item_code,
                    pd.qty as procurement_qty,
                    pd.total as procurement_total,
                    null as sales_qty,
                    null as sales_total,
                    p.procurement_date as created_at
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
                    s.sales_date as created_at
                FROM
                    items i
                    JOIN items_details id ON i.item_code = id.item_code and id.status = 1
                    RIGHT JOIN sales_details sd ON id.id = sd.item_detail_id and sd.status = 1
                    LEFT OUTER JOIN sales s ON sd.sales_id = s.id and s.status = 1
                    JOIN locations l ON id.location_id = l.id and l.status = 1
                WHERE
                    i.status = 1
            ) a
            WHERE
                a.created_at <= STR_TO_DATE('04-2024', '%m-%Y')
            GROUP BY a.item_code
            ORDER BY a.item_code, a.created_at
        "));
        // dd($stockAwal);

        $result = [];

        $items = Item::where('status', 1)->get();

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
                $result[$i]['saldo_qty']        = $item[0]->saldo_qty;
                $result[$i]['saldo_nominal']    = $item[0]->saldo_nominal;
                $result[$i]['value']            = $item[0]->value;
            }
            else
            {
                $result[$i]['saldo_qty']        = 0;
                $result[$i]['saldo_nominal']    = 0;
                $result[$i]['value']            = 0;
            }

            $item = array_values(array_filter($stock, function($k) use ($item_code) {
                return $k->item_code == $item_code;
            }));

            $saldoQty               = $result[$i]['saldo_qty'];
            $saldoNominal           = $result[$i]['saldo_nominal'];
            $result[$i]['items']    = [];

            for($j = 0; $j < sizeof($item); $j++)
            {
                $saldoQty = $saldoQty + ($item[$j]->procurement_qty == null ? 0 : $item[$j]->procurement_qty) - ($item[$j]->sales_qty == null ? 0 : $item[$j]->sales_qty);
                $saldoNominal = $saldoNominal + ($item[$j]->procurement_total == null ? 0 : $item[$j]->procurement_total) - ($item[$j]->sales_total == null ? 0 : $item[$j]->sales_total);
                $result[$i]['items'][$j] = $item[$j];

                $result[$i]['items'][$j]->saldo_qty = $saldoQty;
                $result[$i]['items'][$j]->saldo_nominal = $saldoNominal;
                $result[$i]['items'][$j]->value = $saldoQty == 0 ? 0 : $saldoNominal / $saldoQty;
            }
        }

        return response()->json([
            "status"    => true,
            "data"      => $result
        ]);
    }

    public function getStockCardList(Request $request)
    {
        $date = new DateTime('now');
        $filterMonth = $date->format('m-Y');

        if ($request->month_year)
        {
            $filterMonth = $request->month_year;
        }

        Config::set('database.connections.'. config('database.default') .'.strict', false);
        DB::reconnect();

        $stock = DB::select(DB::raw("
            SELECT
                a.item_code,
                a.item_name,
                a.location_name,
                a.procurement_date,
                a.procurement_qty,
                a.procurement_total,
                a.sales_date,
                a.sales_qty,
                a.sales_total
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
                    sd.created_at as created_at
                FROM
                    items i
                    JOIN items_details id ON i.item_code = id.item_code and id.status = 1
                    RIGHT JOIN sales_details sd ON id.id = sd.item_detail_id and sd.status = 1
                    LEFT OUTER JOIN sales s ON sd.sales_id = s.id and s.status = 1
                    JOIN locations l ON id.location_id = l.id and l.status = 1
                WHERE
                    i.status = 1
            ) a
            WHERE
                (a.procurement_date >= STR_TO_DATE('" . $filterMonth ."', '%m-%Y') or a.sales_date >= STR_TO_DATE('" . $filterMonth ."', '%m-%Y'))
            ORDER BY a.item_code, a.created_at, a.procurement_date, a.sales_date
        "));


        $stockAwal = DB::select(DB::raw("
            SELECT
                a.item_code,
                (sum(IFNULL(a.procurement_qty, 0))-sum(IFNULL(a.sales_qty,0))) as saldo_qty,
                (sum(IFNULL(a.procurement_total, 0))-sum(IFNULL(a.sales_total,0))) as saldo_nominal
            FROM (
                SELECT
                    i.item_code,
                    pd.qty as procurement_qty,
                    pd.total as procurement_total,
                    null as sales_qty,
                    null as sales_total,
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
            ) a
            WHERE
                a.tx_date <= STR_TO_DATE('" . $filterMonth . "', '%m-%Y')
            GROUP BY a.item_code
            ORDER BY a.item_code, a.created_at
        "));

        $stockList = [];

        $items = Item::where('status', 1)->get();

        for ($i = 0; $i < sizeof($items); $i++)
        {
            $item_code = $items[$i]->item_code;

            $item = array_values(array_filter($stockAwal, function($k) use ($item_code) {
                return $k->item_code == $item_code;
            }));

            $stockList[$i]['item_code'] = $item_code;
            $stockList[$i]['item_name'] = $items[$i]->name;
            $stockList[$i]['item_image'] = $items[$i]->image;

            $saldoQty       = 0;
            $saldoNominal   = 0;
            $value = 0;

            if(sizeof($stockAwal) > 0)
            {
                $saldoQty        = $stockAwal[0]->saldo_qty;
                $saldoNominal    = $stockAwal[0]->saldo_nominal;
            }

            $item = array_values(array_filter($stock, function($k) use ($item_code) {
                return $k->item_code == $item_code;
            }));

            for($j = 0; $j < sizeof($item); $j++)
            {
                $saldoQty = $saldoQty + ($item[$j]->procurement_qty == null ? 0 : $item[$j]->procurement_qty) - ($item[$j]->sales_qty == null ? 0 : $item[$j]->sales_qty);

                if ($j == 0)
                {
                    $saldoNominal = $saldoNominal;
                }
                else
                {
                    if ($value == 0)
                    {
                        $saldoNominal = ($item[$j]->procurement_total == null ? 0 : $item[$j]->procurement_total) - ($item[$j]->sales_total == null ? 0 : $item[$j]->sales_total);
                    }
                    else
                    {
                        $saldoNominal = $value * $saldoQty;
                    }
                }

                if ($saldoQty == 0)
                {
                    $value = 0;
                }
                else
                {
                    $value = $saldoNominal / $saldoQty;
                }

                $value = sprintf("%01.2f",$value);
            }

            $stockList[$i]['saldo_qty']        = $saldoQty;
            $stockList[$i]['saldo_nominal']    = $saldoNominal;
        }

        return response()->json([
            "status"    => true,
            "data"      => $stockList
        ]);
    }

    public function getStockCardDetails(Request $request)
    {
        $request->validate([
            "item_code"    => 'required'
        ]);

        $date = new DateTime('now');
        $filterMonth = $date->format('m-Y');

        if ($request->month_year)
        {
            $filterMonth = $request->month_year;
        }

        Config::set('database.connections.'. config('database.default') .'.strict', false);
        DB::reconnect();

        $stock = DB::select(DB::raw("
            SELECT
                a.item_code,
                a.item_name,
                a.location_name,
                a.procurement_date,
                a.procurement_qty,
                a.procurement_total,
                a.sales_date,
                a.sales_qty,
                a.sales_total
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
                    sd.created_at as created_at
                FROM
                    items i
                    JOIN items_details id ON i.item_code = id.item_code and id.status = 1
                    RIGHT JOIN sales_details sd ON id.id = sd.item_detail_id and sd.status = 1
                    LEFT OUTER JOIN sales s ON sd.sales_id = s.id and s.status = 1
                    JOIN locations l ON id.location_id = l.id and l.status = 1
                WHERE
                    i.status = 1
            ) a
            WHERE
                a.item_code = '" . $request->item_code . "'
                AND (a.procurement_date >= STR_TO_DATE('" . $filterMonth ."', '%m-%Y') or a.sales_date >= STR_TO_DATE('" . $filterMonth ."', '%m-%Y'))
            ORDER BY a.item_code, a.created_at, a.procurement_date, a.sales_date
        "));

        $stockAwal = DB::select(DB::raw("
            SELECT
                a.item_code,
                (sum(IFNULL(a.procurement_qty, 0))-sum(IFNULL(a.sales_qty,0))) as saldo_qty,
                (sum(IFNULL(a.procurement_total, 0))-sum(IFNULL(a.sales_total,0))) as saldo_nominal
            FROM (
                SELECT
                    i.item_code,
                    pd.qty as procurement_qty,
                    pd.total as procurement_total,
                    null as sales_qty,
                    null as sales_total,
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
            ) a
            WHERE
                a.tx_date <= STR_TO_DATE('" . $filterMonth . "', '%m-%Y')
                AND a.item_code = '" . $request->item_code . "'
            GROUP BY a.item_code
            ORDER BY a.item_code, a.created_at
        "));

        $result = [];

        $items = Item::where('status', 1)->where('item_code', $request->item_code)->get();

        for ($i = 0; $i < sizeof($items); $i++)
        {
            $item_code = $items[$i]->item_code;

            $result[$i]['item_code'] = $item_code;
            $result[$i]['item_name'] = $items[$i]->name;
            $result[$i]['item_image'] = $items[$i]->image;

            if(sizeof($stockAwal) > 0)
            {
                $result[$i]['saldo_qty']        = $stockAwal[0]->saldo_qty;
                $result[$i]['saldo_nominal']    = $stockAwal[0]->saldo_nominal;
            }
            else
            {
                $result[$i]['saldo_qty']        = 0;
                $result[$i]['saldo_nominal']    = 0;
            }

            $saldoQty               = $result[$i]['saldo_qty'];
            $saldoNominal           = $result[$i]['saldo_nominal'];
            $result[$i]['items']    = [];

            $value = 0;

            for($j = 0; $j < sizeof($stock); $j++)
            {
                $saldoQty = $saldoQty + ($stock[$j]->procurement_qty == null ? 0 : $stock[$j]->procurement_qty) - ($stock[$j]->sales_qty == null ? 0 : $stock[$j]->sales_qty);
                $saldoNominal = $saldoNominal + ($stock[$j]->procurement_total == null ? 0 : $stock[$j]->procurement_total) - ($stock[$j]->sales_total == null ? 0 : $stock[$j]->sales_total);
                $result[$i]['items'][$j] = $stock[$j];

                $result[$i]['items'][$j]->saldo_qty = $saldoQty;

                if ($j == 0)
                {
                    $saldoNominal = $saldoNominal;
                }
                else
                {
                    if ($value == 0)
                    {
                        $saldoNominal = ($stock[$j]->procurement_total == null ? 0 : $stock[$j]->procurement_total) - ($stock[$j]->sales_total == null ? 0 : $stock[$j]->sales_total);
                    }
                    else
                    {
                        $saldoNominal = $value * $saldoQty;
                    }
                }

                if ($saldoQty == 0)
                {
                    $value = 0;
                }
                else
                {
                    $value = $saldoNominal / $saldoQty;
                }

                $result[$i]['items'][$j]->saldo_nominal = $saldoNominal;
                $result[$i]['items'][$j]->value = sprintf("%01.2f",$value);
            }
        }

        return response()->json([
            "status"    => true,
            "data"      => $result
        ]);
    }
}
