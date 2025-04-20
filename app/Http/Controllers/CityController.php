<?php

namespace App\Http\Controllers;

use App\Models\City;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;

class CityController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_cities";

        $cities = app('cache')->store('database')->get($key);

        if (!$cities) {
            $cities = City::withCount('addresses')
                ->orderBy('name')
                ->get();

            app('cache')->store('database')->forever($key, $cities);
        }

        return response()->json([
            'status' => true,
            'message' => 'Cities fetched successfully.',
            'data' => $cities,
        ]);
    }

    public function store(Request $request)
    {
        $user = Auth::user();
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:cities,name',
        ]);

        $city = City::create($validated);

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_cities");

        return response()->json([
            'status' => true,
            'message' => 'City created successfully.',
            'data' => $city,
            'user' => $user,
        ], 201);
    }

    public function show(City $city)
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_city_{$city->id}";

        $cachedCity = app('cache')->store('database')->get($key);

        if (!$cachedCity) {
            $city->loadCount('addresses');
            $cachedCity = $city;

            app('cache')->store('database')->forever($key, $cachedCity);
        }

        return response()->json([
            'status' => true,
            'message' => 'City details fetched successfully.',
            'data' => $cachedCity,
        ]);
    }

    public function update(Request $request, City $city)
    {
        $validated = $request->validate([
            'name' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('cities', 'name')->ignore($city->id),
            ],
        ]);

        $city->update($validated);

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_cities");
        app('cache')->store('database')->forget("tenant_{$tenantId}_city_{$city->id}");

        return response()->json([
            'status' => true,
            'message' => 'City updated successfully.',
            'data' => $city,
        ]);
    }

    public function destroy(City $city)
    {
        $city->delete();

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_cities");
        app('cache')->store('database')->forget("tenant_{$tenantId}_city_{$city->id}");

        return response()->json([
            'status' => true,
            'message' => 'City deleted successfully.',
        ]);
    }

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:cities,id',
        ]);

        $tenantId = tenant('id');
        $skipped = [];
        $deleted = 0;

        foreach ($request->ids as $id) {
            try {
                $deleted += City::where('id', $id)->delete();
                app('cache')->store('database')->forget("tenant_{$tenantId}_city_{$id}");
            } catch (\Illuminate\Database\QueryException $e) {
                $skipped[] = ['id' => $id, 'reason' => $e->getMessage()];
            }
        }

        app('cache')->store('database')->forget("tenant_{$tenantId}_cities");

        return response()->json([
            'message' => 'Bulk delete completed.',
            'deleted_count' => $deleted,
            'skipped' => $skipped,
        ]);
    }

    public function exportExcell()
    {
        $cities = City::withCount('addresses')
            ->orderBy('name');
        $collection = $cities->get();
        if ($collection->isEmpty()) {
            return response()->json(['message' => 'No cities found.'], 404);
        }
        $columns = ['id', 'name'];
        $headings = ['ID', 'Name'];

        return Excel::download(new Export($cities, $columns, $headings), 'cities.xlsx');
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $cities = City::select(
            'id',
            'name'
        )->get();

        if ($cities->isEmpty()) {
            return response()->json(['message' => 'No cities found.'], 404);
        }

        $title = 'City Report';
        $headers = [
            'id' => 'City ID',
            'name' => 'City Name'
        ];
        $data = $cities->toArray();

        $pdf = $pdfService->generatePdf($title, $headers, $data);
        return $pdf->download('Cities.pdf');
    }

    public function importFromExcel(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv',
        ]);

        $import = new DynamicExcelImport(
            City::class,
            ['name'],
            function ($row) {
                $errors = [];

                if (empty($row['name'])) {
                    $errors[] = 'Missing name';
                } elseif (preg_match('/[0-9]/', $row['name'])) {
                    $errors[] = 'City name must not contain numbers';
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

        app('cache')->store('database')->forget('tenant_' . tenant('id') . '_cities');

        return response()->json([
            'success' => true,
            'rows_imported' => $import->getImportedCount(),
            'rows_skipped_count' => $import->getSkippedCount(),
            'skipped_rows' => $import->getSkippedRows(),
        ]);
    }
}
