<?php

namespace App\Http\Controllers;

use App\Models\CustomerMasterList;
use App\Models\Item;
use Illuminate\Http\Request;
use App\Http\Requests\CustomerMasterList\StoreCustomerMasterListRequest;
use App\Http\Requests\CustomerMasterList\UpdateCustomerMasterListRequest;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;
use Illuminate\Support\Facades\Log;

class CustomerMasterListController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_customer_master_lists";

        $customerMasterLists = app('cache')->store('database')->get($key);

        if (!$customerMasterLists) {
            $customerMasterLists = CustomerMasterList::select('id', 'date', 'name', 'valid_from', 'valid_till', 'created_at', 'updated_at')
                ->orderBy('name')
                ->get();
            app('cache')->store('database')->forever($key, $customerMasterLists);
        }

        return response()->json([
            'status' => true,
            'message' => 'Customer master lists fetched successfully.',
            'data' => $customerMasterLists,
        ]);
    }

    public function show(CustomerMasterList $customerMasterList)
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_customer_master_list_{$customerMasterList->id}";

        $cachedCustomerMasterList = app('cache')->store('database')->get($key);

        if (!$cachedCustomerMasterList) {
            $cachedCustomerMasterList = CustomerMasterList::with('items')
                ->find($customerMasterList->id);
            app('cache')->store('database')->forever($key, $cachedCustomerMasterList);
        }

        return response()->json([
            'status' => true,
            'message' => 'Customer master list details fetched successfully.',
            'data' => $cachedCustomerMasterList,
        ]);
    }

    public function store(StoreCustomerMasterListRequest $request)
    {
        try {
            $validated = $request->validated();

            // Check if name already exists
            $existingMasterList = CustomerMasterList::where('name', $validated['name'])->first();
            if ($existingMasterList) {
                return response()->json([
                    'status' => false,
                    'message' => 'A customer master list with this name already exists. Please choose a different name.',
                ], 422);
            }

            // Create header
            $customerMasterList = CustomerMasterList::create([
                'date' => $validated['date'],
                'name' => $validated['name'],
                'valid_from' => $validated['valid_from'],
                'valid_till' => $validated['valid_till'],
            ]);

            // Attach items with pivot attributes
            $attach = [];
            foreach ($validated['items'] as $row) {
                $attach[$row['item_id']] = [
                    'price' => $row['price'],
                    'discount' => $row['discount'] ?? 0,
                ];
            }
            $customerMasterList->items()->attach($attach);

            // Clear cache
            $tenantId = tenant('id');
            app('cache')->store('database')->forget("tenant_{$tenantId}_customer_master_lists");

            // Return full data with pivot information
            $data = $customerMasterList->load('items');

            return response()->json([
                'status' => true,
                'message' => 'Customer master list created successfully.',
                'data' => $data,
            ], 201);
        } catch (\Exception $e) {
            // Check if it's a duplicate name error
            if (str_contains($e->getMessage(), 'Duplicate entry') && str_contains($e->getMessage(), 'customer_master_lists_name_unique')) {
                return response()->json([
                    'status' => false,
                    'message' => 'A customer master list with this name already exists. Please choose a different name.',
                ], 422);
            }
            
            return response()->json([
                'status' => false,
                'message' => 'Failed to create customer master list.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(UpdateCustomerMasterListRequest $request, CustomerMasterList $customerMasterList)
    {
        try {
            $validated = $request->validated();

            // Check if name is being updated and if it's unique
            if (isset($validated['name']) && $validated['name'] !== $customerMasterList->name) {
                $existingMasterList = CustomerMasterList::where('name', $validated['name'])->first();
                if ($existingMasterList) {
                    return response()->json([
                        'status' => false,
                        'message' => 'A customer master list with this name already exists. Please choose a different name.',
                    ], 422);
                }
            }

            // Update header
            $customerMasterList->update(array_filter([
                'date' => $validated['date'] ?? null,
                'name' => $validated['name'] ?? null,
                'valid_from' => $validated['valid_from'] ?? null,
                'valid_till' => $validated['valid_till'] ?? null,
            ], fn($v) => !is_null($v)));

            // Sync items with pivot if provided
            if (isset($validated['items'])) {
                $sync = [];
                foreach ($validated['items'] as $row) {
                    $sync[$row['item_id']] = [
                        'price' => $row['price'],
                        'discount' => $row['discount'] ?? 0,
                    ];
                }
                $customerMasterList->items()->sync($sync);
            }

            // Clear cache
            $tenantId = tenant('id');
            $cacheKeys = [
                "tenant_{$tenantId}_customer_master_lists",
                "tenant_{$tenantId}_customer_master_list_{$customerMasterList->id}"
            ];
            foreach ($cacheKeys as $key) {
                app('cache')->store('database')->forget($key);
            }

            // Return full data with pivot information
            $data = $customerMasterList->load('items');

            return response()->json([
                'status' => true,
                'message' => 'Customer master list updated successfully.',
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            // Check if it's a duplicate name error
            if (str_contains($e->getMessage(), 'Duplicate entry') && str_contains($e->getMessage(), 'customer_master_lists_name_unique')) {
                return response()->json([
                    'status' => false,
                    'message' => 'A customer master list with this name already exists. Please choose a different name.',
                ], 422);
            }
            
            return response()->json([
                'status' => false,
                'message' => 'Failed to update customer master list.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(CustomerMasterList $customerMasterList)
    {
        try {
            $customerMasterList->delete();

            // Clear cache
            $tenantId = tenant('id');
            $cacheKeys = [
                "tenant_{$tenantId}_customer_master_lists",
                "tenant_{$tenantId}_customer_master_list_{$customerMasterList->id}"
            ];
            
            foreach ($cacheKeys as $key) {
                app('cache')->store('database')->forget($key);
            }

            return response()->json([
                'status' => true,
                'message' => 'Customer master list deleted successfully.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to delete customer master list.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function active()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_active_customer_master_lists";

        $activeLists = app('cache')->store('database')->get($key);

        if (!$activeLists) {
            $activeLists = CustomerMasterList::select('id', 'date', 'name', 'valid_from', 'valid_till')
                ->active()
                ->orderBy('name')
                ->get();
            app('cache')->store('database')->put($key, $activeLists, 3600); // Cache for 1 hour
        }

        return response()->json([
            'status' => true,
            'message' => 'Active customer master lists fetched successfully.',
            'data' => $activeLists,
        ]);
    }

    public function validOn(Request $request)
    {
        $request->validate([
            'date' => 'required|date',
        ]);

        $date = $request->date;
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_customer_master_lists_valid_on_{$date}";

        $validLists = app('cache')->store('database')->get($key);

        if (!$validLists) {
            $validLists = CustomerMasterList::select('id', 'date', 'name', 'valid_from', 'valid_till')
                ->validOn($date)
                ->orderBy('name')
                ->get();
            app('cache')->store('database')->put($key, $validLists, 3600); // Cache for 1 hour
        }

        return response()->json([
            'status' => true,
            'message' => "Customer master lists valid on {$date} fetched successfully.",
            'data' => $validLists,
        ]);
    }

    public function attachItems(Request $request, CustomerMasterList $customerMasterList)
    {
        $request->validate([
            'item_ids' => 'required|array',
            'item_ids.*' => 'exists:items,id',
        ]);

        try {
            $customerMasterList->items()->attach($request->item_ids);
            $customerMasterList->load('items');

            // Clear cache
            $tenantId = tenant('id');
            $cacheKeys = [
                "tenant_{$tenantId}_customer_master_lists",
                "tenant_{$tenantId}_customer_master_list_{$customerMasterList->id}"
            ];
            
            foreach ($cacheKeys as $key) {
                app('cache')->store('database')->forget($key);
            }

            return response()->json([
                'status' => true,
                'message' => 'Items attached to customer master list successfully.',
                'data' => $customerMasterList,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to attach items to customer master list.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function detachItems(Request $request, CustomerMasterList $customerMasterList)
    {
        $request->validate([
            'item_ids' => 'required|array',
            'item_ids.*' => 'exists:items,id',
        ]);

        try {
            $customerMasterList->items()->detach($request->item_ids);
            $customerMasterList->load('items');

            // Clear cache
            $tenantId = tenant('id');
            $cacheKeys = [
                "tenant_{$tenantId}_customer_master_lists",
                "tenant_{$tenantId}_customer_master_list_{$customerMasterList->id}"
            ];
            
            foreach ($cacheKeys as $key) {
                app('cache')->store('database')->forget($key);
            }

            return response()->json([
                'status' => true,
                'message' => 'Items detached from customer master list successfully.',
                'data' => $customerMasterList,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to detach items from customer master list.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function exportExcell()
    {
        $query = CustomerMasterList::query()->with(['items']);
        $collection = $query->get();

        if ($collection->isEmpty()) {
            return response()->json(['message' => 'No customer master lists found.'], 404);
        }

        $columns = [
            'id',
            'date',
            'name',
            'valid_from',
            'valid_till',
            'items.*.name',
            'items.*.pivot.price',
            'items.*.pivot.discount',
            'created_at',
            'updated_at',
        ];

        $headings = [
            'ID',
            'Date',
            'Name',
            'Valid From',
            'Valid Till',
            'Items',
            'Prices',
            'Discounts',
            'Created At',
            'Updated At',
        ];

        $fileName = 'customer_master_lists.xlsx';
        return Excel::download(new Export($query, $columns, $headings), $fileName);
    }

    public function importFromExcel(Request $request)
    {
        $request->validate([
            'file' => [
                'required',
                'file',
                'mimes:xlsx,xls,csv,txt,text/plain,text/csv,application/csv',
            ],
            'type' => 'nullable|string|in:fresh,mapping',
            'mapping' => 'nullable|array',
        ], [
            'file.mimes' => 'The file field must be a file of type: xlsx, xls, csv',
        ]);

        try {
            // If type is 'fresh', delete all records first so duplicate detection does not skip rows
            if ($request->input('type') === 'fresh') {
                CustomerMasterList::truncate();
            }

            // Custom import logic for CustomerMasterList with many-to-many relationship
            $imported = 0;
            $skipped = [];
            $currentRow = 1;

            // Read Excel file with proper header handling
            $data = Excel::toArray(new \stdClass(), $request->file('file'));
            $allRows = $data[0] ?? [];
            
            // If first row contains headers, use them as keys
            $rows = [];
            if (!empty($allRows)) {
                $headers = array_shift($allRows); // Remove first row and use as headers
                $rows = array_map(function($row) use ($headers) {
                    return array_combine($headers, array_pad($row, count($headers), ''));
                }, $allRows);
                
                // Debug: Log headers for troubleshooting
                Log::info('Excel headers found:', $headers);
            }


            // Date parser helper
            $parseDate = function ($value) {
                if ($value === null || $value === '') { return null; }
                if (is_numeric($value)) {
                    try {
                        $dt = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$value);
                        return \Carbon\Carbon::instance($dt)->format('Y-m-d');
                    } catch (\Throwable $e) {}
                }
                foreach (['n/j/Y', 'm/d/Y', 'Y-m-d'] as $fmt) {
                    try { return \Carbon\Carbon::createFromFormat($fmt, (string)$value)->format('Y-m-d'); } catch (\Throwable $e) {}
                }
                try { return \Carbon\Carbon::parse((string)$value)->format('Y-m-d'); } catch (\Throwable $e) { return null; }
            };

            // Group rows by master list name
            $masterLists = [];
            foreach ($rows as $index => $row) {
                $currentRow = $index + 2; // +2 because Excel is 1-indexed (header is row 1, data starts at row 2)
                
                // Clean row data
                foreach ($row as $k => $v) { 
                    if (is_string($v)) { 
                        $row[$k] = trim($v); 
                    } 
                }
                
                // Debug: Log row data for troubleshooting
                Log::info("Row {$currentRow} data:", $row);


                // Validate required fields - try different possible column names
                    $errors = [];

                // Try different possible column names for date
                $dateValue = $row['date'] ?? $row['Date'] ?? $row['DATE'] ?? '';
                if ($dateValue === '') {
                        $errors[] = 'Missing date';
                }
                
                // Try different possible column names for name
                $nameValue = $row['name'] ?? $row['Name'] ?? $row['NAME'] ?? '';
                if ($nameValue === '') {
                    $errors[] = 'Missing name';
                }
                
                // Try different possible column names for valid_from
                $validFromValue = $row['valid_from'] ?? $row['Valid From'] ?? $row['valid from'] ?? $row['VALID_FROM'] ?? '';
                if ($validFromValue === '') {
                    $errors[] = 'Missing valid from date';
                }
                
                // Try different possible column names for valid_till
                $validTillValue = $row['valid_till'] ?? $row['Valid Till'] ?? $row['valid till'] ?? $row['VALID_TILL'] ?? '';
                if ($validTillValue === '') {
                    $errors[] = 'Missing valid till date';
                }

                // Validate date formats (allow Excel serials and common text formats)
                $isValidDate = function ($v) use ($parseDate) {
                    if ($v === '' || $v === null) return false;
                    return $parseDate($v) !== null;
                };
                foreach ([['Date', $dateValue], ['Valid From', $validFromValue], ['Valid Till', $validTillValue]] as [$label, $val]) {
                    if ($val !== '' && !$isValidDate($val)) {
                        $errors[] = "$label has invalid date";
                    }
                }
                
                
                // Unified field names that support both single values and JSON arrays
                $itemCodesValue = $row['item_code(s)'] ?? $row['itemcode'] ?? $row['Item Code'] ?? $row['item code'] ?? $row['ITEMCODE'] ?? $row['items'] ?? $row['Items'] ?? $row['ITEMS'] ?? '';
                $pricesValue = $row['price(s)'] ?? $row['price'] ?? $row['Price'] ?? $row['PRICE'] ?? $row['prices'] ?? $row['Prices'] ?? $row['PRICES'] ?? '';
                $discountsValue = $row['discount(s)'] ?? $row['discount'] ?? $row['Discount'] ?? $row['DISCOUNT'] ?? $row['discounts'] ?? $row['Discounts'] ?? $row['DISCOUNTS'] ?? '';

                $itemsArray = [];
                $pricesArray = [];
                $discountsArray = [];

                // Process item codes - try JSON array first, then single value
                if (!empty($itemCodesValue)) {
                    // Try to decode as JSON array
                    $decodedItems = json_decode($itemCodesValue, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decodedItems)) {
                        $itemsArray = $decodedItems;
                    } else {
                        // Treat as single value
                        $itemsArray = [$itemCodesValue];
                    }
                }

                // Process prices - try JSON array first, then single value
                if (!empty($pricesValue)) {
                    // Try to decode as JSON array
                    $decodedPrices = json_decode($pricesValue, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decodedPrices)) {
                        $pricesArray = $decodedPrices;
                    } else {
                        // Treat as single value - apply to all items
                        $pricesArray = array_fill(0, count($itemsArray), $pricesValue);
                    }
                } else {
                    // Default to 0.00 for each item if no prices provided
                    $pricesArray = array_fill(0, count($itemsArray), 0.00);
                }

                // Process discounts - try JSON array first, then single value
                if (!empty($discountsValue)) {
                    // Try to decode as JSON array
                    $decodedDiscounts = json_decode($discountsValue, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decodedDiscounts)) {
                        $discountsArray = $decodedDiscounts;
                    } else {
                        // Treat as single value - apply to all items
                        $discountsArray = array_fill(0, count($itemsArray), $discountsValue);
                    }
                } else {
                    // Default to 0.00 for each item if no discounts provided
                    $discountsArray = array_fill(0, count($itemsArray), 0.00);
                }

                // Validate arrays have same length
                if (!empty($itemsArray) && !empty($pricesArray) && count($itemsArray) !== count($pricesArray)) {
                    $errors[] = 'Items and prices arrays must have same length';
                }

                if (!empty($itemsArray) && !empty($discountsArray) && count($itemsArray) !== count($discountsArray)) {
                    $errors[] = 'Items and discounts arrays must have same length';
                }

                // Validate each item exists
                if (!empty($itemsArray)) {
                    foreach ($itemsArray as $itemCode) {
                        $itemExists = Item::where('code', $itemCode)->exists();
                        if (!$itemExists) {
                            $errors[] = "Item code '{$itemCode}' does not exist in items table";
                        }
                    }
                } else {
                    $errors[] = 'Missing item_code(s)';
                }

                if (!empty($errors)) {
                    $skipped[] = [
                        'row' => $currentRow,
                        'reasons' => $errors,
                    ];
                    continue;
                }

                // Group by master list name
                $masterListName = $nameValue;
                if (!isset($masterLists[$masterListName])) {
                    $masterLists[$masterListName] = [
                        'date' => $parseDate($dateValue),
                        'name' => $nameValue,
                        'valid_from' => $parseDate($validFromValue),
                        'valid_till' => $parseDate($validTillValue),
                        'items' => []
                    ];
                }
                // Add all items from this row to the master list
                for ($i = 0; $i < count($itemsArray); $i++) {
                    $masterLists[$masterListName]['items'][] = [
                        'itemcode' => $itemsArray[$i],
                        'price' => isset($pricesArray[$i]) ? floatval($pricesArray[$i]) : 0.00,
                        'discount' => isset($discountsArray[$i]) ? floatval($discountsArray[$i]) : 0.00,
                    ];
                }
            }

            // Process each master list
            foreach ($masterLists as $masterListData) {
                try {
                    // Check if master list already exists (unless fresh import)
                    if ($request->input('type') !== 'fresh') {
                        $existingMasterList = CustomerMasterList::where('name', $masterListData['name'])->first();
                        if ($existingMasterList) {
                            $skipped[] = [
                                'row' => 'Multiple',
                                'reasons' => ['Master list with this name already exists'],
                            ];
                            continue;
                        }
                    }

                    // If no valid items, skip this master list
                    if (empty($masterListData['items'])) {
                        $skipped[] = [
                            'row' => 'Multiple',
                            'reasons' => ['Master list has no valid items'],
                        ];
                        continue;
                    }

                    // Create the master list
                    $customerMasterList = CustomerMasterList::create([
                        'date' => $masterListData['date'],
                        'name' => $masterListData['name'],
                        'valid_from' => $masterListData['valid_from'],
                        'valid_till' => $masterListData['valid_till'],
                    ]);

                    // Attach items with pivot data
                    $attach = [];
                    foreach ($masterListData['items'] as $itemData) {
                        // Find item by code (should exist since we validated above)
                        $item = Item::where('code', $itemData['itemcode'])->first();
                        if ($item) {
                            $attach[$item->id] = [
                                'price' => $itemData['price'],
                                'discount' => $itemData['discount'],
                            ];
                        }
                    }

                    $customerMasterList->items()->attach($attach);

                    $imported++;

                } catch (\Exception $e) {
                    $skipped[] = [
                        'row' => 'Multiple',
                        'reasons' => ['Failed to create master list: ' . $e->getMessage()],
                    ];
                }
            }

            // Clear cache
            $tenantId = tenant('id');
            app('cache')->store('database')->forget("tenant_{$tenantId}_customer_master_lists");

            $skippedCount = count($skipped);
            $totalProcessed = $imported + $skippedCount;

            $message = '';
            if ($imported > 0 && $skippedCount === 0) {
                $message = "Imported {$imported} row(s) successfully.";
            } elseif ($imported > 0 && $skippedCount > 0) {
                $message = "Partially imported: {$imported} row(s) added, {$skippedCount} row(s) skipped.";
            } elseif ($imported === 0 && $skippedCount > 0) {
                $message = 'No rows imported. All rows were skipped due to validation errors or duplicates.';
            } else {
                $message = 'No rows found to import.';
            }


            return response()->json([
                'success' => $imported > 0,
                'message' => $message,
                'rows_processed' => $totalProcessed,
                'rows_imported' => $imported,
                'rows_skipped_count' => $skippedCount,
                'skipped_rows' => $skipped,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Import failed.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $customerMasterLists = CustomerMasterList::query()
            ->with(['items'])
            ->get([
                'id', 'date', 'name', 'valid_from', 'valid_till', 'created_at', 'updated_at'
            ]);

        if ($customerMasterLists->isEmpty()) {
            return response()->json(['message' => 'No customer master lists found.'], 404);
        }

        $title = 'Customer Master Lists Report';
        $headers = [
            'id' => 'ID',
            'date' => 'Date',
            'name' => 'Name',
            'valid_from' => 'Valid From',
            'valid_till' => 'Valid Till', 
            'items' => 'Items',
            'prices' => 'Prices',
            'discounts' => 'Discounts',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
        ];

        // Transform data to include related items information
        $data = $customerMasterLists->map(function ($list) {
            return [
                'id' => $list->id,
                'date' => $list->date,
                'name' => $list->name,
                'valid_from' => $list->valid_from,
                'valid_till' => $list->valid_till,
                'items' => $list->items->pluck('name')->values()->all(),
                'prices' => $list->items->pluck('pivot.price')->values()->all(),
                'discounts' => $list->items->pluck('pivot.discount')->values()->all(),
                'created_at' => $list->created_at,
                'updated_at' => $list->updated_at,
            ];
        })->toArray();

        $pdf = $pdfService->generatePdf($title, $headers, $data);
        return $pdf->download('customer_master_lists.pdf');
    }
}
