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

class CustomerMasterListController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_customer_master_lists";

        $customerMasterLists = app('cache')->store('database')->get($key);

        if (!$customerMasterLists) {
            $customerMasterLists = CustomerMasterList::select('id', 'date', 'name', 'valid_from', 'valid_till')
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

    public function export(Request $request)
    {
        try {
             $customerMasterLists = CustomerMasterList::with('items')->orderBy('name');
            $collection = $customerMasterLists->get();
            
            if ($collection->isEmpty()) {
                return response()->json([
                    'status' => false,
                    'message' => 'No customer master lists to export',
                ], 404);
            }

            $columns = ['id', 'date', 'name', 'valid_from', 'valid_till', 'itemcode', 'price', 'discount'];
            $headings = ['ID', 'Date', 'Name', 'Valid From', 'Valid Till', 'Item Code', 'Price', 'Discount'];

            $fileName = 'customer_master_lists_' . date('Y-m-d_H-i-s') . '.xlsx';
            return Excel::download(new Export($customerMasterLists, $columns, $headings), $fileName);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Export failed.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv',
        ]);

        try {
            $import = new DynamicExcelImport(
                CustomerMasterList::class,
                ['date', 'name', 'valid_from', 'valid_till', 'itemcode', 'price', 'discount'],
                function ($row) {
                    $errors = [];

                    if (empty($row['date'])) {
                        $errors[] = 'Missing date';
                    }
                    if (empty($row['name'])) {
                        $errors[] = 'Missing name';
                    }
                    if (empty($row['valid_from'])) {
                        $errors[] = 'Missing valid from date';
                    }
                    if (empty($row['valid_till'])) {
                        $errors[] = 'Missing valid till date';
                    }
                    if (empty($row['price']) || !is_numeric($row['price'])) {
                        $errors[] = 'Invalid price';
                    }

                    return $errors;
                },
                function ($row) {
                    return [
                        'date' => $row['date'],
                        'name' => $row['name'],
                        'valid_from' => $row['valid_from'],
                        'valid_till' => $row['valid_till'],
                        'itemcode' => $row['itemcode'] ?? null,
                        'price' => floatval($row['price']),
                        'discount' => isset($row['discount']) ? floatval($row['discount']) : 0.00,
                    ];
                }
            );

            Excel::import($import, $request->file('file'));

            // Clear cache
            $tenantId = tenant('id');
            app('cache')->store('database')->forget("tenant_{$tenantId}_customer_master_lists");

            return response()->json([
                'status' => true,
                'message' => 'Customer master lists imported successfully.',
                'rows_imported' => $import->getImportedCount(),
                'rows_skipped_count' => $import->getSkippedCount(),
                'skipped_rows' => $import->getSkippedRows(),
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
        $customerMasterLists = CustomerMasterList::with('items')
            ->select('id', 'date', 'name', 'valid_from', 'valid_till', 'itemcode', 'price', 'discount')
            ->get();

        if ($customerMasterLists->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No customer master lists to export',
            ], 404);
        }

        $title = 'Customer Master Lists Report';
        $headers = [
            'id' => 'ID',
            'date' => 'Date',
            'name' => 'Name',
            'valid_from' => 'Valid From',
            'valid_till' => 'Valid Till',
            'itemcode' => 'Item Code',
            'price' => 'Price',
            'discount' => 'Discount (%)',
            'items_count' => 'Items Count'
        ];
        
        // Transform data to include items count and format properly
        $data = $customerMasterLists->map(function ($list) {
            return [
                'id' => $list->id,
                'date' => $list->date->format('Y-m-d'),
                'name' => $list->name,
                'valid_from' => $list->valid_from->format('Y-m-d'),
                'valid_till' => $list->valid_till->format('Y-m-d'),
                'itemcode' => $list->itemcode ?? 'N/A',
                'price' => number_format($list->price, 2),
                'discount' => number_format($list->discount, 2),
                'items_count' => $list->items->count()
            ];
        })->toArray();

        $pdf = $pdfService->generatePdf($title, $headers, $data);
        return $pdf->download('CustomerMasterLists.pdf');
    }
}
