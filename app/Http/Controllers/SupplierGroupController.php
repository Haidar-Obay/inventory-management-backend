<?php

namespace App\Http\Controllers;

use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;
use App\Models\SupplierGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

class SupplierGroupController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_supplier_groups";

        $supplierGroups = app('cache')->store('database')->get($key);

        if (! $supplierGroups) {
            $supplierGroups = SupplierGroup::orderBy('name')->get();

            app('cache')->store('database')->forever($key, $supplierGroups);
        }

        return response()->json([
            'status' => true,
            'message' => 'Supplier groups fetched successfully.',
            'data' => $supplierGroups,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|unique:supplier_groups,code',
            'name' => 'required|string',
            'active' => 'boolean',
        ]);

        $supplierGroup = SupplierGroup::create($validated);

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_supplier_groups");

        return response()->json([
            'status' => true,
            'message' => 'Supplier group created successfully.',
            'data' => $supplierGroup,
        ], 201);
    }

    public function show(SupplierGroup $supplierGroup)
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_supplier_group_{$supplierGroup->id}";

        $cachedSupplierGroup = app('cache')->store('database')->get($key);

        if (! $cachedSupplierGroup) {
            $cachedSupplierGroup = $supplierGroup;

            app('cache')->store('database')->forever($key, $cachedSupplierGroup);
        }

        return response()->json([
            'status' => true,
            'message' => 'Supplier group details fetched successfully.',
            'data' => $cachedSupplierGroup,
        ]);
    }

    public function update(Request $request, SupplierGroup $supplierGroup)
    {
        $validated = $request->validate([
            'code' => [
                'sometimes',
                'string',
                Rule::unique('supplier_groups', 'code')->ignore($supplierGroup->id),
            ],
            'name' => 'sometimes|string',
            'active' => 'sometimes|boolean',
        ]);

        $supplierGroup->update($validated);

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_supplier_groups");
        app('cache')->store('database')->forget("tenant_{$tenantId}_supplier_group_{$supplierGroup->id}");

        return response()->json([
            'status' => true,
            'message' => 'Supplier group updated successfully.',
            'data' => $supplierGroup,
        ]);
    }

    public function destroy(SupplierGroup $supplierGroup)
    {
        // Check if supplier group has suppliers
        if ($supplierGroup->suppliers()->exists()) {
            return response()->json([
                'status' => false,
                'message' => 'Cannot delete supplier group with associated suppliers',
            ], 422);
        }

        $supplierGroup->delete();

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_supplier_groups");
        app('cache')->store('database')->forget("tenant_{$tenantId}_supplier_group_{$supplierGroup->id}");

        return response()->json([
            'status' => true,
            'message' => 'Supplier group deleted successfully.',
        ]);
    }

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:supplier_groups,id',
        ]);

        $ids = $request->input('ids');
        $skipped = [];
        $deleted = 0;

        foreach ($ids as $id) {
            try {
                $supplierGroup = SupplierGroup::find($id);
                
                if (!$supplierGroup) {
                    $skipped[] = [
                        'id' => $id,
                        'reason' => 'Supplier group not found.',
                    ];
                    continue;
                }

                // Check if supplier group has associated suppliers
                if ($supplierGroup->suppliers()->exists()) {
                    $skipped[] = [
                        'id' => $id,
                        'reason' => 'Cannot delete supplier group. It is being used by one or more suppliers.',
                    ];
                    continue;
                }

                $supplierGroup->delete();
                $deleted++;
                
            } catch (\Exception $e) {
                Log::error('Error deleting supplier group '.$id.': '.$e->getMessage());
                $skipped[] = [
                    'id' => $id, 
                    'reason' => $e->getMessage()
                ];
            }
        }

        // Invalidate cache after bulk delete
        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_supplier_groups");

        return response()->json([
            'message' => 'Bulk delete completed.',
            'deleted_count' => $deleted,
            'skipped' => $skipped,
        ]);
    }

    public function exportExcell()
    {
        $supplierGroups = SupplierGroup::orderBy('name');

        if ($supplierGroups->count() === 0) {
            return response()->json(['message' => 'No supplier groups found.'], 404);
        }

        $columns = ['id', 'code', 'name', 'active',
            'created_at',
            'updated_at'];
        $headings = ['ID', 'Code', 'Name', 'Active',
            'Created At', 'Updated At'];

        return Excel::download(new Export($supplierGroups, $columns, $headings), 'supplier_groups.xlsx');
    }

    public function exportPdf()
    {
        $supplierGroups = SupplierGroup::select('id', 'code', 'name', 'active')->get();

        if ($supplierGroups->isEmpty()) {
            return response()->json(['message' => 'No supplier groups found.'], 404);
        }

        $title = 'Supplier Group Report';
        $headers = [
            'id' => 'ID',
            'code' => 'Code',
            'name' => 'Name',
            'active' => 'Active',
        ];
        $data = $supplierGroups->toArray();

        $pdf = app(ExportPDF::class)->generatePdf($title, $headers, $data);

        return $pdf->download('SupplierGroups.pdf');
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

        // If type is 'fresh', delete all records first
        if ($request->input('type') === 'fresh') {
            // Get model class from the import
            SupplierGroup::truncate();
        }

        // If type is 'mapping', use provided mapping, else use default
        $mapping = $request->input('mapping');

        try {
            $import = new DynamicExcelImport(
                SupplierGroup::class,
                ['code', 'name'],
                function ($row) {
                    foreach ($row as $k => $v) {
                        if (is_string($v)) {
                            $row[$k] = trim($v);
                        }
                    }
                    $errors = [];

                    if (($row['code'] ?? '') === '') {
                        $errors[] = 'Missing code';
                    }
                    if (($row['name'] ?? '') === '') {
                        $errors[] = 'Missing name';
                    }

                    return $errors;
                },
                function ($row) {
                    foreach ($row as $k => $v) {
                        if (is_string($v)) {
                            $row[$k] = trim($v);
                        }
                    }

                    return [
                        'code' => $row['code'] ?? null,
                        'name' => $row['name'] ?? null,
                        'active' => $row['active'] ?? true,
                    ];
                },
                true // Enable header validation
            );

            Excel::import($import, $request->file('file'));

            // Check if headers were valid
            if (! $import->areHeadersValid()) {
                $headerResult = $import->getHeaderValidationResult();

                return response()->json([
                    'success' => false,
                    'message' => 'Invalid Excel file headers',
                    'header_validation' => $headerResult,
                    'errors' => [
                        'missing_headers' => $headerResult['missing'],
                        'extra_headers' => $headerResult['extra'],
                        'expected_headers' => $headerResult['expected_headers'],
                        'actual_headers' => $headerResult['excel_headers'],
                    ],
                ], 422);
            }

            app('cache')->store('database')->forget('tenant_'.tenant('id').'_supplier_groups');

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
                'message' => 'Error importing supplier groups: '.$e->getMessage(),
            ], 500);
        }
    }
}
