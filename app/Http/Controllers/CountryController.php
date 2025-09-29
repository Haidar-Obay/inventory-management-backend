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
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_countries";

        $countries = app('cache')->store('database')->get($key);

        if (!$countries) {
            $countries = Country::withCount('addresses')
                ->orderBy('name')
                ->get();

            app('cache')->store('database')->forever($key, $countries);
        }

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

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_countries");

        return response()->json([
            'status' => true,
            'message' => 'Country created successfully.',
            'data' => $country,
        ], 201);
    }

    public function show(Country $country)
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_country_{$country->id}";

        $cachedCountry = app('cache')->store('database')->get($key);

        if (!$cachedCountry) {
            $country->loadCount('addresses');
            $cachedCountry = $country;

            app('cache')->store('database')->forever($key, $cachedCountry);
        }

        return response()->json([
            'status' => true,
            'message' => 'Country details fetched successfully.',
            'data' => $cachedCountry,
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

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_countries");
        app('cache')->store('database')->forget("tenant_{$tenantId}_country_{$country->id}");

        return response()->json([
            'status' => true,
            'message' => 'Country updated successfully.',
            'data' => $country,
        ]);
    }

    public function destroy(Country $country)
    {
        $country->delete();

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_countries");
        app('cache')->store('database')->forget("tenant_{$tenantId}_country_{$country->id}");

        return response()->json([
            'status' => true,
            'message' => 'Country deleted successfully.',
        ]);
    }

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:countries,id',
        ]);

        $tenantId = tenant('id');
        $skipped = [];
        $deleted = 0;

        foreach ($request->ids as $id) {
            try {
                $deleted += Country::where('id', $id)->delete();
                app('cache')->store('database')->forget("tenant_{$tenantId}_country_{$id}");
            } catch (\Illuminate\Database\QueryException $e) {
                $skipped[] = ['id' => $id, 'reason' => $e->getMessage()];
            }
        }

        app('cache')->store('database')->forget("tenant_{$tenantId}_countries");

        return response()->json([
            'message' => 'Bulk delete completed.',
            'deleted_count' => $deleted,
            'skipped' => $skipped,
        ]);
    }

    public function exportExcell()
    {
        $countries = Country::withCount('addresses')->orderBy('name');
        $collection = $countries->get();

        if ($collection->isEmpty()) {
            return response()->json(['message' => 'No countries found.'], 404);
        }

        $columns = ['id', 'name', 'created_at', 'updated_at'];
        $headings = ['ID', 'Name', 'Created At', 'Updated At'];

        return Excel::download(new Export($countries, $columns, $headings), 'countries.xlsx');
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $countries = Country::select('id', 'name', 'created_at', 'updated_at')->get();

        if ($countries->isEmpty()) {
            return response()->json(['message' => 'No countries found.'], 404);
        }

        $title = 'Country Report';
        $headers = [
            'id' => 'Country ID',
            'name' => 'Country Name',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
        ];
        $data = $countries->toArray();

        $pdf = $pdfService->generatePdf($title, $headers, $data);
        return $pdf->download('Countries.pdf');
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
            Country::truncate();
        }

        // If type is 'mapping', use provided mapping, else use default
        $mapping = $request->input('mapping');

        $import = new DynamicExcelImport(
            Country::class,
            ['name'],
            function ($row) {
                $errors = [];

                $name = isset($row['name']) ? trim((string)$row['name']) : '';

                if ($name === '') {
                    $errors[] = 'Missing name';
                } elseif (preg_match('/[0-9]/', $name)) {
                    $errors[] = 'Country name must not contain numbers';
                }

                return $errors;
            },
            function ($row) {
                $name = trim((string)($row['name'] ?? ''));
                return [
                    'name' => $name,
                ];
            },
            true // Enable header validation
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

        app('cache')->store('database')->forget('tenant_' . tenant('id') . '_countries');

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
