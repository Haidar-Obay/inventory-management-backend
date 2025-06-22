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
            'subbrand_of' => 'nullable|exists:brands,id',
            'active' => 'boolean',
        ]);

        // Check if the parent brand is not itself a subbrand
        if ($validated['subbrand_of']) {
            $parentBrand = Brand::find($validated['subbrand_of']);
            if ($parentBrand->subbrand_of) {
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
            'subbrand_of' => [
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
        if ($validated['subbrand_of']) {
            $parentBrand = Brand::find($validated['subbrand_of']);
            if ($parentBrand->subbrand_of) {
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

    public function exportExcell()
    {
        $brands = Brand::with(['parentBrand'])->orderBy('name');
        $collection = $brands->get();

        if ($collection->isEmpty()) {
            return response()->json(['message' => 'No brands found.'], 404);
        }

        $columns = ['id', 'code', 'name', 'subbrand_of', 'active'];
        $headings = ['ID', 'Code', 'Name', 'Subbrand Of', 'Active'];

        return Excel::download(new Export($brands, $columns, $headings), 'brands.xlsx');
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $brands = Brand::with(['parentBrand'])
            ->select('id', 'code', 'name', 'subbrand_of', 'active')
            ->get();

        if ($brands->isEmpty()) {
            return response()->json(['message' => 'No brands found.'], 404);
        }

        $title = 'Brand Report';
        $headers = [
            'id' => 'Brand ID',
            'code' => 'Code',
            'name' => 'Name',
            'subbrand_of' => 'Subbrand Of',
            'active' => 'Status'
        ];
        $data = $brands->toArray();

        $pdf = $pdfService->generatePdf($title, $headers, $data);
        return $pdf->download('Brands.pdf');
    }

    public function importFromExcel(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv',
        ]);

        $import = new DynamicExcelImport(
            Brand::class,
            ['code', 'name'],
            function ($row) {
                $errors = [];

                if (empty($row['code'])) {
                    $errors[] = 'Missing code';
                }
                if (empty($row['name'])) {
                    $errors[] = 'Missing name';
                }

                if (!empty($row['subbrand_of'])) {
                    $parentBrand = Brand::where('code', $row['subbrand_of'])->first();
                    if (!$parentBrand) {
                        $errors[] = 'Parent brand not found';
                    }
                }

                return $errors;
            },
            function ($row) {
                $data = [
                    'code' => $row['code'],
                    'name' => $row['name'],
                    'active' => boolval($row['active'] ?? false),
                ];

                if (!empty($row['subbrand_of'])) {
                    $parentBrand = Brand::where('code', $row['subbrand_of'])->first();
                    if ($parentBrand) {
                        $data['subbrand_of'] = $parentBrand->id;
                    }
                }

                return $data;
            }
        );

        Excel::import($import, $request->file('file'));

        app('cache')->store('database')->forget('tenant_' . tenant('id') . '_brands');

        return response()->json([
            'success' => true,
            'rows_imported' => $import->getImportedCount(),
            'rows_skipped_count' => $import->getSkippedCount(),
            'skipped_rows' => $import->getSkippedRows(),
        ]);
    }

    public function getNames()
    {
        // Only get top-level brands (not subbrands)
        $brands = Brand::whereNull('subbrand_of')
                ->select('id', 'name')
                ->orderBy('name')
                ->get();

        return response()->json([
            'status' => true,
            'message' => 'Brand names fetched successfully.',
            'data' => $brands,
        ]);
    }
}
