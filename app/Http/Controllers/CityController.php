<?php

namespace App\Http\Controllers;

use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;
use Illuminate\Support\Facades\DB;
use App\Models\City;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

class CityController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_cities";

        $cities = app('cache')->store('database')->get($key);

        if (! $cities) {
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
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:cities,name',
        ]);

        $nextId = $this->computeNextAvailableId(City::class, 'id');
        $city = new City($validated);
        $city->id = $nextId;
        $city->save();

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_cities");

        return response()->json([
            'status' => true,
            'message' => 'City created successfully.',
            'data' => $city,
        ], 201);
    }

    public function show(City $city)
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_city_{$city->id}";

        $cachedCity = app('cache')->store('database')->get($key);

        if (! $cachedCity) {
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
        // Prevent deletion if referenced by customers or suppliers (via addresses)
        $customerCount = DB::table('customer_addresses')
            ->join('addresses', 'customer_addresses.address_id', '=', 'addresses.id')
            ->where('addresses.city_id', $city->id)
            ->count();
        $supplierCount = DB::table('supplier_addresses')
            ->join('addresses', 'supplier_addresses.address_id', '=', 'addresses.id')
            ->where('addresses.city_id', $city->id)
            ->count();

        if ($customerCount > 0 || $supplierCount > 0) {
            $customerSample = DB::table('customer_addresses')
                ->join('addresses', 'customer_addresses.address_id', '=', 'addresses.id')
                ->where('addresses.city_id', $city->id)
                ->limit(1)
                ->pluck('customer_addresses.customer_id');
            $supplierSample = DB::table('supplier_addresses')
                ->join('addresses', 'supplier_addresses.address_id', '=', 'addresses.id')
                ->where('addresses.city_id', $city->id)
                ->limit(1)
                ->pluck('supplier_addresses.supplier_id');

            $details = [];
            if ($customerCount > 0) {
                $details['customers'] = [
                    'count' => $customerCount,
                    'sample_ids' => $customerSample,
                ];
            }
            if ($supplierCount > 0) {
                $details['suppliers'] = [
                    'count' => $supplierCount,
                    'sample_ids' => $supplierSample,
                ];
            }

            return response()->json([
                'status' => false,
                'message' => 'Cannot delete city. It is referenced by existing customers or suppliers.',
                'details' => $details,
            ], 409);
        }

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
            $city = City::find($id);

            // Check if the city has any addresses linked to it
            if ($city->addresses()->exists()) {
                $skipped[] = [
                    'id' => $id,
                    'reason' => 'Cannot delete city. It is being used by one or more addresses.',
                ];

                continue;
            }

            try {
                $deleted += $city->delete();
                app('cache')->store('database')->forget("tenant_{$tenantId}_city_{$id}");
            } catch (\Illuminate\Database\QueryException $e) {
                // Check if it's a foreign key constraint error
                if ($e->getCode() == '23503') {
                    $skipped[] = [
                        'id' => $id,
                        'reason' => 'Cannot delete city. It is being used by one or more addresses.',
                    ];
                } else {
                    $skipped[] = ['id' => $id, 'reason' => $e->getMessage()];
                }
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
        $columns = ['id', 'name', 'created_at', 'updated_at', 'created_at', 'updated_at'];
        $headings = ['ID', 'Name', 'Created At', 'Updated At', 'Created At', 'Updated At'];

        return Excel::download(new Export($cities, $columns, $headings), 'cities.xlsx');
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $cities = City::select(
            'id',
            'name',
            'created_at',
            'updated_at'
        )->get();

        if ($cities->isEmpty()) {
            return response()->json(['message' => 'No cities found.'], 404);
        }

        $title = 'City Report';
        $headers = ['id' => 'City ID', 'name' => 'City Name', 'created_at' => 'Created At', 'updated_at' => 'Updated At', 'created_at' => 'Created At', 'updated_at' => 'Updated At', 'created_at' => 'Created At', 'updated_at' => 'Updated At'];
        $data = $cities->toArray();

        $pdf = $pdfService->generatePdf($title, $headers, $data);

        return $pdf->download('Cities.pdf');
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
            City::truncate();
        }

        // If type is 'mapping', use provided mapping, else use default
        $mapping = $request->input('mapping');

        $import = new DynamicExcelImport(
            City::class,
            ['name'],
            function ($row) use ($mapping) {
                foreach ($row as $k => $v) {
                    if (is_string($v)) {
                        $row[$k] = trim($v);
                    }
                }
                $errors = [];

                $nameKey = $mapping ? array_search('name', $mapping) : 'name';
                if ((($row[$nameKey] ?? '') === '')) {
                    $errors[] = 'Missing name';
                } elseif (preg_match('/[0-9]/', $row[$nameKey])) {
                    $errors[] = 'City name must not contain numbers';
                }

                return $errors;
            },
            function ($row) use ($mapping) {
                foreach ($row as $k => $v) {
                    if (is_string($v)) {
                        $row[$k] = trim($v);
                    }
                }
                $nameKey = $mapping ? array_search('name', $mapping) : 'name';

                return [
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

        app('cache')->store('database')->forget('tenant_'.tenant('id').'_cities');

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

    public function getByCountry($countryId)
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_cities_country_{$countryId}";

        $cities = app('cache')->store('database')->get($key);

        if (! $cities) {
            $cities = City::where('country_id', $countryId)
                ->withCount('addresses')
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

    public function getByProvince($provinceId)
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_cities_province_{$provinceId}";

        $cities = app('cache')->store('database')->get($key);

        if (! $cities) {
            $cities = City::where('province_id', $provinceId)
                ->withCount('addresses')
                ->with(['country', 'districts'])
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
}
