<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Api\ProcurementController;
use Illuminate\Http\Request;
use App\Contact;
use App\Item;
use App\ItemDetail;
use App\Location;
use App\Procurement as AppProcurement;
use App\ProcurementDetail;
use App\Tax;
use App\User;
use DateTime;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class Procurement extends Command
{
    protected $signature = 'import:procurement {file}';
    protected $description = 'Import procurement data from an Excel file with custom format';

    public function handle()
    {
        Log::channel('import')->info('Importing procurement data...');
        // Set the authenticated user for the command (for use in SalesController)
        $user = User::where('username', 'admin')->first(); // Or use a specific user, e.g., User::where('username', 'admin')->first()
        Auth::setUser($user);
        $flag = true;

        $fileName = $this->argument('file');
        $filePath = storage_path('excelData/' . $fileName);
        if (!file_exists($filePath)) 
        {
            Log::channel('import')->error("File not found: $filePath");
            throw new \Exception("File not found: $filePath");
            return 1;
        }

        // Load spreadsheet using PhpSpreadsheet
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray();

        // Assume first row is header
        $header = $rows[0];
        unset($rows[0]);

        // Group rows by External Doc Number for multi-item procurement
        $procurements = [];
        foreach ($rows as $row) 
        {
            // Skip if all columns are null or empty
            $allNull = true;
            foreach ($row as $value) {
                if (!is_null($value) && $value !== '') {
                    $allNull = false;
                    break;
                }
            }
            if ($allNull) {
                continue;
            }
            $rowData = array_combine($header, $row);
            $docNo = $rowData['External Doc Number'] ?? '';

            // Lookups
            // dd($rowData);
            if ($rowData['Vendor'] == '') 
            {
                Log::channel('import')->error('Vendor/Customer is empty in row: ' . json_encode($rowData));
                throw new \Exception("Vendor/Customer is empty in row: " . json_encode($rowData));
            }

            $contact_id = Contact::where('name', $rowData['Vendor'] ?? $rowData['Customer'] ?? '')->value('id');
            if (is_null($contact_id)) 
            {
                Log::channel('import')->error('Vendor/Customer not found: ' . ($rowData['Vendor'] ?? $rowData['Customer'] ?? ''));

                Contact::create([
                    'name' => $rowData['Vendor'] ?? $rowData['Customer'] ?? '',
                    'type' => isset($rowData['Vendor']) ? 'SUPPLIER' : 'CUSTOMER',
                    'status' => 1,
                    'created_by' => Auth::id(),
                    'updated_by' => Auth::id()
                ]);
            }

            $location_id = Location::where('name', $rowData['Location'])->value('id');
            if (is_null($location_id)) 
            {
                Log::channel('import')->info('Location not found: ' . ($rowData['Location'] ?? ''));
                // throw new \Exception("Location not found: " . ($rowData['Location'] ?? ''));
                $location_id = 1;
            }

            if ($rowData['Tax Amount'] != 0) 
            {
                $tax_id = Tax::where('value', $rowData['Tax Amount'])->value('id');
                if (is_null($tax_id)) 
                {
                    Log::channel('import')->error('Tax category not found: ' . ($rowData['Tax Category'] ?? ''));
                    throw new \Exception("Tax category not found: " . ($rowData['Tax Category'] ?? ''));
                }
            }
            else
            {
                $tax_id = null;
            }

            // Support both "Item Code" and "Item Name" columns
            $itemCode = $rowData['Item Code'] ?? null;
            if (!$itemCode && isset($rowData['Item Name'])) 
            {
                $itemCode = \App\Item::where('name', 'like', '%' . trim($rowData['Item Name']) . '%')->value('item_code');
            }

            if (is_null($itemCode)) 
            {
                $flag = false;

                $this->info("Item Not found: " . $rowData['Item Name'] . " with external doc no : " . $docNo);
            }

            Log::channel('import')->info("Processing row $docNo: Vendor={$rowData['Vendor']}, Location={$rowData['Location']}, ItemCode={$itemCode}, ItemName={$rowData['Item Name']}, Quantity={$rowData['Quantity']}, Price={$rowData['Price']}, Discount={$rowData['Discount %']}, Rounding={$rowData['Rounding']}");

            // Prepare item
            $item = [
                "item_code" => $itemCode,
                "qty" => (int)($rowData['Quantity'] ?? 0),
                "price" => ($this->parseRupiah($rowData['Price']) ?? 0),
                "discounts" => [floatval($this->parseDiscount($rowData['Discount %']) ?? 0)]
            ];

            if (!isset($procurements[$docNo])) 
            {
                // dd($rowData['Date']);
                // dd(\DateTime::createFromFormat('m/d/Y', $rowData['Date'])->format('Y-m-d'));
                $procurements[$docNo] = [
                    "contact_id"        => $contact_id,
                    "location_id"       => $location_id,
                    "procurement_date"  => isset($rowData['Date']) ? \DateTime::createFromFormat('m/d/Y', $rowData['Date'])->format('Y-m-d') : null,
                    "tax_ids"           => $tax_id,
                    "include_tax"       => 0,
                    "rounding"          => $rowData['Rounding'] ?? 0,
                    "external_doc_no"   => $docNo,
                    "items"             => []
                ];
            }
            $procurements[$docNo]['items'][] = $item;
        }

        if (!$flag)
        {
            $this->error("There is any item not found in the database, please check the log for details.");

            return 1;
        }

        DB::beginTransaction();
        try 
        {
            foreach ($procurements as $docNo => $requestData) 
            {
                $this->info("Procurement Proccessed: " . $requestData['external_doc_no'] . " with " . count($requestData['items']) . " items.");
                Log::channel('import')->info("Procurement Proccessed: " . $requestData['external_doc_no']);
                $request = new Request($requestData);
                $response = $this->addProcurement($request);

                $result = $response->getData();

                if (!$response->getData()->status) 
                {
                    DB::rollBack();
                    Log::channel('import')->error("Failed to add procurement $docNo: " . json_encode($response->getData()->message));
                    throw new \Exception("Failed to add procurement $docNo: " . json_encode($response->getData()->message));
                }
            }
            DB::commit();
        } 
        catch (\Exception $e) 
        {
            DB::rollBack();
            Log::channel('import')->error("Error during procurement import: " . $e->getMessage());
            $this->error("Error during procurement import: " . $e->getMessage());
            return 1;
        }

        Log::channel('import')->info('Import procurement finished.');
        return 0;
    }

    public function addProcurement(Request $request)
    {
        $validator = Validator::make($request->all(), [
            "contact_id"        => "required",
            "location_id"       => "required",
            "items"             => "required",
            "procurement_date"  => "required"
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
            $date = strtotime($request->procurement_date);

            $procurementDate = date('Y-m-d H:i:s',$date);

            // document number
            $date = new DateTime('now');
            $date = $date->format('dmY');

            $documentNumber = '';
            $countDocNo = 1;

            while ($countDocNo > 0)
            {
                $seq = DB::select("
                    SELECT
                        count(doc_number) as seq
                    FROM
                        procurements
                    WHERE
                        DATE_FORMAT(created_at, '%d%m%Y') <= STR_TO_DATE(?, '%d%m%Y')
                        AND doc_number IS NOT NULL
                ", [$date]);
                
                $documentNumber = 'PO-'.$date.'-'.str_pad(($seq[0]->seq+1), 4, '0', STR_PAD_LEFT);

                $countDocNo = DB::select("
                    SELECT
                        count(doc_number) as seq
                    FROM
                        procurements
                    WHERE
                        doc_number = ?
                ", [$documentNumber])[0]->seq;
            }
            
            $taxes = explode(',', $request->tax_ids);

            $tax = Tax::whereIn('id', $taxes)->sum('value');
            $taxes = Tax::whereIn('id', $taxes)->get();

            $totalTax = [];
            foreach ($taxes as $key)
            {
                $totalTax[] = $key->value;
            }

            $totalAmount = 0;
            // insert procurement
            $procurement = AppProcurement::create([
                'contact_id'            => $request->contact_id,
                'procurement_date'      => $procurementDate,
                'amount'                => 0,
                'pay_status'            => "Paid",
                'created_by'            => auth()->user()->id,
                'updated_by'            => auth()->user()->id,
                'status'                => 1,
                'doc_number'            => $documentNumber,
                'include_tax'           => $request->include_tax == 1 ? true : false,
                'rounding'              => (float)($request->round),
                'external_doc_no'       => $request->external_doc_no,
                'location_id'           => $request->location_id
            ]);

            foreach ($request->items as $item)
            {
                $this->info("Processing item: " . $item['item_code'] . " with qty: " . $item['qty'] . ", price: " . $item['price'] . " and discounts: " . implode('|', $item['discounts'] ?? []));
                Log::channel('import')->info("Processing item: " . $item['item_code'] . " with qty: " . $item['qty'] . ", price: " . $item['price'] . " and discounts: " . implode('|', $item['discounts'] ?? []));

                $itemDet = ItemDetail::where('item_code', $item['item_code'])->where('location_id', $request->location_id)->where('status', 1)->first();

                $discounts = $item['discounts'] ?? [];

                if ($request->include_tax)
                {
                    $priceAfterDiscount = $item['price'];
                    foreach ($discounts as $discount)
                    {
                        $discount = $discount ?? 0 ? ($discount/100) * $priceAfterDiscount : 0;
                        
                        $priceAfterDiscount = $priceAfterDiscount - $discount;
                    }

                    $itemPrice = $priceAfterDiscount / (1 + $tax/100);
                    
                    $total = $item['qty'] * $itemPrice;
                }
                else
                {
                    $priceAfterDiscount = $item['price'];
                    foreach ($discounts as $discount)
                    {
                        $discount = $discount ?? 0 ? ($discount/100) * $priceAfterDiscount : 0;
                        
                        $priceAfterDiscount = $priceAfterDiscount - $discount;
                    }

                    $itemPrice = $priceAfterDiscount;

                    $total = $item['qty'] * $itemPrice;
                }

                // insert to item detail
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

                // insert to procurement detail
                ProcurementDetail::create([
                    'procurement_id'    => $procurement->id,
                    'item_detail_id'    => $itemDet['id'],
                    'qty'               => $item['qty'],
                    'price'             => $itemPrice,
                    'total'             => round($total, 2),
                    'tax_ids'           => $request->tax_ids,
                    'created_by'        => auth()->user()->id,
                    'updated_by'        => auth()->user()->id,
                    'status'            => 1,
                    'discount'          => implode('|', $discounts),
                    'initial_price'     => $item['price']
                ]);
                
                $totalAmount += ($total + $total * ($tax/100));
            }

            $totalAmount += $request->round;

            if ($request->rounding === 'down') 
            {
                $roundedAmount = floor($totalAmount);
            } 
            elseif ($request->rounding === 'up') 
            {
                $roundedAmount = ceil($totalAmount);
            } 
            else 
            {
                $roundedAmount = round($totalAmount,2);
            }

            $procurement->update([
                'amount'        => $roundedAmount,
                'updated_by'    => auth()->user()->id,
                'updated_at'    => date("Y-m-d H:i:s"),
                'tax'           => implode('|', $totalTax)
            ]);

            return response()->json([
                "status" => true,
                "message" => "Procurement Success"
            ]);
        }
        catch (\Throwable $e)
        {
            return response()->json([
                "status" => false,
                "message" => $e
            ], 500);
        }
    }

    function parseRupiah($str) 
    {
        // Remove 'Rp', spaces, and non-numeric characters except , and .
        $str = str_replace(['Rp', ' '], '', $str);
        // $str = str_replace('.', '', $str); // remove thousand separator
        $str = str_replace(',', '', $str); // change decimal separator
        return (float)$str;
    }

    function parseDiscount($str) 
    {
        // Remove 'Rp', spaces, and non-numeric characters except , and .
        $str = str_replace(['Rp', ' '], '', $str);
        // $str = str_replace('.', '', $str); // remove thousand separator
        $str = str_replace(',', '.', $str); // change decimal separator
        return (float)$str;
    }
}