<?php

namespace App\Http\Controllers;

use App\Models\CompanyCode;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;

class CompanyCodeController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_company_codes";

        $companyCodes = app('cache')->store('database')->get($key);

        if (!$companyCodes) {
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

        $companyCode = CompanyCode::create($validated);

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

        if (!$cachedCompanyCode) {
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
            try {
                $deleted += CompanyCode::where('id', $id)->delete();
                app('cache')->store('database')->forget("tenant_{$tenantId}_company_code_{$id}");
            } catch (\Illuminate\Database\QueryException $e) {
                $skipped[] = ['id' => $id, 'reason' => $e->getMessage()];
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

        $columns = ['id', 'code', 'name'];
        $headings = ['ID', 'Code', 'Name'];

        return Excel::download(new Export($companyCodes, $columns, $headings), 'company_codes.xlsx');
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $companyCodes = CompanyCode::select('id', 'code', 'name')->get();

        if ($companyCodes->isEmpty()) {
            return response()->json(['message' => 'No company codes found.'], 404);
        }

        $title = 'Company Code Report';
        $headers = [
            'id' => 'Company Code ID',
            'code' => 'Code',
            'name' => 'Name'
        ];
        $data = $companyCodes->toArray();

        $pdf = $pdfService->generatePdf($title, $headers, $data);
        return $pdf->download('CompanyCodes.pdf');
    }

    public function importFromExcel(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv',
        ]);

        $import = new DynamicExcelImport(
            CompanyCode::class,
            ['code', 'name'],
            function ($row) {
                $errors = [];

                if (empty($row['code'])) {
                    $errors[] = 'Missing code';
                }
                if (empty($row['name'])) {
                    $errors[] = 'Missing name';
                }

                return $errors;
            },
            function ($row) {
                return [
                    'code' => $row['code'],
                    'name' => $row['name'],
                ];
            }
        );

        Excel::import($import, $request->file('file'));

        app('cache')->store('database')->forget('tenant_' . tenant('id') . '_company_codes');

        return response()->json([
            'success' => true,
            'rows_imported' => $import->getImportedCount(),
            'rows_skipped_count' => $import->getSkippedCount(),
            'skipped_rows' => $import->getSkippedRows(),
        ]);
    }
}
