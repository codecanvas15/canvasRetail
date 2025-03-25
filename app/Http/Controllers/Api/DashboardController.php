<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Procurement;
use App\Sales;
use DateTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    //
    public function monthlySummary()
    {
        $date = new DateTime('now');
        $startDate = $date->modify('first day of this Month')->format('Y-m-d');
        $endDate = $date->modify('last day of this Month')->format('Y-m-d');

        $sales = Sales::whereBetween('sales_date', [$startDate, $endDate])->where('status', 2);
        $salesSum = $sales->sum('amount');

        $procurement = Procurement::whereBetween('procurement_date', [$startDate, $endDate])->where('status', 2);
        $procurementSum = $procurement->sum('amount');

        $totalTransaction = $procurement->count() + $sales->count();

        return [
            'sales_summary' => $salesSum,
            'procurement_summary' => $procurementSum,
            'total_transaction' => $totalTransaction
        ];
    }

    public function getLatestTransaction()
    {
        $date = new DateTime('now');
        $startDate = $date->modify('first day of this Month')->format('Y-m-d');
        $endDate = $date->modify('last day of this Month')->format('Y-m-d');

        $transaction = DB::select("
            SELECT
               *
            FROM
                (
                    SELECT
                        s.doc_number,
                        s.sales_date as transaction_date,
                        c.name,
                        s.amount,
                        CASE
                            WHEN s.status = 1 THEN 'PENDING'
                            WHEN s.status = 2 THEN 'APPROVED'
                            WHEN s.status = 3 THEN 'REJECT'
                            WHEN s.status = 4 THEN 'VOID'
                        END as status
                    FROM
                        sales s
                        JOIN contacts c ON c.id = s.contact_id
                    WHERE
                        s.sales_date BETWEEN ? AND ?
                    UNION ALL
                    SELECT
                        p.doc_number,
                        p.procurement_date as transaction_date,
                        c.name,
                        p.amount,
                        CASE
                            WHEN p.status = 1 THEN 'PENDING'
                            WHEN p.status = 2 THEN 'APPROVED'
                            WHEN p.status = 3 THEN 'REJECT'
                            WHEN p.status = 4 THEN 'VOID'
                        END as status
                    FROM
                        procurements p
                        JOIN contacts c ON c.id = p.contact_id
                    WHERE
                        p.procurement_date BETWEEN ? AND ?
                ) a
            ORDER BY a.transaction_date DESC
            LIMIT 10
        ", [$startDate, $endDate, $startDate, $endDate]);

        return $transaction;
    }

    public function transactionPerMonth()
    {
        $date = new DateTime('now');
        $endDate = $date->modify('last day of this Month')->format('Y-m-d');
        $startDate = $date->modify('-6 months')->format('Y-m-d');

        $salesCounts = DB::select("
            SELECT
                DATE_FORMAT(sales_date, '%Y-%m') as month,
                COUNT(*) as transaction_count
            FROM
                sales
            WHERE
                sales_date BETWEEN ? AND ?
            GROUP BY
                DATE_FORMAT(sales_date, '%Y-%m')
            ORDER BY
                DATE_FORMAT(sales_date, '%Y-%m') ASC
        ", [$startDate, $endDate]);

        $procurementCounts = DB::select("
            SELECT
                DATE_FORMAT(procurement_date, '%Y-%m') as month,
                COUNT(*) as transaction_count
            FROM
                procurements
            WHERE
                procurement_date BETWEEN ? AND ?
            GROUP BY
                DATE_FORMAT(procurement_date, '%Y-%m')
            ORDER BY
                DATE_FORMAT(procurement_date, '%Y-%m') ASC
        ", [$startDate, $endDate]);

        return [
            'sales' => $salesCounts,
            'procurement' => $procurementCounts
        ];
    }

    public function dashboard()
    {
        $transactionSum = $this->monthlySummary();
        $monthlySum = $this->transactionPerMonth();
        $transaction = $this->getLatestTransaction();
        
        return response()->json([
            'status' => true,
            'data' => [
                'monthly_summary' => $transactionSum,
                'transaction_summary' => $monthlySum,
                'transaction' => $transaction
            ]
        ]);
    }
}
