<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;

class BrandController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_brands";

        $brands = app('cache')->store('database')->get($key);

        if (!$brands) {
            $brands = Brand::with(['parentBrand', 'subbrands'])
                ->orderBy('name')
                ->get();
            app('cache')->store('database')->forever($key, $brands);
        }

        return response()->json([
            'status' => true,
            'message' => 'Brands fetched successfully.',
            'data' => $brands,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|max:255|unique:brands,code',
            'name' => 'required|string|max:255',
            'sub_brand_of' => 'nullable|exists:brands,id',
            'active' => 'boolean',
        ]);

        // Check if the parent brand is not itself a subbrand
        if ($validated['sub_brand_of']) {
            $parentBrand = Brand::find($validated['sub_brand_of']);
            if ($parentBrand->sub_brand_of) {
                return response()->json([
                    'status' => false,
                    'message' => 'Cannot create subbrand under another subbrand. Only top-level brands can have subbrands.',
                ], 422);
            }
        }

        $brand = Brand::create($validated);

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_brands");

        return response()->json([
            'status' => true,
            'message' => 'Brand created successfully.',
            'data' => $brand,
        ], 201);
    }

    public function show(Brand $brand)
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_brand_{$brand->id}";

        $cachedBrand = app('cache')->store('database')->get($key);

        if (!$cachedBrand) {
            $brand->load(['parentBrand', 'subbrands']);
            $cachedBrand = $brand;
            app('cache')->store('database')->forever($key, $cachedBrand);
        }

        return response()->json([
            'status' => true,
            'message' => 'Brand details fetched successfully.',
            'data' => $cachedBrand,
        ]);
    }

    public function update(Request $request, Brand $brand)
    {
        $validated = $request->validate([
            'code' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('brands', 'code')->ignore($brand->id),
            ],
            'name' => 'sometimes|string|max:255',
            'sub_brand_of' => [
                'nullable',
                'exists:brands,id',
                function ($attribute, $value, $fail) use ($brand) {
                    if ($value == $brand->id) {
                        $fail('A brand cannot be a subbrand of itself.');
                    }
                },
            ],
            'active' => 'boolean',
        ]);

        // Check if the parent brand is not itself a subbrand
        if ($validated['sub_brand_of']) {
            $parentBrand = Brand::find($validated['sub_brand_of']);
            if ($parentBrand->sub_brand_of) {
                return response()->json([
                    'status' => false,
                    'message' => 'Cannot assign subbrand under another subbrand. Only top-level brands can have subbrands.',
                ], 422);
            }
        }

        $brand->update($validated);

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_brands");
        app('cache')->store('database')->forget("tenant_{$tenantId}_brand_{$brand->id}");

        return response()->json([
            'status' => true,
            'message' => 'Brand updated successfully.',
            'data' => $brand,
        ]);
    }

    public function destroy(Brand $brand)
    {
        // Check if brand has subbrands
        if ($brand->subbrands()->exists()) {
            return response()->json([
                'status' => false,
                'message' => 'Cannot delete brand with subbrands. Please delete or reassign subbrands first.',
            ], 422);
        }

        $brand->delete();

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_brands");
        app('cache')->store('database')->forget("tenant_{$tenantId}_brand_{$brand->id}");

        return response()->json([
            'status' => true,
            'message' => 'Brand deleted successfully.',
        ]);
    }

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:brands,id',
        ]);

        $tenantId = tenant('id');
        $skipped = [];
        $deleted = 0;

        foreach ($request->ids as $id) {
            try {
                $brand = Brand::find($id);
                if ($brand->subbrands()->exists()) {
                    $skipped[] = ['id' => $id, 'reason' => 'Brand has subbrands'];
                    continue;
                }
                $deleted += $brand->delete();
                app('cache')->store('database')->forget("tenant_{$tenantId}_brand_{$id}");
            } catch (\Illuminate\Database\QueryException $e) {
                $skipped[] = ['id' => $id, 'reason' => $e->getMessage()];
            }
        }

        app('cache')->store('database')->forget("tenant_{$tenantId}_brands");

        return response()->json([
            'message' => 'Bulk delete completed.',
            'deleted_count' => $deleted,
            'skipped' => $skipped,
        ]);
    }

    public function exportExcel()
    {
        $brands = Brand::with(['parentBrand'])->orderBy('name');
        $collection = $brands->get();

        if ($collection->isEmpty()) {
            return response()->json(['message' => 'No brands found.'], 404);
        }

        $columns = ['id', 'code', 'name', 'sub_brand_of', 'active', 'created_at', 'updated_at'];
        $headings = ['ID', 'Code', 'Name', 'Sub Brand Of', 'Active', 'Created At', 'Updated At'];

        return Excel::download(new Export($brands, $columns, $headings), 'brands.xlsx');
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $brands = Brand::with(['parentBrand'])
            ->select('id', 'code', 'name', 'sub_brand_of', 'active', 'created_at', 'updated_at')
            ->get();

        if ($brands->isEmpty()) {
            return response()->json(['message' => 'No brands found.'], 404);
        }

        $title = 'Brand Report';
        $headers = ['id' => 'Brand ID', 'code' => 'Code', 'name' => 'Name', 'sub_brand_of' => 'Sub Brand Of', 'active' => 'Status', 'created_at' => 'Created At', 'updated_at' => 'Updated At'];
        $data = $brands->toArray();

        $pdf = $pdfService->generatePdf($title, $headers, $data);
        return $pdf->download('Brands.pdf');
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
            Brand::truncate();
        }

        // If type is 'mapping', use provided mapping, else use default
        $mapping = $request->input('mapping');
        $fields = $mapping ? array_values($mapping) : ['code', 'name'];

        $import = new DynamicExcelImport(
            Brand::class,
            $fields,
            function ($row) use ($mapping) {
                foreach ($row as $k => $v) { if (is_string($v)) { $row[$k] = trim($v); } }
                $errors = [];
                $codeKey = $mapping ? array_search('code', $mapping) : 'code';
                $nameKey = $mapping ? array_search('name', $mapping) : 'name';
                if (($row[$codeKey] ?? '') === '') { $errors[] = 'Missing code'; }
                if (($row[$nameKey] ?? '') === '') { $errors[] = 'Missing name'; }
                $subBrandKey = $mapping ? array_search('sub_brand_of', $mapping) : 'sub_brand_of';
                if (!empty($row[$subBrandKey])) {
                    $parentBrand = Brand::whereRaw('LOWER(TRIM(code)) = ?', [mb_strtolower($row[$subBrandKey])])->first();
                    if (!$parentBrand) {
                        $errors[] = 'Parent brand not found';
                    }
                }
                return $errors;
            },
            function ($row) use ($mapping) {
                foreach ($row as $k => $v) { if (is_string($v)) { $row[$k] = trim($v); } }
                $codeKey = $mapping ? array_search('code', $mapping) : 'code';
                $nameKey = $mapping ? array_search('name', $mapping) : 'name';
                $subBrandKey = $mapping ? array_search('sub_brand_of', $mapping) : 'sub_brand_of';
                $data = [
                    'code' => $row[$codeKey] ?? null,
                    'name' => $row[$nameKey] ?? null,
                    'active' => boolval($row['active'] ?? false),
                ];
                if (!empty($row[$subBrandKey])) {
                    $parentBrand = Brand::whereRaw('LOWER(TRIM(code)) = ?', [mb_strtolower($row[$subBrandKey])])->first();
                    if ($parentBrand) {
                        $data['sub_brand_of'] = $parentBrand->id;
                    }
                }
                return $data;
            },
            $mapping ? false : true // Disable header validation when mapping provided
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

        app('cache')->store('database')->forget('tenant_' . tenant('id') . '_brands');

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

    public function getNames()
    {
        // Only get top-level brands (not subbrands)
        $brands = Brand::whereNull('sub_brand_of')
                ->select('id', 'name', 'created_at', 'updated_at')
                ->orderBy('name')
                ->get();

        return response()->json([
            'status' => true,
            'message' => 'Brand names fetched successfully.',
            'data' => $brands,
        ]);
    }
}
