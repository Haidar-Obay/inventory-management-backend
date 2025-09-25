<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;
use App\Models\CustomerGroup;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;
use Illuminate\Support\Facades\Cache;

class CustomerGroupController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_customer_groups";

        $customerGroups = app('cache')->store('database')->get($key);

        if (!$customerGroups) {
            $customerGroups = CustomerGroup::all();

            app('cache')->store('database')->forever($key, $customerGroups);
        }

        return response()->json([
            'status' => true,
            'message' => 'Customer groups fetched successfully.',
            'data' => $customerGroups,
        ]);
    }

    public function store(Request $request)
    {
        $tenantId = tenant('id');
        $customerGroup = CustomerGroup::create($request->all());
        app('cache')->store('database')->forget("tenant_{$tenantId}_customer_groups");
        return response()->json([
            'status' => true,
            'message' => 'Customer group created successfully.',
            'data' => $customerGroup,
        ], 201);
    }

    public function show(CustomerGroup $customerGroup)
    {
        return response()->json($customerGroup->load('customers'));
    }

    public function update(Request $request, CustomerGroup $customerGroup)
    {
        $tenantId = tenant('id');
        $customerGroup->update($request->all());
        app('cache')->store('database')->forget("tenant_{$tenantId}_customer_groups");

        return response()->json([
            'status' => true,
            'message' => 'Customer group updated successfully.',
            'data' => $customerGroup,
        ]);
    }

    public function destroy(CustomerGroup $customerGroup)
    {
        // Check if customer group has associated customers
        if ($customerGroup->customers()->exists()) {
            return response()->json([
                'status' => false,
                'message' => 'Cannot delete customer group. There are customers associated with this group. Please reassign or delete the customers first.',
            ], 422);
        }

        $tenantId = tenant('id');
        $customerGroup->delete();
        app('cache')->store('database')->forget("tenant_{$tenantId}_customer_groups");

        return response()->json([
            'status' => true,
            'message' => 'Customer group deleted successfully.',
        ]);
    }

    public function bulkDelete(Request $request)
    {
        $tenantId = tenant('id');
        $groupsWithCustomers = CustomerGroup::whereIn('id', $request->ids)
            ->whereHas('customers')
            ->pluck('id');

        if ($groupsWithCustomers->isNotEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'Some customer groups have associated customers and cannot be deleted',
                'groups_with_customers' => $groupsWithCustomers
            ], 400);
        }

        CustomerGroup::whereIn('id', $request->ids)->delete();
        app('cache')->store('database')->forget("tenant_{$tenantId}_customer_groups");

        return response()->json([
            'status' => true,
            'message' => 'Customer groups deleted successfully.',
        ]);
    }

    public function exportExcel()
    {
        $customerGroups = CustomerGroup::orderBy('name');
        $collection = $customerGroups->get();

        if ($collection->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No customer groups to export',
            ], 404);
        }

        $columns = ['id', 'code', 'name', 'active'];
        $headings = ['ID', 'Code', 'Name', 'Active'];

        $fileName = 'customer_groups_' . date('Y-m-d_H-i-s') . '.xlsx';
        return Excel::download(new Export($customerGroups, $columns, $headings), $fileName);
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $customerGroups = CustomerGroup::select('id', 'code', 'name', 'active')->get();

        if ($customerGroups->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No customer groups to export',
            ], 404);
        }

        $title = 'Customer Groups Report';
        $headers = [
            'id' => 'ID',
            'code' => 'Code', 
            'name' => 'Name',
            'active' => 'Active'
        ];

        $data = $customerGroups->toArray();
        $pdf = $pdfService->generatePdf($title, $headers, $data);
        
        return $pdf->download('customer_groups_' . date('Y-m-d_H-i-s') . '.pdf');
    }

    public function importFromExcel(Request $request)
    {
        $tenantId = tenant('id');
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

        // If type is 'fresh', delete all records first
        if ($request->input('type') === 'fresh') {
            // Get model class from the import
            CustomerGroup::truncate();
        }

        // If type is 'mapping', use provided mapping, else use default
        $mapping = $request->input('mapping');

        try {
            $import = new DynamicExcelImport(
                CustomerGroup::class,
                ['code', 'name', 'active'],
                function ($row) {
                    $errors = [];

                    $code = isset($row['code']) ? trim((string)$row['code']) : '';
                    $name = isset($row['name']) ? trim((string)$row['name']) : '';

                    if ($code === '') {
                        $errors[] = 'Missing code';
                    } elseif (CustomerGroup::where('code', $code)->exists()) {
                        $errors[] = "Customer group code '{$code}' already exists";
                    }

                    if ($name === '') {
                        $errors[] = 'Missing name';
                    }

                    return $errors;
                },
                function ($row) {
                    $code = trim((string)($row['code'] ?? ''));
                    $name = trim((string)($row['name'] ?? ''));
                    $active = isset($row['active']) ? (bool)$row['active'] : false;

                    return [
                        'code' => $code,
                        'name' => $name,
                        'active' => $active,
                    ];
                },
                true // Enable header validation
            );
            Excel::import($import, $request->file('file'));

            // Check if headers were valid
            if (!$import->areHeadersValid()) {
                $headerResult = $import->getHeaderValidationResult();
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid Excel file headers',
                    'header_validation' => $headerResult,
                    'errors' => [
                        'missing_headers' => $headerResult['missing'],
                        'extra_headers' => $headerResult['extra'],
                        'expected_headers' => $headerResult['expected_headers'],
                        'actual_headers' => $headerResult['excel_headers']
                    ]
                ], 422);
            }

            app('cache')->store('database')->forget("tenant_{$tenantId}_customer_groups");

            $imported = $import->getImportedCount();
            $skippedCount = $import->getSkippedCount();
            $skippedRows = $import->getSkippedRows();
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
                'skipped_rows' => $skippedRows,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Error importing customer groups: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function getNames()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_customer_group_names";

        $customerGroups = app('cache')->store('database')->get($key);

        if (!$customerGroups) {
            $customerGroups = CustomerGroup::where('active', true)
                ->select('id', 'code', 'name')
                ->orderBy('name')
                ->get()
                ->map(function ($customerGroup) {
                    return [
                        'id' => $customerGroup->id,
                        'code' => $customerGroup->code,
                        'name' => $customerGroup->name
                    ];
                });

            app('cache')->store('database')->forever($key, $customerGroups);
        }

        return response()->json([
            'status' => true,
            'message' => 'Customer group names fetched successfully.',
            'data' => $customerGroups,
        ]);
    }
}
