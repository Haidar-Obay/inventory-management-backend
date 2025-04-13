<?php

namespace App\Http\Controllers;

use App\Models\Country;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;

class CountryController extends Controller
{
    public function index()
    {
        $countries = Country::withCount('addresses')
            ->orderBy('name')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Countries fetched successfully.',
            'data' => $countries,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:countries,name',
        ]);

        $country = Country::create($validated);

        return response()->json([
            'status' => true,
            'message' => 'Country created successfully.',
            'data' => $country,
        ], 201);
    }

    public function show(Country $country)
    {
      $country->loadCount('addresses');

        return response()->json([
            'status' => true,
            'message' => 'Country details fetched successfully.',
            'data' => $country,
        ]);
    }

    public function update(Request $request, Country $country)
    {
        $validated = $request->validate([
            'name' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('countries', 'name')->ignore($country->id),
            ],
        ]);

        $country->update($validated);

        return response()->json([
            'status' => true,
            'message' => 'Country updated successfully.',
            'data' => $country,
        ]);
    }

    public function destroy(Country $country)
    {
        $country->delete();

        return response()->json([
            'status' => true,
            'message' => 'Country deleted successfully.',
        ]);
    }
    public function exportExcell()
    {
        $countries = Country::query();
        $collection =  $countries->get();
        if ($collection->isEmpty()) {
            return response()->json(['message' => 'No currencies found.'], 404);
        }
        $columns = ['id', 'name'];
        $headings = ['ID', 'Name'];
        return Excel::download(new Export($countries, $columns, $headings), 'countries.xlsx');
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $countries = Country::select(
            'id',
            'name'
        )->get();

        if ($countries->isEmpty()) {
            return response()->json(['message' => 'No countries found.'], 404);
        }

        $title = 'Country Report';
        $headers = [
            'id' => 'Country ID',
            'name' => 'Country Name'
        ];
        $data = $countries->toArray();

        $pdf = $pdfService->generatePdf($title, $headers, $data);
        return $pdf->download('Countries.pdf');
    }

    public function importFromExcel(Request $request)
{
    $request->validate([
        'file' => 'required|file|mimes:xlsx,xls,csv',
    ]);

    $import = new DynamicExcelImport(
        Country::class,
        ['name'],
        function ($row) {
            $errors = [];

            if (empty($row['name'])) {
                $errors[] = 'Missing name';
            } elseif (preg_match('/[0-9]/', $row['name'])) {
                $errors[] = 'Country name must not contain numbers';
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
