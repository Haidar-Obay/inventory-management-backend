<?php

namespace App\Http\Controllers;

use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;
use App\Models\CompanyCode;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

class CompanyCodeController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_company_codes";

        $companyCodes = app('cache')->store('database')->get($key);

        if (! $companyCodes) {
            $companyCodes = CompanyCode::orderBy('name')->get();
            app('cache')->store('database')->forever($key, $companyCodes);
        }

        return response()->json([
            'status' => true,
            'message' => 'Company codes fetched successfully.',
            'data' => $companyCodes,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|max:255|unique:company_codes,code',
            'name' => 'required|string|max:255',
        ]);

        $nextId = $this->computeNextAvailableId(CompanyCode::class, 'id');
        $companyCode = new CompanyCode($validated);
        $companyCode->id = $nextId;
        $companyCode->save();

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_company_codes");

        return response()->json([
            'status' => true,
            'message' => 'Company code created successfully.',
            'data' => $companyCode,
        ], 201);
    }

    public function show(CompanyCode $companyCode)
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_company_code_{$companyCode->id}";

        $cachedCompanyCode = app('cache')->store('database')->get($key);

        if (! $cachedCompanyCode) {
            $cachedCompanyCode = $companyCode;
            app('cache')->store('database')->forever($key, $cachedCompanyCode);
        }

        return response()->json([
            'status' => true,
            'message' => 'Company code details fetched successfully.',
            'data' => $cachedCompanyCode,
        ]);
    }

    public function update(Request $request, CompanyCode $companyCode)
    {
        $validated = $request->validate([
            'code' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('company_codes', 'code')->ignore($companyCode->id),
            ],
            'name' => 'sometimes|string|max:255',
        ]);

        $companyCode->update($validated);

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_company_codes");
        app('cache')->store('database')->forget("tenant_{$tenantId}_company_code_{$companyCode->id}");

        return response()->json([
            'status' => true,
            'message' => 'Company code updated successfully.',
            'data' => $companyCode,
        ]);
    }

    public function destroy(CompanyCode $companyCode)
    {
        $companyCode->delete();

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_company_codes");
        app('cache')->store('database')->forget("tenant_{$tenantId}_company_code_{$companyCode->id}");

        return response()->json([
            'status' => true,
            'message' => 'Company code deleted successfully.',
        ]);
    }

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:company_codes,id',
        ]);

        $tenantId = tenant('id');
        $skipped = [];
        $deleted = 0;

        foreach ($request->ids as $id) {
            $companyCode = CompanyCode::find($id);

            // Check if the company code has any customers linked to it
            if ($companyCode->customers()->exists()) {
                $skipped[] = [
                    'id' => $id,
                    'reason' => 'Cannot delete company code. It is being used by one or more customers.',
                ];

                continue;
            }

            try {
                $deleted += $companyCode->delete();
                app('cache')->store('database')->forget("tenant_{$tenantId}_company_code_{$id}");
            } catch (\Illuminate\Database\QueryException $e) {
                // Check if it's a foreign key constraint error
                if ($e->getCode() == '23503') {
                    $skipped[] = [
                        'id' => $id,
                        'reason' => 'Cannot delete company code. It is being used by one or more customers.',
                    ];
                } else {
                    $skipped[] = ['id' => $id, 'reason' => $e->getMessage()];
                }
            }
        }

        app('cache')->store('database')->forget("tenant_{$tenantId}_company_codes");

        return response()->json([
            'message' => 'Bulk delete completed.',
            'deleted_count' => $deleted,
            'skipped' => $skipped,
        ]);
    }

    public function exportExcell()
    {
        $companyCodes = CompanyCode::query();
        $collection = $companyCodes->get();

        if ($collection->isEmpty()) {
            return response()->json(['message' => 'No company codes found.'], 404);
        }

        $columns = ['id', 'code', 'name', 'created_at', 'updated_at'];
        $headings = ['ID', 'Code', 'Name', 'Created At', 'Updated At'];

        return Excel::download(new Export($companyCodes, $columns, $headings), 'company_codes.xlsx');
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $companyCodes = CompanyCode::select('id', 'code', 'name')->get();

        if ($companyCodes->isEmpty()) {
            return response()->json(['message' => 'No company codes found.'], 404);
        }

        $title = 'Company Code Report';
        $headers = ['id' => 'Company Code ID', 'code' => 'Code', 'name' => 'Name', 'created_at' => 'Created At', 'updated_at' => 'Updated At', 'created_at' => 'Created At', 'updated_at' => 'Updated At'];
        $data = $companyCodes->toArray();

        $pdf = $pdfService->generatePdf($title, $headers, $data);

        return $pdf->download('CompanyCodes.pdf');
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
            CompanyCode::truncate();
        }

        // If type is 'mapping', use provided mapping, else use default
        $mapping = $request->input('mapping');
        $fields = $mapping ? array_values($mapping) : ['code', 'name'];

        $import = new DynamicExcelImport(
            CompanyCode::class,
            $fields,
            function ($row) use ($mapping) {
                foreach ($row as $k => $v) {
                    if (is_string($v)) {
                        $row[$k] = trim($v);
                    }
                }
                $errors = [];
                $codeKey = $mapping ? array_search('code', $mapping) : 'code';
                $nameKey = $mapping ? array_search('name', $mapping) : 'name';

                if (($row[$codeKey] ?? '') === '') {
                    $errors[] = 'Missing code';
                }
                if (($row[$nameKey] ?? '') === '') {
                    $errors[] = 'Missing name';
                }

                return $errors;
            },
            function ($row) use ($mapping) {
                foreach ($row as $k => $v) {
                    if (is_string($v)) {
                        $row[$k] = trim($v);
                    }
                }
                $codeKey = $mapping ? array_search('code', $mapping) : 'code';
                $nameKey = $mapping ? array_search('name', $mapping) : 'name';

                return [
                    'code' => $row[$codeKey] ?? null,
                    'name' => $row[$nameKey] ?? null,
                ];
            },
            $mapping ? false : true // Disable header validation when mapping provided
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

        app('cache')->store('database')->forget('tenant_'.tenant('id').'_company_codes');

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
