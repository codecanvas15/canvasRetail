<?php

namespace App\Jobs;

use App\Item;
use App\Location;
use App\ReportQueue;
use App\Tax;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class GenerateReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $queueId;

    /**
     * Create a new job instance.
     */
    public function __construct($queueId)
    {
        $this->queueId = $queueId;
    }

    /**
     * Execute the job.
     */
    public function handle()
    {
        $queue = ReportQueue::find($this->queueId);
        if (!$queue || $queue->status != 1) {
            return;
        }

        try {
            if ($queue->type == 'procurement') {
                Log::channel('report')->info('Generating Procurement Report');
                $this->procurementReport($queue);
            } else if ($queue->type == 'sales') {
                Log::channel('report')->info('Generating Sales Report');
                $this->salesReport($queue);
            } else if ($queue->type == 'stockcard') {
                Log::channel('report')->info('Generating Stockcard Report');
                $this->stockCardReport($queue);
            } else if ($queue->type == 'stockvalue') {
                Log::channel('report')->info('Generating Stockvalue Report');
                $this->stockValueReport($queue);
            }
        } catch (\Throwable $e) {
            $errorMessage = now() . ' ' . $e->getMessage();
            Log::channel('report')->error($errorMessage);
            Log::channel('report')->error($e->getTraceAsString());
            // $queue->update(['status' => 3, 'file' => null]);

            Log::error('Job failed: ' . $e->getMessage());
            Log::error($e->getTraceAsString());
        }
    }

    public function procurementReport($queue)
    {
        $startProcurementDate = $queue->start_date;
        $endProcurementDate = $queue->end_date;
        $locationId = $queue->location_id;

        $query = DB::table('procurements')
            ->join('procurement_details', 'procurements.id', '=', 'procurement_details.procurement_id')
            ->join('items_details', 'procurement_details.item_detail_id', '=', 'items_details.id')
            ->join('items', 'items_details.item_code', '=', 'items.item_code')
            ->join('contacts', 'procurements.contact_id', '=', 'contacts.id')
            ->join('locations', 'items_details.location_id', '=', 'locations.id')
            ->select('contacts.name as vendor', 'items.name as item_name', 'items.item_code', 'procurements.procurement_date', 'procurements.doc_number', 'procurements.external_doc_no', 'procurement_details.qty', 'procurement_details.price', 'procurement_details.total', 'procurement_details.tax_ids', 'procurement_details.discount', 'locations.name as location', 'procurements.include_tax', 'procurement_details.initial_price', 'procurements.rounding')
            ->where('procurements.status', 2)
            ->whereBetween('procurements.procurement_date', [$startProcurementDate, $endProcurementDate]);


        if ($locationId != null)
        {
            $query->where('procurements.location_id', $locationId);
        }

        $report = $query->get();

        foreach($report as $key => $value)
        {
            $value->procurement_date = (new \DateTime($value->procurement_date))->format('d-m-Y');

            $tax = explode(',', $value->tax_ids);
            $report[$key]->ppn = 0;
            $report[$key]->other_tax = 0;

            $discounts = explode('|', $value->discount);
            $totalDiscount = 0;
            $priceAfterDiscount = $value->initial_price;

            foreach ($discounts as $discount)
            {
                $discount = $discount ?? 0 ? ($discount/100) * $priceAfterDiscount : 0;
                $totalDiscount += $discount;
                
                $priceAfterDiscount = $priceAfterDiscount - $discount;
            }

            $report[$key]->discount = $totalDiscount * $value->qty;

            $sumTax = Tax::whereIn('id', $tax)->sum('value');
            $taxes = Tax::whereIn('id', $tax)->get();

            if ($value->include_tax)
            {
                $itemPrice = round($priceAfterDiscount / (1 + $sumTax/100),2);
            }
            else
            {
                $itemPrice = round($priceAfterDiscount, 2);
            }
            
            foreach ($taxes as $tax) 
            {
                if ($tax->name == 'ppn')
                {
                    $report[$key]->ppn += round($itemPrice * ($tax->value / 100), 2);
                }
                else
                {
                    $report[$key]->other_tax += round($itemPrice * ($tax->value / 100), 2);
                }
            }
            
            $report[$key]->total = round(($itemPrice + $report[$key]->ppn + $report[$key]->other_tax) * $value->qty, 2);
        }

        $startProcurementDateFormatted = (new \DateTime($startProcurementDate))->format('d-m-Y');
        $endProcurementDateFormatted = (new \DateTime($endProcurementDate))->format('d-m-Y');

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // header docs
        $sheet->setCellValue('A1', "PT Purnama Jaya Teknik\nRuko Satelit Town Square Blok A 09-10 Surabaya\nTelp. 08155110111 | Fax. 08155110111");
        $sheet->getStyle('A1')->getAlignment()->setWrapText(true);
        $sheet->getStyle('A1')->getFont()->setBold(true);

        $sheet->mergeCells('A3:L3');
        $sheet->setCellValue('A3', 'LAPORAN PEMBELIAN');
        $sheet->getStyle('A3')->getFont()->setBold(true);
        $sheet->getStyle('A3')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        $sheet->mergeCells('A4:L4');
        $sheet->setCellValue('A4', 'TANGGAL : ' . $startProcurementDateFormatted . '/' . $endProcurementDateFormatted);
        $sheet->getStyle('A4')->getFont()->setBold(true);
        $sheet->getStyle('A4')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        // header row
        $sheet->setCellValue('A6', 'Vendor');
        $sheet->getColumnDimension('A')->setWidth(33);
        $sheet->setCellValue('B6', 'Item');
        $sheet->getColumnDimension('B')->setWidth(33);
        $sheet->setCellValue('C6', 'Kode Item');
        $sheet->getColumnDimension('C')->setWidth(33);
        $sheet->setCellValue('D6', 'Tanggal');
        $sheet->getColumnDimension('D')->setWidth(12);
        $sheet->setCellValue('E6', 'Kode');
        $sheet->getColumnDimension('E')->setWidth(22);
        $sheet->setCellValue('F6', 'Qty');
        $sheet->getColumnDimension('F')->setWidth(8);
        $sheet->setCellValue('G6', 'Unit');
        $sheet->getColumnDimension('G')->setWidth(10);
        $sheet->setCellValue('H6', 'Disc');
        $sheet->getColumnDimension('H')->setWidth(12);
        $sheet->setCellValue('I6', 'PPN');
        $sheet->getColumnDimension('I')->setWidth(12);
        $sheet->setCellValue('J6', 'Other Tax');
        $sheet->getColumnDimension('J')->setWidth(12);
        $sheet->setCellValue('K6', 'Harga');
        $sheet->getColumnDimension('K')->setWidth(20);
        $sheet->setCellValue('L6', 'Total');
        $sheet->getColumnDimension('L')->setWidth(20);

        // Apply styling to header row
        $sheet->getStyle('A6:L6')->getFont()->setBold(true);
        $sheet->getStyle('A6:L6')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT);

        $row = 7;
        $total = 0;
        foreach ($report as $value) {
            $value->procurement_date = (new \DateTime($value->procurement_date))->format('d-m-Y');

            $sheet->setCellValue('A' . $row, $value->vendor);
            $sheet->setCellValue('B' . $row, $value->item_name);
            $sheet->setCellValue('C' . $row, $value->item_code);
            $sheet->setCellValue('D' . $row, $value->procurement_date);
            $sheet->setCellValue('E' . $row, $value->doc_number);
            $sheet->setCellValue('F' . $row, $value->qty);
            // $sheet->setCellValue('G' . $row, $value->price);
            $sheet->setCellValue('H' . $row, $value->discount);
            $sheet->getStyle('H' . $row)->getNumberFormat()->setFormatCode('_-"Rp"* #,##0.00_-;-"Rp"* #,##0.00_-;_-"Rp"* "-"??_-;_-@_-');
            $sheet->setCellValue('I' . $row, $value->ppn);
            $sheet->getStyle('I' . $row)->getNumberFormat()->setFormatCode('_-"Rp"* #,##0.00_-;-"Rp"* #,##0.00_-;_-"Rp"* "-"??_-;_-@_-');
            $sheet->setCellValue('J' . $row, $value->other_tax);
            $sheet->getStyle('J' . $row)->getNumberFormat()->setFormatCode('_-"Rp"* #,##0.00_-;-"Rp"* #,##0.00_-;_-"Rp"* "-"??_-;_-@_-');
            $sheet->setCellValue('K' . $row, $value->price);
            $sheet->getStyle('K' . $row)->getNumberFormat()->setFormatCode('_-"Rp"* #,##0.00_-;-"Rp"* #,##0.00_-;_-"Rp"* "-"??_-;_-@_-');
            $sheet->setCellValue('L' . $row, $value->total);
            $sheet->getStyle('L' . $row)->getNumberFormat()->setFormatCode('_-"Rp"* #,##0.00_-;-"Rp"* #,##0.00_-;_-"Rp"* "-"??_-;_-@_-');

            $total += $value->total;
            $row++;
        }

        // Apply styling to total row
        $sheet->mergeCells('A' . $row . ':K' . $row);
        $sheet->setCellValue('A' . $row, 'Total');
        $sheet->setCellValue('L' . $row, $total);
        $sheet->getStyle('L' . $row)->getNumberFormat()->setFormatCode('_-"Rp"* #,##0.00_-;-"Rp"* #,##0.00_-;_-"Rp"* "-"??_-;_-@_-');

        $directory = public_path('report/procurement');
        if (!File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        $filename = 'procurement_report_' . $startProcurementDateFormatted . '_' . $endProcurementDateFormatted . '_' . $queue->id . '.xlsx';
        $filePath = $directory . '/' . $filename;

        // Save the spreadsheet to a file
        $writer = new Xlsx($spreadsheet);
        $writer->save($filePath);

        $queue->update([
            'status' => 2,
            'file' => url('report/procurement/' . $filename)
        ]);

        Log::channel('report')->info(' ' . url('report/procurement/' . $filename));

        return true;
    }

    public function salesReport($queue)
    {
        $queue = ReportQueue::where('status',1)->first();

        $startSalesDate = $queue->start_date;
        $endSalesDate = $queue->end_date;
        $locationId = $queue->location_id;

        $query = DB::table('sales')
            ->join('sales_details', 'sales.id', '=', 'sales_details.sales_id')
            ->join('items_details', 'sales_details.item_detail_id', '=', 'items_details.id')
            ->join('items', 'items_details.item_code', '=', 'items.item_code')
            ->join('contacts', 'sales.contact_id', '=', 'contacts.id')
            ->join('locations', 'items_details.location_id', '=', 'locations.id')
            ->select('contacts.name as customer', 'items.name as item_name', 'items.item_code', 'sales.sales_date', 'sales.doc_number', 'sales_details.qty', 'sales_details.price', 'sales_details.total', 'sales_details.tax_ids', 'sales_details.discount', 'locations.name as location', 'sales_details.initial_price', 'sales.rounding')
            ->where('sales.status', 2)
            ->whereBetween('sales.sales_date', [$startSalesDate, $endSalesDate]);
        
        if ($locationId != null)
        {
            $query->where('sales.location_id', $locationId);
        }

        $report = $query->get();
            
        foreach($report as $key => $value)
        {
            $value->sales_date = (new \DateTime($value->sales_date))->format('d-m-Y');

            $tax = explode(',', $value->tax_ids);
            $report[$key]->ppn = 0;
            $report[$key]->other_tax = 0;

            $taxes = Tax::whereIn('id', $tax)->get();
            
            foreach ($taxes as $tax) 
            {
                if ($tax->name == 'ppn')
                {
                    $report[$key]->ppn += round($value->price * ($tax->value / 100), 2);
                }
                else
                {
                    $report[$key]->other_tax += round($value->price * ($tax->value / 100), 2);
                }
            }

            $report[$key]->subtotal = round($value->price * $value->qty, 2);
            $report[$key]->total = round(($value->price + $report[$key]->ppn + $report[$key]->other_tax) * $value->qty, 2);
        }

        $startSalesDateFormatted = (new \DateTime($startSalesDate))->format('d-m-Y');
        $endSalesDateFormatted = (new \DateTime($endSalesDate))->format('d-m-Y');

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $sheet->setCellValue('A1', "PT Purnama Jaya Teknik\nRuko Satelit Town Square Blok A 09-10 Surabaya\nTelp. 08155110111 | Fax. 08155110111");
        $sheet->getStyle('A1')->getAlignment()->setWrapText(true);
        $sheet->getStyle('A1')->getFont()->setBold(true);

        $sheet->mergeCells('A3:L3');
        $sheet->setCellValue('A3', 'LAPORAN PENJUALAN');
        $sheet->getStyle('A3')->getFont()->setBold(true);
        $sheet->getStyle('A3')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        $sheet->mergeCells('A4:L4');
        $sheet->setCellValue('A4', 'TANGGAL : ' . $startSalesDateFormatted . '/' . $endSalesDateFormatted);
        $sheet->getStyle('A4')->getFont()->setBold(true);
        $sheet->getStyle('A4')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        // header row
        $sheet->setCellValue('A6', 'No');
        $sheet->getColumnDimension('A')->setWidth(5);
        $sheet->setCellValue('B6', 'Customer');
        $sheet->getColumnDimension('B')->setWidth(33);
        $sheet->setCellValue('C6', 'Tanggal Faktur');
        $sheet->getColumnDimension('C')->setWidth(15);
        $sheet->setCellValue('D6', 'Nomor Faktur');
        $sheet->getColumnDimension('D')->setWidth(20);
        $sheet->setCellValue('E6', 'Nama Barang');
        $sheet->getColumnDimension('E')->setWidth(40);
        $sheet->setCellValue('F6', 'Jumlah');
        $sheet->getColumnDimension('F')->setWidth(10);
        $sheet->setCellValue('G6', 'Satuan');
        $sheet->getColumnDimension('G')->setWidth(10);
        $sheet->setCellValue('H6', 'Harga');
        $sheet->getColumnDimension('H')->setWidth(15);
        $sheet->setCellValue('I6', 'Subtotal');
        $sheet->getColumnDimension('I')->setWidth(15);
        $sheet->setCellValue('J6', 'PPN');
        $sheet->getColumnDimension('J')->setWidth(15);
        $sheet->setCellValue('K6', 'Total Pajak Lain');
        $sheet->getColumnDimension('K')->setWidth(20);
        $sheet->setCellValue('L6', 'Total');
        $sheet->getColumnDimension('L')->setWidth(20);

        // Apply styling to header row
        $sheet->getStyle('A6:L6')->getFont()->setBold(true);
        $sheet->getStyle('A6:L6')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT);

        $row = 7;
        $total = 0;
        foreach ($report as $value) {
            $sheet->setCellValue('A' . $row, $row - 6);
            $sheet->setCellValue('B' . $row, $value->customer);
            $sheet->setCellValue('C' . $row, $value->sales_date);
            $sheet->setCellValue('D' . $row, $value->doc_number);
            $sheet->setCellValue('E' . $row, $value->item_name);
            $sheet->setCellValue('F' . $row, $value->qty);
            $sheet->setCellValue('H' . $row, $value->price);
            $sheet->getStyle('H' . $row)->getNumberFormat()->setFormatCode('_-"Rp"* #,##0.00_-;-"Rp"* #,##0.00_-;_-"Rp"* "-"??_-;_-@_-');
            $sheet->setCellValue('I' . $row, $value->subtotal);
            $sheet->getStyle('I' . $row)->getNumberFormat()->setFormatCode('_-"Rp"* #,##0.00_-;-"Rp"* #,##0.00_-;_-"Rp"* "-"??_-;_-@_-');
            $sheet->setCellValue('J' . $row, $value->ppn);
            $sheet->getStyle('J' . $row)->getNumberFormat()->setFormatCode('_-"Rp"* #,##0.00_-;-"Rp"* #,##0.00_-;_-"Rp"* "-"??_-;_-@_-');
            $sheet->setCellValue('K' . $row, $value->other_tax);
            $sheet->getStyle('K' . $row)->getNumberFormat()->setFormatCode('_-"Rp"* #,##0.00_-;-"Rp"* #,##0.00_-;_-"Rp"* "-"??_-;_-@_-');
            $sheet->setCellValue('L' . $row, $value->total);
            $sheet->getStyle('L' . $row)->getNumberFormat()->setFormatCode('_-"Rp"* #,##0.00_-;-"Rp"* #,##0.00_-;_-"Rp"* "-"??_-;_-@_-');

            $total += $value->total;
            $row++;
        }

        $sheet->mergeCells('A' . $row . ':K' . $row);
        $sheet->setCellValue('A' . $row, 'Total');
        $sheet->setCellValue('L' . $row, $total);
        $sheet->getStyle('L' . $row)->getNumberFormat()->setFormatCode('_-"Rp"* #,##0.00_-;-"Rp"* #,##0.00_-;_-"Rp"* "-"??_-;_-@_-');

        $directory = public_path('report/sales');
        if (!File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        $filename = 'sales_report_' . $startSalesDateFormatted . '_' . $endSalesDateFormatted . '_' . $queue->id . '.xlsx';
        $filePath = $directory . '/' . $filename;

        // Save the spreadsheet to a file
        $writer = new Xlsx($spreadsheet);
        $writer->save($filePath);

        $queue->update([
            'status' => 2,
            'file' => url('report/sales/' . $filename)
        ]);

        Log::channel('report')->info(' ' . url('report/sales/' . $filename));

        return true;
    }

    public function stockCardReport($queue)
    {
        $queue = ReportQueue::where('status',1)->first();

        $startDate = $queue->start_date;
        $endDate = $queue->end_date;

        $locationCondition = '';
        $locationParams = [];
        if ($queue->location_id != null) 
        {
            $locationCondition = 'AND a.location_id = ?';
            $locationParams[] = $queue->location_id;
        }

        $item = Item::where('status', 1)->get();

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
                a.doc_number,
                a.location_id,
                l.name as location_name,
                a.created_at
            FROM 
                stock_value a
                JOIN locations l ON a.location_id = l.id
            WHERE
                (
                    a.procurement_date >= ?
                    or a.sales_date >= ?
                    or a.adjustment_date >= ?
                    or a.usage_date >= ?
                )
                AND (
                    a.procurement_date <= ?
                    or a.sales_date <= ?
                    or a.adjustment_date <= ?
                    or a.usage_date <= ?
                )
                $locationCondition
            ORDER BY a.item_code, a.created_at, a.procurement_date, a.sales_date
        ", array_merge([$startDate, $startDate, $startDate, $startDate, $endDate, $endDate, $endDate, $endDate], $locationParams));

        $stockAwal = DB::select("
            SELECT
                a.item_code,
                COALESCE(a.procurement_qty, a.sales_qty * -1, a.adjustment_qty, a.usage_qty * -1) as saldo_qty,
                a.location_id
            FROM 
                stock_value_sum a
            WHERE
                a.tx_date < ?
                $locationCondition
            ORDER BY a.item_code, a.created_at
        ", array_merge([$startDate], $locationParams));

        $stockAwal = collect($stockAwal)->groupBy('item_code');
        $stock = collect($stock)->groupBy('item_code');
        
        $stockCard = [];

        foreach ($item as $value) 
        {
            $stockCard[$value->item_code] = [
                'item_name' => $value->name,
                'item_code' => $value->item_code,
                'location' => []
            ];
            
            $locations = Location::where('status', 1)->get();
            
            foreach ($locations as $location) 
            {
                $stockItem = $stock->get($value->item_code);
                $stockItemLoc = $stockItem ? $stockItem->where('location_id', $location->id) : collect();

                $stockInitial = $stockAwal->get($value->item_code) ? $stockAwal->get($value->item_code)->where('location_id', $location->id)->sum('saldo_qty') : 0;

                $stockCard[$value->item_code]['location'][$location->id] = [
                    'location_name' => $location->name,
                    'stock' => $stockItemLoc,
                    'stockAwal' => $stockInitial
                ];
            }
        }
        
        $startDateFormated = (new \DateTime($startDate))->format('d-m-Y');
        $endDateFormated = (new \DateTime($endDate))->format('d-m-Y');

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', "PT Purnama Jaya Teknik\nRuko Satelit Town Square Blok A 09-10 Surabaya\nTelp. 08155110111 | Fax. 08155110111");
        $sheet->getStyle('A1')->getAlignment()->setWrapText(true);
        $sheet->getStyle('A1')->getFont()->setBold(true);

        $sheet->mergeCells('A3:L3');
        $sheet->setCellValue('A3', 'STOCK CARD REPORT');
        $sheet->getStyle('A3')->getFont()->setBold(true);
        $sheet->getStyle('A3')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        $sheet->mergeCells('A4:L4');
        $sheet->setCellValue('A4', 'TANGGAL : ' . $startDateFormated . '/' . $endDateFormated);
        $sheet->getStyle('A4')->getFont()->setBold(true);
        $sheet->getStyle('A4')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        $row = 6;
        foreach($stockCard as $item)
        {
            foreach($item['location'] as $location)
            {
                $sheet->setCellValue('A' . $row, 'Item: ' . $item['item_name'] . '(' . $item['item_code'] . ')');
                $sheet->getStyle('A' . $row)->getFont()->setBold(true);
                $row++;
                $sheet->setCellValue('A'.$row, 'Gudang: ' . $location['location_name']);
                $sheet->getStyle('A' . $row)->getFont()->setBold(true);
                $row += 2;

                // set Header per item per location
                $sheet->setCellValue('A' . $row, 'Tanggal Dokumen');
                $sheet->getStyle('A' . $row)->getFont()->setBold(true);
                $sheet->setCellValue('B' . $row, 'Tanggal');
                $sheet->getStyle('B' . $row)->getFont()->setBold(true);
                $sheet->setCellValue('C' . $row, 'Note');
                $sheet->getStyle('C' . $row)->getFont()->setBold(true);
                $sheet->setCellValue('D' . $row, 'Kode Dokmen');
                $sheet->getStyle('D' . $row)->getFont()->setBold(true);
                $sheet->setCellValue('E' . $row, 'Satuan');
                $sheet->getStyle('E' . $row)->getFont()->setBold(true);
                $sheet->setCellValue('F' . $row, 'Masuk');
                $sheet->getStyle('F' . $row)->getFont()->setBold(true);
                $sheet->setCellValue('G' . $row, 'Keluar');
                $sheet->getStyle('G' . $row)->getFont()->setBold(true);
                $sheet->setCellValue('H' . $row, 'Saldo');
                $sheet->getStyle('H' . $row)->getFont()->setBold(true);
                $row++;

                $sheet->setCellValue('A' . $row, 'Saldo Awal');
                $sheet->setCellValue('H' . $row, $location['stockAwal']);
                $row++;

                $saldo = $location['stockAwal'];
                foreach ($location['stock'] as $stockDet)
                {
                    $sheet->setCellValue('A' . $row, $stockDet->tx_date);
                    $sheet->setCellValue('B' . $row, $stockDet->created_at);
                    
                    
                    $stockMasuk = 0;
                    $stockKeluar = 0;
                    
                    if ($stockDet->procurement_qty !== null || (int)$stockDet->adjustment_qty > 0 || (int)$stockDet->usage_qty < 0)
                    {
                        $stockMasuk = $stockDet->procurement_qty + $stockDet->adjustment_qty - (int)$stockDet->usage_qty;
                    }
                    
                    if ($stockDet->sales_qty !== null || (int)$stockDet->usage_qty > 0|| (int)$stockDet->adjustment_qty < 0)
                    {
                        $stockKeluar = $stockDet->sales_qty + (int)$stockDet->usage_qty - (int)$stockDet->adjustment_qty;
                    }

                    if ($stockDet->procurement_qty !== null && strpos($stockDet->doc_number, 'VOID') === false)
                    {
                        $sheet->setCellValue('C' . $row, 'Item Procurements');
                    }
                    else if ($stockDet->procurement_qty !== null && strpos($stockDet->doc_number, 'VOID') !== false)
                    {
                        $sheet->setCellValue('C' . $row, 'VOID Item Sales');
                    }
                    else if ($stockDet->sales_qty !== null && strpos($stockDet->doc_number, 'VOID') === false)
                    {
                        $sheet->setCellValue('C' . $row, 'Item Sales');
                    }
                    else if ($stockDet->sales_qty !== null && strpos($stockDet->doc_number, 'VOID') !== false)
                    {
                        $sheet->setCellValue('C' . $row, 'VOID Item Procurements');
                    }
                    else if ($stockDet->adjustment_qty !== null && strpos($stockDet->doc_number, 'VOID') === false)
                    {
                        $sheet->setCellValue('C' . $row, 'Item Adjustment');
                    }
                    else if ($stockDet->adjustment_qty !== null && strpos($stockDet->doc_number, 'VOID') !== false)
                    {
                        $sheet->setCellValue('C' . $row, 'VOID Item Adjustment');
                    }
                    else if ($stockDet->usage_qty !== null && strpos($stockDet->doc_number, 'VOID') === false)
                    {
                        $sheet->setCellValue('C' . $row, 'Item Usage');
                    }
                    else if ($stockDet->usage_qty !== null && strpos($stockDet->doc_number, 'VOID') !== false)
                    {
                        $sheet->setCellValue('C' . $row, 'VOID Item Usage');
                    }

                    $sheet->setCellValue('D' . $row, $stockDet->doc_number);
                    // $sheet->setCellValue('E' . $row, 'Satuan');
                    
                    $sheet->setCellValue('F' . $row, $stockMasuk);
                    $sheet->setCellValue('G' . $row, $stockKeluar);
                    
                    $saldo = $saldo + $stockMasuk - $stockKeluar;
                    $sheet->setCellValue('H' . $row, $saldo);

                    $row++;
                }

                $row += 4;
            }
        }

        $sheet->getColumnDimension('A')->setWidth(22);
        $sheet->getColumnDimension('B')->setWidth(22);
        $sheet->getColumnDimension('C')->setWidth(30);
        $sheet->getColumnDimension('D')->setWidth(27);
        $sheet->getColumnDimension('E')->setWidth(10);
        $sheet->getColumnDimension('F')->setWidth(10);
        $sheet->getColumnDimension('G')->setWidth(10);
        $sheet->getColumnDimension('H')->setWidth(10);

        $directory = public_path('report/stockcard');
        if (!File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        $filename = 'stockcard_report_' . $startDateFormated . '_' . $endDateFormated . '_' . $queue->id . '.xlsx';
        $filePath = $directory . '/' . $filename;

        // Save the spreadsheet to a file
        $writer = new Xlsx($spreadsheet);
        $writer->save($filePath);

        $queue->update([
            'status' => 2,
            'file' => url('report/stockcard/' . $filename)
        ]);

        Log::channel('report')->info(' ' . url('report/stockcard/' . $filename));

        return true;
    }

    public function stockValueReport($queue)
    {
        $queue = ReportQueue::where('status',1)->first();

        $startDate = $queue->start_date;
        $endDate = $queue->end_date;
        
        $locationCondition = '';
        $locationParams = [];
        if ($queue->location_id != null) 
        {
            $locationCondition = 'AND a.location_id = ?';
            $locationParams[] = $queue->location_id;
        }

        $items = Item::where('status', 1)->get();

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
                a.doc_number,
                a.location_id,
                l.name as location_name,
                a.created_at
            FROM 
                stock_value a
                JOIN locations l ON a.location_id = l.id
            WHERE
                (
                    a.procurement_date >= ?
                    or a.sales_date >= ?
                    or a.adjustment_date >= ?
                    or a.usage_date >= ?
                )
                AND (
                    a.procurement_date <= ?
                    or a.sales_date <= ?
                    or a.adjustment_date <= ?
                    or a.usage_date <= ?
                )
                $locationCondition
            ORDER BY a.item_code, a.created_at, a.procurement_date, a.sales_date
        ", array_merge([$startDate, $startDate, $startDate, $startDate, $endDate, $endDate, $endDate, $endDate], $locationParams));

        $stockAwal = DB::select("
            SELECT
                a.item_code,
                COALESCE(a.procurement_qty, a.sales_qty * -1, a.adjustment_qty, a.usage_qty * -1) as saldo_qty,
                IFNULL(a.procurement_total, 0) as saldo_nominal
            FROM 
                stock_value_sum a
            WHERE
                a.tx_date < ?
                $locationCondition
            ORDER BY a.item_code, a.created_at
        ", array_merge([$startDate], $locationParams));

        $stockAwal = collect($stockAwal)->groupBy('item_code');
        $stock = collect($stock)->groupBy('item_code');
        
        $stockCard = [];

        foreach ($items as $value) 
        {            
            $stockItem = $stock->get($value->item_code) ?? collect();

            $stockInitial = $stockAwal->get($value->item_code) ?? collect();
            // dd($stockInitial);

            $stockValue = 0;
            $saldoQty       = 0;
            $saldoNominal   = 0;
            $procurement_qty = 0;

            foreach ($stockInitial as $key => $itemDet) 
            {
                $qty = (int)$itemDet->saldo_qty;
                if ((int)$itemDet->saldo_nominal == 0)
                {
                    $saldoNominal += $stockValue * $qty;
                }
                else
                {
                    $saldoNominal += (int)$itemDet->saldo_nominal;
                }
                
                $saldoQty += $qty;
                
                if ($saldoQty != 0)
                {
                    $stockValue = $saldoNominal / $saldoQty;
                }
            }

            $saldoNominal = ($stockValue < 0 ? $stockValue * -1 : $stockValue) * $saldoQty;

            $stockCard[$value->item_code] = [
                'item_name' => $value->name,
                'item_code' => $value->item_code,
                'saldo_qty' => $saldoQty,
                'saldo_nominal' => $saldoNominal,
                'stock_value' => $saldoQty == 0 ? 0 : $stockValue,
                'stock' => $stockItem
            ];
        }

        $startDateFormated = (new \DateTime($startDate))->format('d-m-Y');
        $endDateFormated = (new \DateTime($endDate))->format('d-m-Y');

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', "PT Purnama Jaya Teknik\nRuko Satelit Town Square Blok A 09-10 Surabaya\nTelp. 08155110111 | Fax. 08155110111");
        $sheet->getStyle('A1')->getAlignment()->setWrapText(true);
        $sheet->getStyle('A1')->getFont()->setBold(true);

        $sheet->mergeCells('A3:M3');
        $sheet->setCellValue('A3', 'STOCK VALUE REPORT');
        $sheet->getStyle('A3')->getFont()->setBold(true);
        $sheet->getStyle('A3')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        $sheet->mergeCells('A4:M4');
        $sheet->setCellValue('A4', 'TANGGAL : ' . $startDateFormated . '/' . $endDateFormated);
        $sheet->getStyle('A4')->getFont()->setBold(true);
        $sheet->getStyle('A4')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        $sheet->mergeCells('A6:A7');
        $sheet->setCellValue('A6', 'Tanggal Dokumen');

        $sheet->mergeCells('B6:B7');
        $sheet->setCellValue('B6', 'Tanggal');

        $sheet->mergeCells('C6:C7');
        $sheet->setCellValue('C6', 'Item');

        $sheet->mergeCells('D6:D7');
        $sheet->setCellValue('D6', 'Gudang');

        $sheet->mergeCells('E6:E7');
        $sheet->setCellValue('E6', 'Keterangan');

        $sheet->mergeCells('F6:F7');
        $sheet->setCellValue('F6', 'Kode Dokumen');

        $sheet->mergeCells('G6:H6');
        $sheet->setCellValue('G6', 'Stock Masuk');
        $sheet->setCellValue('G7', 'Qty');
        $sheet->setCellValue('H7', 'NilaiQty');

        $sheet->mergeCells('I6:J6');
        $sheet->setCellValue('I6', 'Stock Keluar');
        $sheet->setCellValue('I7', 'Qty');
        $sheet->setCellValue('J7', 'Nilai');

        $sheet->mergeCells('K6:L6');
        $sheet->setCellValue('K6', 'Saldo');
        $sheet->setCellValue('K7', 'Qty');
        $sheet->setCellValue('L7', 'Nilai');

        $sheet->mergeCells('M6:M7');
        $sheet->setCellValue('M6', 'Value');

        $sheet->getStyle('A6:M7')->getFont()->setBold(true);
        $row = 8;
        // dd($stockCard);
        foreach($stockCard as $item)
        {
            $sheet->setCellValue('A' . $row, 'Saldo Awal ' . $item['item_name'] . ' (' . $item['item_code'] . ')');
            $sheet->getStyle('A' . $row)->getFont()->setBold(true);
            $sheet->setCellValue('K' . $row, $item['saldo_qty']);
            $sheet->setCellValue('L' . $row, $item['saldo_nominal']);
            $sheet->getStyle('M' . $row)->getNumberFormat()->setFormatCode('_-"Rp"* #,##0.00_-;-"Rp"* #,##0.00_-;_-"Rp"* "-"??_-;_-@_-');
            $sheet->setCellValue('M' . $row, $item['stock_value']);
            $sheet->getStyle('M' . $row)->getNumberFormat()->setFormatCode('_-"Rp"* #,##0.00_-;-"Rp"* #,##0.00_-;_-"Rp"* "-"??_-;_-@_-');
            $row++;
            $stockValue = $item['stock_value'];
            $stockQty = $item['saldo_qty'];
            
            foreach ($item['stock'] as $stock)
            {
                $stockMasukQty = 0;
                $stockMasukValue = 0;
                $stockKeluarQty = 0;
                $stockKeluarValue = 0;
                $sheet->setCellValue('A' . $row, $stock->created_at);
                $sheet->setCellValue('B' . $row, $stock->tx_date);
                $sheet->setCellValue('C' . $row, $item['item_name'] . ' (' . $item['item_code'] . ')');
                $sheet->setCellValue('D' . $row, $stock->location_name);
                
                if ($stock->procurement_qty !== null || (int)$stock->adjustment_qty > 0 || (int)$stock->usage_qty < 0)
                {
                    $stockMasukQty = $stock->procurement_qty + $stock->adjustment_qty - (int)$stock->usage_qty;
                    $stockQty = $stockQty +  $stock->procurement_qty + $stock->adjustment_qty - (int)$stock->usage_qty;

                    if ($stock->procurement_qty !== null)
                    {
                        $stockMasukValue = $stock->procurement_total;
                    }
                    else
                    {
                        $stockMasukValue = $stockMasukQty * $stockValue;
                    }

                    if ($stockQty != 0)
                    {
                        $stockValue = (($stockValue * $item['saldo_qty']) + $stockMasukValue) / $stockQty;
                    }
                }
                
                if ($stock->sales_qty !== null || (int)$stock->usage_qty > 0|| (int)$stock->adjustment_qty < 0)
                {
                    $stockKeluarQty = $stock->sales_qty + (int)$stock->usage_qty - (int)$stock->adjustment_qty;

                    $stockQty -= $stockKeluarQty;

                    $stockKeluarValue = $stockValue * $stockKeluarQty;
                }

                if ($stock->procurement_qty !== null && strpos($stock->doc_number, 'VOID') === false)
                {
                    $sheet->setCellValue('E' . $row, 'Item Procurements');
                }
                else if ($stock->procurement_qty !== null && strpos($stock->doc_number, 'VOID') !== false)
                {
                    $sheet->setCellValue('E' . $row, 'VOID Item Sales');
                }
                else if ($stock->sales_qty !== null && strpos($stock->doc_number, 'VOID') === false)
                {
                    $sheet->setCellValue('E' . $row, 'Item Sales');
                }
                else if ($stock->sales_qty !== null && strpos($stock->doc_number, 'VOID') !== false)
                {
                    $sheet->setCellValue('E' . $row, 'VOID Item Procurements');
                }
                else if ($stock->adjustment_qty !== null && strpos($stock->doc_number, 'VOID') === false)
                {
                    $sheet->setCellValue('E' . $row, 'Item Adjustment');
                }
                else if ($stock->adjustment_qty !== null && strpos($stock->doc_number, 'VOID') !== false)
                {
                    $sheet->setCellValue('E' . $row, 'VOID Item Adjustment');
                }
                else if ($stock->usage_qty !== null && strpos($stock->doc_number, 'VOID') === false)
                {
                    $sheet->setCellValue('E' . $row, 'Item Usage');
                }
                else if ($stock->usage_qty !== null && strpos($stock->doc_number, 'VOID') !== false)
                {
                    $sheet->setCellValue('E' . $row, 'VOID Item Usage');
                }
                
                $sheet->setCellValue('F' . $row, $stock->doc_number);
                $sheet->setCellValue('G' . $row, $stockMasukQty);
                $sheet->setCellValue('H' . $row, $stockMasukValue);
                $sheet->getStyle('H' . $row)->getNumberFormat()->setFormatCode('_-"Rp"* #,##0.00_-;-"Rp"* #,##0.00_-;_-"Rp"* "-"??_-;_-@_-');
                $sheet->setCellValue('I' . $row, $stockKeluarQty);
                $sheet->setCellValue('J' . $row, $stockKeluarValue);
                $sheet->getStyle('J' . $row)->getNumberFormat()->setFormatCode('_-"Rp"* #,##0.00_-;-"Rp"* #,##0.00_-;_-"Rp"* "-"??_-;_-@_-');
                $sheet->setCellValue('K' . $row, $stockQty);
                $sheet->setCellValue('L' . $row, $stockQty * $stockValue);
                $sheet->getStyle('L' . $row)->getNumberFormat()->setFormatCode('_-"Rp"* #,##0.00_-;-"Rp"* #,##0.00_-;_-"Rp"* "-"??_-;_-@_-');
                $sheet->setCellValue('M' . $row, $stockValue);
                $sheet->getStyle('M' . $row)->getNumberFormat()->setFormatCode('_-"Rp"* #,##0.00_-;-"Rp"* #,##0.00_-;_-"Rp"* "-"??_-;_-@_-');

                $row++;
            }

            $row += 2;
        }

        $sheet->getColumnDimension('A')->setWidth(22);
        $sheet->getColumnDimension('B')->setWidth(22);
        $sheet->getColumnDimension('C')->setWidth(45);
        $sheet->getColumnDimension('D')->setWidth(20);
        $sheet->getColumnDimension('E')->setWidth(30);
        $sheet->getColumnDimension('F')->setWidth(25);
        $sheet->getColumnDimension('G')->setWidth(8);
        $sheet->getColumnDimension('H')->setWidth(15);
        $sheet->getColumnDimension('I')->setWidth(8);
        $sheet->getColumnDimension('J')->setWidth(25);
        $sheet->getColumnDimension('K')->setWidth(8);
        $sheet->getColumnDimension('L')->setWidth(25);
        $sheet->getColumnDimension('M')->setWidth(25);

        $directory = public_path('report/stockvalue');
        if (!File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        $filename = 'stockvalue_report_' . $startDateFormated . '_' . $endDateFormated . '_' . $queue->id . '.xlsx';
        $filePath = $directory . '/' . $filename;

        // Save the spreadsheet to a file
        $writer = new Xlsx($spreadsheet);
        $writer->save($filePath);

        $queue->update([
            'status' => 2,
            'file' => url('report/stockvalue/' . $filename)
        ]);

        Log::channel('report')->info(' ' . url('report/stockvalue/' . $filename));

        return true;
    }
}
