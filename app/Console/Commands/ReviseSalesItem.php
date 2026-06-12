<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ReviseSalesItem extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:revise-sales-item {--dry-run : Preview changes without applying them}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Revise item_code on sales_details based on Excel revision data (storage/excelData/revision.xlsx)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $filePath = storage_path('excelData/revision.xlsx');

        if (!file_exists($filePath)) {
            $this->error("Excel file not found: {$filePath}");
            return self::FAILURE;
        }

        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->warn('Running in DRY-RUN mode. No changes will be applied.');
        }

        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray();

        // Remove header row
        $header = array_shift($rows);
        $this->info("Loaded " . count($rows) . " data rows from revision.xlsx");
        $this->newLine();

        $successCount = 0;
        $skipCount = 0;
        $errorCount = 0;

        foreach ($rows as $index => $row) {
            $rowNum = $index + 2; // Excel row number (1-indexed + header)
            $docNumber    = trim($row[4] ?? '');
            $oldItemCode  = trim($row[2] ?? '');
            $newQty       = trim($row[5] ?? '');
            $newItemCode  = trim($row[6] ?? '');

            // Validate required fields
            if (empty($docNumber) || empty($oldItemCode) || empty($newItemCode) || $newQty === '') {
                $this->warn("Row {$rowNum}: Skipped — missing required data (doc_number: '{$docNumber}', old: '{$oldItemCode}', qty: '{$newQty}', new: '{$newItemCode}')");
                $skipCount++;
                continue;
            }

            $newQty = (float) $newQty;

            $itemCodeChanged = ($oldItemCode !== $newItemCode);

            // 1. Find the sales record by doc_number
            $sales = DB::table('sales')
                ->where('doc_number', $docNumber)
                ->first();

            if (!$sales) {
                $this->error("Row {$rowNum}: Sales not found for doc_number '{$docNumber}'");
                $errorCount++;
                continue;
            }

            // 2. Find sales_details joined with items_details where item_code = old code
            $salesDetails = DB::table('sales_details as sd')
                ->join('items_details as id', 'sd.item_detail_id', '=', 'id.id')
                ->where('sd.sales_id', $sales->id)
                ->where('id.item_code', $oldItemCode)
                ->select('sd.id as sales_detail_id', 'sd.item_detail_id', 'sd.qty as old_qty', 'id.item_code', 'id.location_id')
                ->get();

            if ($salesDetails->isEmpty()) {
                $this->error("Row {$rowNum}: No sales_detail found for doc '{$docNumber}' with item_code '{$oldItemCode}'");
                $errorCount++;
                continue;
            }

            foreach ($salesDetails as $detail) {
                $updateData = [];
                $changes = [];

                // 3. Check item_code change
                if ($itemCodeChanged) {
                    $newItemDetail = DB::table('items_details')
                        ->where('item_code', $newItemCode)
                        ->where('location_id', $detail->location_id)
                        ->where('status', 1)
                        ->first();

                    if (!$newItemDetail) {
                        $this->error("Row {$rowNum}: No items_details found for new item_code '{$newItemCode}' at location_id {$detail->location_id}");
                        $errorCount++;
                        continue;
                    }

                    $updateData['item_detail_id'] = $newItemDetail->id;
                    $changes[] = "item: {$oldItemCode} → {$newItemCode} (detail_id {$detail->item_detail_id} → {$newItemDetail->id})";
                }

                // 4. Check qty change
                $qtyChanged = ((float) $detail->old_qty !== $newQty);
                if ($qtyChanged) {
                    $updateData['qty'] = $newQty;
                    $changes[] = "qty: {$detail->old_qty} → {$newQty}";
                }

                // Skip if nothing to update
                if (empty($updateData)) {
                    $this->line("Row {$rowNum}: Skipped — no changes needed for sales_detail #{$detail->sales_detail_id} | doc: {$docNumber}");
                    $skipCount++;
                    continue;
                }

                $changesStr = implode(', ', $changes);

                if ($isDryRun) {
                    $this->info("Row {$rowNum}: [DRY-RUN] Would update sales_detail #{$detail->sales_detail_id} — {$changesStr} | doc: {$docNumber}");
                } else {
                    DB::table('sales_details')
                        ->where('id', $detail->sales_detail_id)
                        ->update($updateData);

                    $this->info("Row {$rowNum}: Updated sales_detail #{$detail->sales_detail_id} — {$changesStr} | doc: {$docNumber}");
                }

                $successCount++;
            }
        }

        $this->newLine();
        $this->info("========== Summary ==========");
        $this->info("Success: {$successCount}");
        $this->warn("Skipped: {$skipCount}");
        $this->error("Errors:  {$errorCount}");

        if ($isDryRun) {
            $this->newLine();
            $this->warn("This was a dry run. Run without --dry-run to apply changes.");
        }

        return self::SUCCESS;
    }
}
