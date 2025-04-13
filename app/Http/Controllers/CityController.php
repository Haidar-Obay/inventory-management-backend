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
        $cities = City::withCount('addresses')
            ->orderBy('name')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Cities fetched successfully.',
            'data' => $cities,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:cities,name',
        ]);

        $city = City::create($validated);

        return response()->json([
            'status' => true,
            'message' => 'City created successfully.',
            'data' => $city,
        ], 201);
    }

    public function show(City $city)
    {
        $city->loadCount('addresses');

        return response()->json([
            'status' => true,
            'message' => 'City details fetched successfully.',
            'data' => $city,
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

        return response()->json([
            'status' => true,
            'message' => 'City updated successfully.',
            'data' => $city,
        ]);
    }

    public function destroy(City $city)
    {
        $city->delete();

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

        $skipped = [];
        $deleted = 0;

        foreach ($request->ids as $id) {
            try {
                $deleted += City::where('id', $id)->delete();
            } catch (\Illuminate\Database\QueryException $e) {
                $skipped[] = ['id' => $id, 'reason' => $e->getMessage()];
            }
        }

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
            return response()->json(['message' => 'No currencies found.'], 404);
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
            ['name'], // required columns
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

        return response()->json([
            'success' => true,
            'rows_imported' => $import->getImportedCount(),
            'rows_skipped_count' => $import->getSkippedCount(),
            'skipped_rows' => $import->getSkippedRows(),
        ]);
    }
}
