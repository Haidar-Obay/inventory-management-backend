<?php

namespace App\Http\Controllers;

use App\Models\District;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;

class DistrictController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_districts";

        $districts = app('cache')->store('database')->get($key);

        if (!$districts) {
            $districts = District::withCount('addresses')
                ->orderBy('name')
                ->get();

            app('cache')->store('database')->forever($key, $districts);
        }

        return response()->json([
            'status' => true,
            'message' => 'Districts fetched successfully.',
            'data' => $districts,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:districts,name',
        ]);

        $district = District::create($validated);

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_districts");

        return response()->json([
            'status' => true,
            'message' => 'District created successfully.',
            'data' => $district,
        ], 201);
    }

    public function show(District $district)
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_district_{$district->id}";

        $cachedDistrict = app('cache')->store('database')->get($key);

        if (!$cachedDistrict) {
            $district->loadCount('addresses');
            $cachedDistrict = $district;

            app('cache')->store('database')->forever($key, $cachedDistrict);
        }

        return response()->json([
            'status' => true,
            'message' => 'District details fetched successfully.',
            'data' => $cachedDistrict,
        ]);
    }

    public function update(Request $request, District $district)
    {
        $validated = $request->validate([
            'name' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('districts', 'name')->ignore($district->id),
            ],
        ]);

        $district->update($validated);

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_districts");
        app('cache')->store('database')->forget("tenant_{$tenantId}_district_{$district->id}");

        return response()->json([
            'status' => true,
            'message' => 'District updated successfully.',
            'data' => $district,
        ]);
    }

    public function destroy(District $district)
    {
        $district->delete();

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_districts");
        app('cache')->store('database')->forget("tenant_{$tenantId}_district_{$district->id}");

        return response()->json([
            'status' => true,
            'message' => 'District deleted successfully.',
        ]);
    }

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:districts,id',
        ]);

        $tenantId = tenant('id');
        $skipped = [];
        $deleted = 0;

        foreach ($request->ids as $id) {
            try {
                $deleted += District::where('id', $id)->delete();
                app('cache')->store('database')->forget("tenant_{$tenantId}_district_{$id}");
            } catch (\Illuminate\Database\QueryException $e) {
                $skipped[] = ['id' => $id, 'reason' => $e->getMessage()];
            }
        }

        app('cache')->store('database')->forget("tenant_{$tenantId}_districts");

        return response()->json([
            'message' => 'Bulk delete completed.',
            'deleted_count' => $deleted,
            'skipped' => $skipped,
        ]);
    }

    // Import from Excel
    public function importFromExcel(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv',
        ]);

        $import = new DynamicExcelImport(
            District::class,
            ['name'],
            function ($row) {
                $errors = [];

                if (empty($row['name'])) {
                    $errors[] = 'Missing name';
                } elseif (preg_match('/[0-9]/', $row['name'])) {
                    $errors[] = 'District name must not contain numbers';
                }

                return $errors;
            },
            function ($row) {
                return [
                    'name' => $row['name'],
                ];
            }
        );

        Excel::import($import, $request->file('file'));

        app('cache')->store('database')->forget('tenant_' . tenant('id') . '_districts');

        return response()->json([
            'success' => true,
            'rows_imported' => $import->getImportedCount(),
            'rows_skipped_count' => $import->getSkippedCount(),
            'skipped_rows' => $import->getSkippedRows(),
        ]);
    }

    public function exportExcell()
    {
        $districts = District::withCount('addresses')->orderBy('name');
        $collection = $districts->get();

        if ($collection->isEmpty()) {
            return response()->json(['message' => 'No districts found.'], 404);
        }

        $columns = ['id', 'name'];
        $headings = ['ID', 'Name'];

        return Excel::download(new Export($districts, $columns, $headings), 'districts.xlsx');
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $districts = District::select('id', 'name')->get();

        if ($districts->isEmpty()) {
            return response()->json(['message' => 'No districts found.'], 404);
        }

        $title = 'District Report';
        $headers = [
            'id' => 'District ID',
            'name' => 'District Name',
        ];
        $data = $districts->toArray();

        $pdf = $pdfService->generatePdf($title, $headers, $data);
        return $pdf->download('Districts.pdf');
    }
}
