<?php

namespace App\Console\Commands;

use App\Tax;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixTransaction extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:fix-amount {--dry-run} {--sales} {--procurement}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Recalculate procurement and sales amounts from their details';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        //
        if ($this->option('procurement')) {
            $this->info('Starting procurement amount fix...');
            DB::table('procurements')
                ->where('doc_number', '!=', 'STOCK AWAL')
                ->orderBy('id')
                ->chunkById(100, function ($procurements) {
    
                    foreach ($procurements as $procurement) {
                        // $this->info("Processing Procurement ID: {$procurement->id}, Doc Number: {$procurement->doc_number}");
                        $details = DB::table('procurement_details')
                            ->where('procurement_id', $procurement->id)
                            ->get();
    
                        if ($details->isEmpty()) {
                            continue;
                        }
    
                        $subtotal = 0;
                        $discountTotal = 0;
                        $taxTotal = 0;
    
                        foreach ($details as $detail) {
                            // $this->info("  Processing Detail ID: {$detail->id}");
                            $qty = (float) $detail->qty;
                            $price = (float) $detail->initial_price;
                            $taxRate = Tax::where('id', $detail->tax_ids)->select('value')->first() ?? null;
                            $includeTax = (bool) $procurement->include_tax;
                            // $this->info("    Qty: {$qty}, Price: {$price}, Tax Rate: {$taxRate->value}%, Include Tax: " . ($includeTax ? 'Yes' : 'No'));
    
                            // Original line price
                            $lineBase = $qty * $price;
    
                            /**
                             * 1️⃣ Apply sequential discounts (e.g. 10|5)
                             */
                            $discounts = collect(explode('|', (string) $detail->discount))
                                ->map(fn ($d) => (float) $d)
                                ->filter(fn ($d) => $d > 0);
    
                            $priceAfterDiscount = $price;
    
                            foreach ($discounts as $discount) {
                                $priceAfterDiscount -= ($discount / 100) * $priceAfterDiscount;
                            }
    
                            /**
                             * 2️⃣ Handle include / exclude tax
                             */
                            if ($includeTax && $taxRate != null) {
                                // Extract net price from tax-inclusive price
                                $netItemPrice = $priceAfterDiscount / (1 + ($taxRate->value / 100));
                            } else {
                                // Tax-exclusive price
                                $netItemPrice = $priceAfterDiscount;
                            }
    
                            /**
                             * 3️⃣ Line calculations
                             */
                            $lineSubtotal = $qty * $netItemPrice;
    
                            $lineTax = 0;
                            if ($taxRate != null)
                            {
                                $lineTax = $lineSubtotal * ($taxRate->value / 100);
                            }
    
                            /**
                             * 4️⃣ Accumulate totals
                             */
                            $subtotal += $lineSubtotal;
                            $taxTotal += $lineTax;
                            // $this->info("    Line Subtotal: {$lineSubtotal}, Line Tax: {$lineTax}, Line Discount: {$lineDiscount}");
                        }
    
                        $total = round($subtotal + $taxTotal + $procurement->rounding,2);
    
                        if($procurement->amount != $total) {
                            if ($this->option('dry-run')) {
                                $this->error("{$procurement->doc_number} => Recorded: " . $procurement->amount . ",  Expected: " . $total);
                            } else {
                                DB::table('procurements')
                                    ->where('id', $procurement->id)
                                    ->update([
                                        'amount' => $total,
                                    ]);
                            }
                        }
    
                    }
                });
    
            $this->info('Procurement recalculation completed.');
        }


        if ($this->option('sales')) {
            // ───────────────────────────────────────────────
            // Sales recalculation
            // ───────────────────────────────────────────────
            $this->info('Starting sales amount fix...');

            DB::table('sales')
                ->orderBy('id')
                ->chunkById(100, function ($salesRecords) {

                    foreach ($salesRecords as $sale) {
                        $details = DB::table('sales_details')
                            ->where('sales_id', $sale->id)
                            ->get();

                        if ($details->isEmpty()) {
                            continue;
                        }

                        /**
                         * If include_tax is null, calculate both ways and detect
                         * which one matches the recorded amount.
                         */
                        if ($sale->include_tax === null) {
                            $totalInclude = $this->calculateSalesTotal($details, true, $sale->rounding);
                            $totalExclude = $this->calculateSalesTotal($details, false, $sale->rounding);

                            $matchInclude = ($sale->amount == $totalInclude);
                            $matchExclude = ($sale->amount == $totalExclude);

                            if ($matchInclude) {
                                $detectedIncludeTax = 1;
                            } elseif ($matchExclude) {
                                $detectedIncludeTax = 0;
                            } else {
                                // Neither matches — default to exclude tax
                                $detectedIncludeTax = 0;
                            }

                            $total = $detectedIncludeTax ? $totalInclude : $totalExclude;

                            if ($this->option('dry-run')) {
                                $this->warn("{$sale->doc_number} => include_tax is NULL, detected: " . ($detectedIncludeTax ? 'YES' : 'NO'));
                                if ($sale->amount != $total) {
                                    $this->error("{$sale->doc_number} => Recorded: " . $sale->amount . ",  Expected: " . $total);
                                }
                            } else {
                                $updateData = ['include_tax' => $detectedIncludeTax];
                                if ($sale->amount != $total) {
                                    $updateData['amount'] = $total;
                                }
                                DB::table('sales')
                                    ->where('id', $sale->id)
                                    ->update($updateData);
                            }

                            continue;
                        }

                        /**
                         * Normal flow — include_tax is known
                         */
                        $includeTax = (bool) $sale->include_tax;
                        $total = $this->calculateSalesTotal($details, $includeTax, $sale->rounding);

                        if ($sale->amount != $total) {
                            if ($this->option('dry-run')) {
                                $this->error("{$sale->doc_number} => Recorded: " . $sale->amount . ",  Expected: " . $total);
                            } else {
                                DB::table('sales')
                                    ->where('id', $sale->id)
                                    ->update([
                                        'amount' => $total,
                                    ]);
                            }
                        }
                    }
                });

            $this->info('Sales recalculation completed.');
        }

        return self::SUCCESS;
    }

    /**
     * Calculate the total for a sales transaction given its details.
     */
    private function calculateSalesTotal($details, bool $includeTax, $rounding): float
    {
        $subtotal = 0;
        $taxTotal = 0;

        foreach ($details as $detail) {
            $qty = (float) $detail->qty;
            $price = (float) $detail->initial_price;
            $taxRate = Tax::where('id', $detail->tax_ids)->select('value')->first() ?? null;

            /**
             * 1️⃣ Apply sequential discounts (e.g. 10|5)
             */
            $discounts = collect(explode('|', (string) $detail->discount))
                ->map(fn ($d) => (float) $d)
                ->filter(fn ($d) => $d > 0);

            $priceAfterDiscount = $price;

            foreach ($discounts as $discount) {
                $priceAfterDiscount -= ($discount / 100) * $priceAfterDiscount;
            }

            /**
             * 2️⃣ Handle include / exclude tax
             */
            if ($includeTax && $taxRate != null) {
                $netItemPrice = $priceAfterDiscount / (1 + ($taxRate->value / 100));
            } else {
                $netItemPrice = $priceAfterDiscount;
            }

            /**
             * 3️⃣ Line calculations
             */
            $lineSubtotal = $qty * $netItemPrice;

            $lineTax = 0;
            if ($taxRate != null) {
                $lineTax = $lineSubtotal * ($taxRate->value / 100);
            }

            /**
             * 4️⃣ Accumulate totals
             */
            $subtotal += $lineSubtotal;
            $taxTotal += $lineTax;
        }

        return round($subtotal + $taxTotal + (float) $rounding, 2);
    }
}
