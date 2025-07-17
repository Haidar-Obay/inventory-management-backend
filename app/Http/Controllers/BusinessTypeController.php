<?php

namespace App\Http\Controllers;

use App\Models\BusinessType;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;

class BusinessTypeController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_business_types";

        $businessTypes = app('cache')->store('database')->get($key);

        if (!$businessTypes) {
            $businessTypes = BusinessType::orderBy('name')->get();
            app('cache')->store('database')->forever($key, $businessTypes);
        }

        return response()->json([
            'status' => true,
            'message' => 'Business types fetched successfully.',
            'data' => $businessTypes,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|max:50|unique:business_types,code',
            'name' => 'required|string|max:255',
        ]);

        $businessType = BusinessType::create($validated);

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

        if (!$cachedBusinessType) {
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
            try {
                $deleted += BusinessType::where('id', $id)->delete();
                app('cache')->store('database')->forget("tenant_{$tenantId}_business_type_{$id}");
            } catch (\Illuminate\Database\QueryException $e) {
                $skipped[] = ['id' => $id, 'reason' => $e->getMessage()];
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

        $columns = ['id', 'code', 'name'];
        $headings = ['ID', 'Code', 'Name'];

        $fileName = 'business_types_' . date('Y-m-d_H-i-s') . '.xlsx';
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
        $headers = [
            'id' => 'ID',
            'code' => 'Code',
            'name' => 'Name'
        ];
        $data = $businessTypes->toArray();

        $pdf = $pdfService->generatePdf($title, $headers, $data);
        return $pdf->download('BusinessTypes.pdf');
    }

    public function importFromExcel(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv', 
        ]);

        $import = new DynamicExcelImport(
            BusinessType::class,
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

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_business_types");

        return response()->json([
            'success' => true,
            'rows_imported' => $import->getImportedCount(),
            'rows_skipped_count' => $import->getSkippedCount(),
            'skipped_rows' => $import->getSkippedRows(),
        ]);
    }
}
