<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\IOFactory;
use App\Item;
use App\ItemDetail;
use App\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Procurement as AppProcurement;
use App\ProcurementDetail;

class Items extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import:items 
        {file : The Excel file name in storage/excelData/}
        {--dry-run : Preview the import without saving any data}
        {--procurement-date= : Procurement date (Y-m-d), defaults to today}
        {--location-id=1 : Location ID for items and procurement}
        {--contact-id=1 : Contact ID for procurement}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import item data from an Excel file with custom format';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $user = User::where('username', 'admin')->first();
        if (!$user) {
            $this->error("Admin user not found. Please ensure a user with username 'admin' exists.");
            return 1;
        }
        Auth::setUser($user);

        $fileName = $this->argument('file');
        $filePath = storage_path('excelData/' . $fileName);

        if (!file_exists($filePath)) {
            $this->error("File not found: $filePath");
            Log::error("File not found: $filePath");
            return 1;
        }

        // Resolve command options
        $dryRun     = $this->option('dry-run');
        $locationId = (int) $this->option('location-id');
        $contactId  = (int) $this->option('contact-id');
        $procurementDate = $this->option('procurement-date')
            ? date('Y-m-d H:i:s', strtotime($this->option('procurement-date')))
            : now()->format('Y-m-d H:i:s');

        if ($dryRun) {
            $this->warn('=== DRY RUN MODE — no data will be saved ===');
        }

        // Precomputed column widths (must match header layout)
        $w = [
            'kode'  => 50,
            'nama'  => 120,
            'stock' => 10,
            'value' => 15,
            'total' => 15,
        ];

        // Build and print a padded header
        $headers = ['KODE', 'NAMA BARANG', 'STOCK', 'Value', 'Total'];
        $cols = [
            str_pad($headers[0], $w['kode'], ' ', STR_PAD_RIGHT),
            str_pad($headers[1], $w['nama'], ' ', STR_PAD_RIGHT),
            str_pad($headers[2], $w['stock'], ' ', STR_PAD_LEFT),
            str_pad($headers[3], $w['value'], ' ', STR_PAD_LEFT),
            str_pad($headers[4], $w['total'], ' ', STR_PAD_LEFT),
        ];
        $this->line(implode(' | ', $cols));

        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray();

        // First row is header
        $header = $rows[0];
        unset($rows[0]);

        $imported = 0;
        $existing = 0;
        $skipped = 0;
        $scanned = 0;
        $num = 1;
        $totalQty = 0;
        $totalValue = 0;
        $report = [];
        $newItems = [];

        try {
            DB::beginTransaction();

            foreach ($rows as $row) {
                $rowData = array_combine($header, $row);

                $itemCode = trim($rowData['KODE'] ?? '');
                $itemName = trim($rowData['NAMA BARANG'] ?? '');

                $kode   = str_pad($itemCode, $w['kode'], ' ', STR_PAD_RIGHT);
                $nama   = str_pad($itemName, $w['nama'], ' ', STR_PAD_RIGHT);
                $stok   = str_pad($rowData['STOCK'] ?? '', $w['stock'], ' ', STR_PAD_LEFT);
                $beli   = str_pad($rowData['Value'] ?? '', $w['value'], ' ', STR_PAD_LEFT);
                $jumlah = str_pad($rowData['Total'] ?? '', $w['total'], ' ', STR_PAD_LEFT);

                // Print the row to console
                $this->line("$kode | $nama | $stok | $beli | $jumlah");

                if (!$itemName) {
                    $this->warn("Skipping row — missing Item Name: " . json_encode($rowData));
                    $skipped++;
                    $num++;
                    continue;
                }

                // Create item if it doesn't exist
                $item = Item::where('item_code', $itemCode)->orWhere('name', 'like', '%' . $itemName . '%')->first();

                $isNew = false;
                if (!$item) {
                    $this->info("Imported: $itemCode - $itemName");
                    $item = Item::create([
                        'item_code' => $itemCode,
                        'name'      => $itemName,
                        'status'    => '1',
                    ]);
                    $newItems[] = [$itemCode, $itemName];
                    $imported++;
                    $isNew = true;
                } else {
                    $itemCode = $item->item_code;
                    $existing++;
                }

                // Create item detail if it doesn't exist
                $itemDetail = ItemDetail::where('item_code', $itemCode)->first();

                if (!$itemDetail) {
                    $this->info("Creating ItemDetail for item_code: $itemCode");
                    $itemDetail = ItemDetail::create([
                        'item_code'  => $itemCode,
                        'location_id'=> $locationId,
                        'qty'        => 0,
                        'price'      => 0,
                        'created_by' => $user->id,
                        'updated_by' => $user->id,
                        'status'     => '1',
                    ]);
                }

                $detailTotal = round($this->parseRupiah($rowData['Total'] ?? ''), 2);

                if ($rowData['STOCK'] == 0 && $rowData['Value'] == 0)
                {
                    $detailTotal = 0;
                }

                // Create one procurement per row
                $procurement = AppProcurement::create([
                    'contact_id'       => $contactId,
                    'procurement_date' => $procurementDate,
                    'amount'           => $detailTotal,
                    'pay_status'       => 'Paid',
                    'created_by'       => $user->id,
                    'updated_by'       => $user->id,
                    'status'           => 2,
                    'doc_number'       => 'STOCK AWAL',
                    'include_tax'      => '',
                    'rounding'         => '',
                    'external_doc_no'  => 'STOCK AWAL',
                    'location_id'      => $locationId,
                ]);

                ProcurementDetail::create([
                    'procurement_id' => $procurement->id,
                    'item_detail_id' => $itemDetail->id,
                    'qty'            => $rowData['STOCK'] ?? 0,
                    'price'          => $this->parseRupiah($rowData['Value'] ?? ''),
                    'total'          => $detailTotal,
                    'tax_ids'        => '',
                    'created_by'     => $user->id,
                    'updated_by'     => $user->id,
                    'status'         => 1,
                    'discount'       => '',
                    'initial_price'  => $this->parseRupiah($rowData['Value'] ?? ''),
                ]);

                $rowQty   = (float) ($rowData['STOCK'] ?? 0);
                $rowValue = $detailTotal;
                $totalQty   += $rowQty;
                $totalValue += $rowValue;

                // $report[] = [
                //     $scanned + 1,
                //     $itemCode,
                //     mb_substr($itemName, 0, 50),
                //     number_format($rowQty, 0),
                //     number_format($this->parseRupiah($rowData['Value'] ?? ''), 2),
                //     number_format($rowValue, 2),
                //     $isNew ? 'NEW' : 'EXISTS',
                // ];

                $scanned++;
                $num++;
            }

            if ($dryRun) {
                DB::rollBack();
                $this->warn('=== DRY RUN COMPLETE — all changes rolled back ===');
            } else {
                DB::commit();
            }
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("Import failed: " . $e->getMessage());
            Log::error("Import failed", ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return 1;
        }

        // ── Import Report ──
        $this->newLine();
        $this->line(str_repeat('=', 80));
        $this->info($dryRun ? '  IMPORT REPORT (DRY RUN)' : '  IMPORT REPORT');
        $this->line(str_repeat('=', 80));

        $this->table(
            ['#', 'Item Code', 'Item Name', 'Qty', 'Price', 'Value', 'Status'],
            $report
        );

        // Summary footer
        // $this->line(str_repeat('-', 80));
        // $this->info("  File         : $fileName");
        // $this->info("  Total Scanned: $scanned");
        // $this->info("  New Items    : $imported");
        // $this->info("  Existing     : $existing");
        // $this->info("  Skipped      : $skipped");
        // $this->info("  Total Qty    : " . number_format($totalQty, 0));
        // $this->info("  Total Value  : " . number_format($totalValue, 2));
        // $this->line(str_repeat('=', 80));

        if (!$dryRun) {
            Log::info("Item import finished", [
                'file'     => $fileName,
                'scanned'  => $scanned,
                'imported' => $imported,
                'existing' => $existing,
                'skipped'  => $skipped,
            ]);
        }

        $this->table(
            ['Item Code', 'Item Name'],
            $newItems
        );

        Log::channel('import')->info('Item import finished.');
        return 0;
    }

    /**
     * Parse a currency string (e.g. "Rp 1,000.50") to a float.
     */
    function parseRupiah($str): float
    {
        if (empty($str)) {
            return 0.0;
        }

        $str = str_replace(['Rp', ' '], '', $str);
        $str = str_replace(',', '', $str); // remove thousand separator
        return (float) $str;
    }
}
