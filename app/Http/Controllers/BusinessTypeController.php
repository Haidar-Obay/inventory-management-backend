<?php

namespace App\Http\Controllers;

use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;
use App\Models\BusinessType;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

class BusinessTypeController extends Controller
{
    public function index()
    {
        // $tenantId = tenant('id');
        // $key = "tenant_{$tenantId}_business_types";

        // $businessTypes = app('cache')->store('database')->get($key);

        // if (!$businessTypes) {
        //     $businessTypes = BusinessType::orderBy('name')->get();
        //     app('cache')->store('database')->forever($key, $businessTypes);
        // }

        return response()->json([
            'status' => true,
            'message' => 'Business types fetched successfully.',
            'data' => BusinessType::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|max:50|unique:business_types,code',
            'name' => 'required|string|max:255',
        ]);

        $nextId = $this->computeNextAvailableId(BusinessType::class, 'id');
        $businessType = new BusinessType($validated);
        $businessType->id = $nextId;
        $businessType->save();

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_business_types");

        return response()->json([
            'status' => true,
            'message' => 'Business type created successfully.',
            'data' => $businessType,
        ], 201);
    }

    public function show(BusinessType $businessType)
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_business_type_{$businessType->id}";

        $cachedBusinessType = app('cache')->store('database')->get($key);

        if (! $cachedBusinessType) {
            $cachedBusinessType = $businessType;
            app('cache')->store('database')->forever($key, $cachedBusinessType);
        }

        return response()->json([
            'status' => true,
            'message' => 'Business type details fetched successfully.',
            'data' => $cachedBusinessType,
        ]);
    }

    public function update(Request $request, BusinessType $businessType)
    {
        $validated = $request->validate([
            'code' => [
                'sometimes',
                'string',
                'max:50',
                Rule::unique('business_types', 'code')->ignore($businessType->id),
            ],
            'name' => 'sometimes|string|max:255',
        ]);

        $businessType->update($validated);

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_business_types");
        app('cache')->store('database')->forget("tenant_{$tenantId}_business_type_{$businessType->id}");

        return response()->json([
            'status' => true,
            'message' => 'Business type updated successfully.',
            'data' => $businessType,
        ]);
    }

    public function destroy(BusinessType $businessType)
    {
        // Prevent deletion if related customers or suppliers exist; include helpful details
        $customersCount = $businessType->customers()->count();
        $suppliersCount = \App\Models\Supplier::where('business_type_id', $businessType->id)->count();

        if ($customersCount > 0 || $suppliersCount > 0) {
            $details = [];
            if ($customersCount > 0) {
                $details['customers'] = [
                    'count' => $customersCount,
                    'sample_ids' => $businessType->customers()->select('customers.id')->limit(1)->pluck('id'),
                ];
            }
            if ($suppliersCount > 0) {
                $details['suppliers'] = [
                    'count' => $suppliersCount,
                    'sample_ids' => \App\Models\Supplier::where('business_type_id', $businessType->id)
                        ->select('suppliers.id')
                        ->limit(1)
                        ->pluck('id'),
                ];
            }

            return response()->json([
                'status' => false,
                'message' => 'Cannot delete business type. It is referenced by existing customers or suppliers.',
                'details' => $details,
            ], 409);
        }

        $businessType->delete();

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_business_types");
        app('cache')->store('database')->forget("tenant_{$tenantId}_business_type_{$businessType->id}");

        return response()->json([
            'status' => true,
            'message' => 'Business type deleted successfully.',
        ]);
    }

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:business_types,id',
        ]);

        $tenantId = tenant('id');
        $skipped = [];
        $deleted = 0;

        foreach ($request->ids as $id) {
            $businessType = BusinessType::find($id);

            // Check if the business type has any customers linked to it
            if ($businessType->customers()->exists()) {
                $skipped[] = [
                    'id' => $id,
                    'reason' => 'Cannot delete business type. It is being used by one or more customers.',
                ];

                continue;
            }

            try {
                $deleted += $businessType->delete();
                app('cache')->store('database')->forget("tenant_{$tenantId}_business_type_{$id}");
            } catch (\Illuminate\Database\QueryException $e) {
                // Check if it's a foreign key constraint error
                if ($e->getCode() == '23503') {
                    $skipped[] = [
                        'id' => $id,
                        'reason' => 'Cannot delete business type. It is being used by one or more customers.',
                    ];
                } else {
                    $skipped[] = ['id' => $id, 'reason' => $e->getMessage()];
                }
            }
        }

        app('cache')->store('database')->forget("tenant_{$tenantId}_business_types");

        return response()->json([
            'message' => 'Bulk delete completed.',
            'deleted_count' => $deleted,
            'skipped' => $skipped,
        ]);
    }

    public function exportExcell()
    {
        $businessTypes = BusinessType::orderBy('name');
        $collection = $businessTypes->get();

        if ($collection->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No business types to export',
            ], 404);
        }

        $columns = ['id', 'code', 'name', 'created_at', 'updated_at'];
        $headings = ['ID', 'Code', 'Name', 'Created At', 'Updated At'];

        $fileName = 'business_types_'.'.xlsx';

        return Excel::download(new Export($businessTypes, $columns, $headings), $fileName);
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $businessTypes = BusinessType::select('id', 'code', 'name')->get();

        if ($businessTypes->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No business types to export',
            ], 404);
        }

        $title = 'Business Types Report';
        $headers = ['id' => 'ID', 'code' => 'Code', 'name' => 'Name', 'created_at' => 'Created At', 'updated_at' => 'Updated At', 'created_at' => 'Created At', 'updated_at' => 'Updated At'];
        $data = $businessTypes->toArray();

        $pdf = $pdfService->generatePdf($title, $headers, $data);

        return $pdf->download('BusinessTypes.pdf');
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
            BusinessType::truncate();
        }

        // If type is 'mapping', use provided mapping, else use default
        $mapping = $request->input('mapping');

        $import = new DynamicExcelImport(
            BusinessType::class,
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

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_business_types");

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
    }
}
