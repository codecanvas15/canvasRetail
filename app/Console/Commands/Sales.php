<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\SalesController;
use Illuminate\Support\Facades\Log;
use App\Contact;
use App\Item;
use App\ItemDetail;
use App\Location;
use App\Sales as AppSales;
use App\SalesDetail;
use App\Tax;
use App\User;
use DateTime;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class Sales extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import:sales {file} {--dry-run : Validate data without inserting into the database}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import sales data from an Excel file with custom format';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        Log::channel('import')->info('Importing sales data...');
        $this->info('Importing sales data...');
        // Set the authenticated user for the command (for use in SalesController)
        $user = User::where('username', 'admin')->first(); // Or use a specific user, e.g., User::where('username', 'admin')->first()
        Auth::setUser($user);
        $flag = true;
        $error = '';
        $notFoundItems = [];

        $fileName = $this->argument('file');
        $filePath = storage_path('excelData/' . $fileName);
        if (!file_exists($filePath)) 
        {
            Log::channel('import')->error("File not found: $filePath");
            $this->error("File not found: $filePath");
            return 1;
        }

        // Load spreadsheet using PhpSpreadsheet
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray();

        // Assume first row is header
        $header = $rows[0];
        unset($rows[0]);

        // Pre-fetch lookups to avoid N+1 queries
        $contactsMap = Contact::pluck('id', 'name');
        $taxesMap = Tax::pluck('id', 'value');
        $itemsMap = Item::pluck('item_code', 'name');
        $banksMap = \App\Bank::pluck('id', 'bank_name');

        // Group rows by Customer+Date for multi-item sales
        $salesList = [];
        $imported = 0;
        $scanned = 0;
        foreach ($rows as $row) 
        {
            $rowData = array_combine($header, $row);
            $customerName = trim($rowData['Customer'] ?? '');
            $groupKey = $customerName . '|' . $rowData['Date'] . '|' . $rowData['Item Code'];

            // Lookups (using pre-fetched maps)
            $contact_id = $contactsMap[$customerName] ?? null;
            if (is_null($contact_id)) 
            {
                Log::channel('import')->info("Customer not found: {$customerName}, creating new customer.");
                $this->info("Customer not found: {$customerName}, creating new customer.");

                $contact = Contact::create([
                    'name' => $customerName,
                    'type' => 'CUSTOMER',
                    'status' => 1,
                    'created_by' => Auth::id(),
                    'updated_by' => Auth::id()
                ]);

                $contact_id = $contact->id;
                $contactsMap[$customerName] = $contact_id; // update cache
            }

            // $location_id = Location::where('name', $rowData['Location'])->value('id');
            // if (is_null($location_id)) 
            // {
            //     Log::channel('import')->error("Location not found: {$rowData['Location']}");
                
            //     $location_id = 1;
            // }

            $location_id = 1;

            $tax_id = $taxesMap[$rowData['Tax Category']] ?? null;
            if (is_null($tax_id)) 
            {
                Log::channel('import')->warning("Tax category not found: {$rowData['Tax Category']}");
                $this->warn("Tax category not found: {$rowData['Tax Category']}");
                return 1;
            }

            $itemCode = null;
            if (isset($rowData['Item Name'])) 
            {
                $itemName = preg_replace('/\s+/', ' ', trim($rowData['Item Name']));
                $itemCode = $itemsMap->filter(function ($code, $name) use ($itemName) {
                    return stripos($name, $itemName) !== false;
                })->first();
            }

            if (is_null($itemCode)) 
            {
                $flag = false;
                $itemName = preg_replace('/\s+/', ' ', trim($rowData['Item Name']));
                if (!in_array($itemName, $notFoundItems))
                {
                    $notFoundItems[] = $itemName;
                    $error .= "Item Not found: $itemName" . PHP_EOL;
                }
            }

            $bank = null;
            if (isset($rowData['Bank']))
            {
                $bank = $banksMap[$rowData['Bank']] ?? null;
            }

            $qty = (float)str_replace(',', '.', $rowData['Quantity']);
            
            $price = $this->parseRupiah($rowData['Subtotal']) / $qty;

            Log::channel('import')->info("Processing row $groupKey|{$rowData['Location']}|$itemName|{$qty}|{$rowData['Subtotal']}|{$price}|{$rowData['Rounding']}|{$rowData['Note']}");
            $this->info("Processing row $groupKey|{$rowData['Location']}|$itemName|{$qty}|{$rowData['Subtotal']}|{$price}|{$rowData['Rounding']}|{$rowData['Note']}");

            // Prepare item
            $item = [
                "item_code" => $itemCode,
                "qty" => $qty,
                "price" => $price,
                "discount" => floatval($rowData['Discount %'] ?? 0)
            ];

            if (!isset($salesList[$groupKey])) 
            {
                $salesList[$groupKey] = [
                    "contact_id"        => $contact_id,
                    "location_id"       => $location_id,
                    "sales_date"        => DateTime::createFromFormat('d/m/Y', $rowData['Date'])->format('Y-m-d'),
                    "tax_ids"           => $tax_id,
                    "include_tax"       => $rowData['Tax Category'] == 'Include' ? 1 : 0,
                    "rounding"          => $rowData['Rounding'],
                    "bank"              => $bank,
                    "note"              => $rowData['Note'],
                    "items"             => []
                ];
            }

            $salesList[$groupKey]['items'][] = $item;
            $scanned++;
        }

        $this->info("Import finished. Total items Scanned: $scanned");
        if (!$flag)
        {
            $this->error("Errors found during import:\n" . $error);
            return 1; // Exit if any item was not found
        }

        $dryRun = $this->option('dry-run');

        if ($dryRun)
        {
            $this->info('');
            $this->info('=== DRY RUN SUMMARY ===');
            $this->info("Total rows scanned: $scanned");
            $this->info("Total sales groups to create: " . count($salesList));
            foreach ($salesList as $groupKey => $requestData)
            {
                $totalItems = count($requestData['items']);
                $totalQty = array_sum(array_column($requestData['items'], 'qty'));
                $this->info("  [$groupKey] => $totalItems item(s), total qty: $totalQty, date: {$requestData['sales_date']}");
            }
            $this->info('No records were inserted. Remove --dry-run to import.');
            Log::channel('import')->info('Dry run completed. No records inserted.');
            return 0;
        }

        DB::beginTransaction();
        try
        {
            foreach ($salesList as $groupKey => $requestData) 
            {
                Log::channel('import')->info("Adding sales for " . $groupKey);
                $this->info(PHP_EOL . "Adding sales for " . $groupKey . " with " . count($requestData['items']) . " items.");
                $request = new Request($requestData);
                $response = $this->addSales($request);
                
                if (!$response->getData()->status) 
                {
                    DB::rollBack();
                    Log::channel('import')->error("Failed to add sales for $groupKey: " . json_encode($response->getData()->message));
                    throw new \Exception("Failed to add sales for $groupKey: " . json_encode($response->getData()->message));
                }
                $imported++;
            }

            if ($dryRun)
            {
                DB::rollBack();
                return 0;
            }

            DB::commit();
        }
        catch (\Exception $e) 
        {
            DB::rollBack();
            Log::channel('import')->error("Error during sales import: " . $e->getMessage());
            $this->error("Error during sales import: " . $e->getMessage());
            return 1;
        }

        
        $this->info("Import finished. Total items Imported: $imported");
        Log::channel('import')->info('Sales import finished.');
        return 0;
    }

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

        try
        {
            $date = strtotime($request->sales_date);
            $salesDate = date('Y-m-d H:i:s',$date);

            // document number
            $date = new DateTime('now');
            $date = $date->format('dmY');

            $documentNumber = "";
            $countDocNo = 1;

            // Lock sales table to prevent duplicate doc numbers under concurrency
            while ($countDocNo > 0)
            {
                $seq = AppSales::lockForUpdate()
                    ->whereRaw("DATE_FORMAT(created_at, '%d%m%Y') <= STR_TO_DATE(?, '%d%m%Y')", [$date])
                    ->whereNotNull('doc_number')
                    ->count();

                $documentNumber = 'INV-'.$date.'-'.str_pad(($seq+1), 4, '0', STR_PAD_LEFT);

                $countDocNo = AppSales::where('doc_number', $documentNumber)->count();
            }

            $taxes = explode(',', $request->tax_ids);

            $tax = Tax::whereIn('id', $taxes)->sum('value');
            $taxes = Tax::whereIn('id', $taxes)->get();
            
            $totalTax = [];
            foreach ($taxes as $key)
            {
                $totalTax[] = $key->value;
            }

            $due = Contact::where('id', $request->contact_id)->select('due_date')->first();

            // Convert $salesDate to DateTime object
            $salesDateTime = new DateTime($salesDate);

            // Add the number of days from $due->due_date
            $salesDateTime->modify('+' . $due->due_date . ' days');

            // Format the resulting date
            $dueDate = $salesDateTime->format('Y-m-d H:i:s');

            // insert sales
            $sales = AppSales::create([
                'contact_id'    => $request->contact_id,
                'sales_date'    => $salesDate,
                'amount'        => 0,
                'pay_status'    => "Paid",
                'created_by'    => auth()->user()->id,
                'updated_by'    => auth()->user()->id,
                'status'        => 1,
                'doc_number'    => $documentNumber,
                'bank_id'       => $request->bank ?? null,
                'rounding'      => (float)($request->rounding ?? 0),
                'location_id'   => $request->location_id,
                'reason'        => $request->note ?? null,
                'due_date'      => $dueDate,
            ]);

            $totalAmount = 0.00;

            foreach ($request->items as $item)
            {
                $this->info("Processing item " . json_encode($item));
                $itemDet = ItemDetail::where('item_code', $item['item_code'])->where('location_id', $request->location_id)->where('status', 1)->first();

                $discountPercent = $item['discount'] ?? 0;
                $discount = $discountPercent > 0 ? ($discountPercent / 100) * $item['price'] : 0;
                $priceAfterDiscount = $item['price'] - $discount;

                if ($request->include_tax)
                {
                    $itemPrice = $priceAfterDiscount / (1 + $tax/100);
                }
                else
                {
                    $itemPrice = $priceAfterDiscount;
                }

                $total = $item['qty'] * $itemPrice;

                if ($itemDet == null)
                {
                    $itemDet = ItemDetail::create([
                        'item_code'     => $item['item_code'],
                        'location_id'   => $request->location_id,
                        'qty'           => 0,
                        'price'         => 0,
                        'created_by'    => auth()->user()->id,
                        'updated_by'    => auth()->user()->id,
                        'status'        => 1
                    ]);
                }

                $this->info("$sales->id|{$itemDet['id']}|{$item['item_code']}|{$item['qty']}|$itemPrice|$total|$request->tax_ids|$discount|{$item['price']}");

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

                // Deduct inventory
                $itemDet->qty -= $item['qty'];
                $itemDet->save();

                $totalAmount += ($total + $total * ($tax/100));
            }

            $totalAmount += (float)($request->rounding ?? 0);
            $roundedAmount = round($totalAmount);

            $sales->update([
                'amount'        => $roundedAmount,
                'updated_by'    => auth()->user()->id,
                'updated_at'    => date("Y-m-d H:i:s"),
                'tax'           => implode('|', $totalTax)
            ]);

            return response()->json([
                "status" => true,
                "message" => "Sales Success"
            ]);
        }
        catch (\Throwable $th)
        {
            Log::channel('import')->error("Error in addSales: " . $th->getMessage());
            $this->error("Error in addSales: " . $th->getMessage());

            return response()->json([
                "status" => false,
                "message" => $th->getMessage()
            ], 500);
        }
    }

    function parseRupiah($str) 
    {
        // Remove 'Rp', spaces, and non-numeric characters except , and .
        $str = str_replace(['Rp', ' '], '', $str);
        // $str = str_replace('.', '', $str); // remove dot thousand separator if needed
        $str = str_replace(',', '', $str); // remove comma thousand separator
        return (float)$str;
    }
}
