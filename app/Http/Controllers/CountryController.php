<?php

namespace App\Http\Controllers;

use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;
use App\Models\Country;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;

class CountryController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_countries";

        $countries = app('cache')->store('database')->get($key);

        if (! $countries) {
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

        $nextId = $this->computeNextAvailableId(Country::class, 'id');
        $country = new Country($validated);
        $country->id = $nextId;
        $country->save();

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

        if (! $cachedCountry) {
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
        // Prevent deletion if referenced by customers or suppliers (via addresses)
        $customerCount = DB::table('customer_addresses')
            ->join('addresses', 'customer_addresses.address_id', '=', 'addresses.id')
            ->where('addresses.country_id', $country->id)
            ->count();
        $supplierCount = DB::table('supplier_addresses')
            ->join('addresses', 'supplier_addresses.address_id', '=', 'addresses.id')
            ->where('addresses.country_id', $country->id)
            ->count();

        if ($customerCount > 0 || $supplierCount > 0) {
            $customerSample = DB::table('customer_addresses')
                ->join('addresses', 'customer_addresses.address_id', '=', 'addresses.id')
                ->where('addresses.country_id', $country->id)
                ->limit(1)
                ->pluck('customer_addresses.customer_id');
            $supplierSample = DB::table('supplier_addresses')
                ->join('addresses', 'supplier_addresses.address_id', '=', 'addresses.id')
                ->where('addresses.country_id', $country->id)
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
                'message' => "Cannot delete country \"{$country->name}\" (ID: {$country->id}). It is referenced by existing customers or suppliers.",
                'details' => $details,
            ], 409);
        }

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
            $country = Country::find($id);

            // Check if referenced by customers or suppliers (via addresses) and include details
            $customerCount = DB::table('customer_addresses')
                ->join('addresses', 'customer_addresses.address_id', '=', 'addresses.id')
                ->where('addresses.country_id', $id)
                ->count();
            $supplierCount = DB::table('supplier_addresses')
                ->join('addresses', 'supplier_addresses.address_id', '=', 'addresses.id')
                ->where('addresses.country_id', $id)
                ->count();

            if ($customerCount > 0 || $supplierCount > 0) {
                $customerSample = DB::table('customer_addresses')
                    ->join('addresses', 'customer_addresses.address_id', '=', 'addresses.id')
                    ->where('addresses.country_id', $id)
                    ->limit(1)
                    ->pluck('customer_addresses.customer_id');
                $supplierSample = DB::table('supplier_addresses')
                    ->join('addresses', 'supplier_addresses.address_id', '=', 'addresses.id')
                    ->where('addresses.country_id', $id)
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

                $skipped[] = [
                    'id' => $id,
                    'name' => $country->name ?? "ID: {$id}",
                    'reason' => 'Cannot delete country. It is referenced by existing customers or suppliers.',
                    'details' => $details,
                ];

                continue;
            }

            try {
                $deleted += $country->delete();
                app('cache')->store('database')->forget("tenant_{$tenantId}_country_{$id}");
            } catch (\Illuminate\Database\QueryException $e) {
                // Check if it's a foreign key constraint error and include details
                if ($e->getCode() == '23503') {
                    $details = [];

                    try {
                        $customerCount = DB::table('customer_addresses')
                            ->join('addresses', 'customer_addresses.address_id', '=', 'addresses.id')
                            ->where('addresses.country_id', $id)
                            ->count();
                        $supplierCount = DB::table('supplier_addresses')
                            ->join('addresses', 'supplier_addresses.address_id', '=', 'addresses.id')
                            ->where('addresses.country_id', $id)
                            ->count();
                        if ($customerCount > 0) {
                            $customerSample = DB::table('customer_addresses')
                                ->join('addresses', 'customer_addresses.address_id', '=', 'addresses.id')
                                ->where('addresses.country_id', $id)
                                ->limit(1)
                                ->pluck('customer_addresses.customer_id');
                            $details['customers'] = [
                                'count' => $customerCount,
                                'sample_ids' => $customerSample,
                            ];
                        }
                        if ($supplierCount > 0) {
                            $supplierSample = DB::table('supplier_addresses')
                                ->join('addresses', 'supplier_addresses.address_id', '=', 'addresses.id')
                                ->where('addresses.country_id', $id)
                                ->limit(1)
                                ->pluck('supplier_addresses.supplier_id');
                            $details['suppliers'] = [
                                'count' => $supplierCount,
                                'sample_ids' => $supplierSample,
                            ];
                        }
                    } catch (\Throwable $ignored) {
                    }

                    $country = Country::find($id);
                    $skipped[] = [
                        'id' => $id,
                        'name' => $country?->name ?? "ID: {$id}",
                        'reason' => 'Cannot delete country. It is referenced by existing customers or suppliers.',
                        'details' => $details,
                    ];
                } else {
                    $country = Country::find($id);
                    $skipped[] = [
                        'id' => $id,
                        'name' => $country?->name ?? "ID: {$id}",
                        'reason' => $e->getMessage(),
                    ];
                }
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
            'strictHeaders' => 'nullable|string|in:exact',
            'importMethod' => 'nullable|string|in:add_new_and_update_existing,add_new_only,update_existing_only,add_new_and_alert_existing,update_existing_and_alert_new',
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
        $strictHeaders = $request->input('strictHeaders') === 'exact';
        $importMethod = $request->input('importMethod');

        // Manual commit path for countries when importMethod is provided
        if ($request->input('type') === 'mapping' && $mapping && $importMethod) {
            $filePath = $request->file('file')->getRealPath();
            $spreadsheet = IOFactory::load($filePath);
            $sheet = $spreadsheet->getActiveSheet();
            $highestColumn = $sheet->getHighestColumn();
            $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);
            $highestRow = $sheet->getHighestRow();

            // Build header → column index map
            $excelHeaders = [];
            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $val = $sheet->getCellByColumnAndRow($col, 1)->getValue();
                if ($val !== null && $val !== '') {
                    $excelHeaders[trim((string) $val)] = $col;
                }
            }

            // Pre-check required field mappings and header existence
            $requiredFields = ['name'];
            $mappedFieldsLower = array_map(fn ($v) => mb_strtolower((string) $v), array_values($mapping));
            foreach ($requiredFields as $req) {
                if (! in_array($req, $mappedFieldsLower, true)) {
                    return response()->json([
                        'success' => false,
                        'message' => "Missing required field mapping for '{$req}'",
                    ], 422);
                }
            }
            // Validate that each mapped header exists (case-insensitive)
            $headersLower = [];
            foreach ($excelHeaders as $h => $i) {
                $headersLower[mb_strtolower($h)] = true;
            }
            $missingMappedHeaders = [];
            foreach ($mapping as $excelHeader => $field) {
                if (! isset($headersLower[mb_strtolower((string) $excelHeader)])) {
                    $missingMappedHeaders[] = $excelHeader;
                }
            }
            if (! empty($missingMappedHeaders)) {
                return response()->json([
                    'success' => false,
                    'message' => 'One or more mapped headers were not found in the file',
                    'missing_mapped_headers' => $missingMappedHeaders,
                ], 422);
            }
            if ($strictHeaders) {
                // Fail if file has columns beyond the mapped set
                $mappedHeaderSetLower = [];
                foreach (array_keys($mapping) as $mh) {
                    $mappedHeaderSetLower[mb_strtolower((string) $mh)] = true;
                }
                $extraHeaders = [];
                foreach (array_keys($headersLower) as $hLower) {
                    if (! isset($mappedHeaderSetLower[$hLower])) {
                        $extraHeaders[] = $hLower;
                    }
                }
                if (! empty($extraHeaders)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'File contains headers not present in mapping (strict mode)',
                        'extra_headers' => $extraHeaders,
                    ], 422);
                }
            }

            // Case-insensitive resolution of the name header from mapping
            $nameHeader = array_search('name', $mapping) ?: 'name';
            $excelHeadersLower = [];
            foreach ($excelHeaders as $label => $colIndex) {
                $excelHeadersLower[mb_strtolower($label)] = $colIndex;
            }
            $resolvedHeaderKey = isset($excelHeaders[$nameHeader]) ? $nameHeader : (array_key_exists(mb_strtolower($nameHeader), $excelHeadersLower) ? array_search($excelHeadersLower[mb_strtolower($nameHeader)], $excelHeaders) : null);
            if ($resolvedHeaderKey === null) {
                return response()->json([
                    'success' => false,
                    'message' => "Mapping header '{$nameHeader}' not found in file",
                ], 422);
            }

            $imported = 0;
            $updated = 0;
            $skipped = 0;
            $errorsOut = [];
            $alerts = [];
            for ($rowIdx = 2; $rowIdx <= $highestRow; $rowIdx++) {
                $rawName = $sheet->getCellByColumnAndRow($excelHeaders[$resolvedHeaderKey], $rowIdx)->getValue();
                $name = is_string($rawName) ? trim($rawName) : (string) $rawName;
                if ($name === '') {
                    $errorsOut[] = ['row' => $rowIdx, 'reasons' => ['Missing name']];

                    continue;
                }
                if (preg_match('/[0-9]/', $name)) {
                    $errorsOut[] = ['row' => $rowIdx, 'reasons' => ['Country name must not contain numbers']];

                    continue;
                }

                $existing = \App\Models\Country::whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($name)])->first();
                // Determine behavior based on importMethod (default to add_new_only)
                $method = $importMethod ?: 'add_new_only';
                switch ($method) {
                    case 'add_new_and_update_existing':
                        if ($existing) {
                            $existing->update(['name' => $name]);
                            $updated++;
                        } else {
                            \App\Models\Country::create(['name' => $name]);
                            $imported++;
                        }

                        break;
                    case 'add_new_only':
                        if ($existing) {
                            $skipped++;
                        } else {
                            \App\Models\Country::create(['name' => $name]);
                            $imported++;
                        }

                        break;
                    case 'update_existing_only':
                        if ($existing) {
                            $existing->update(['name' => $name]);
                            $updated++;
                        } else {
                            $skipped++;
                        }

                        break;
                    case 'add_new_and_alert_existing':
                        if ($existing) {
                            $alerts[] = ['row' => $rowIdx, 'reason' => 'Exists', 'existing_id' => $existing->id];
                            $skipped++;
                        } else {
                            \App\Models\Country::create(['name' => $name]);
                            $imported++;
                        }

                        break;
                    case 'update_existing_and_alert_new':
                        if ($existing) {
                            $existing->update(['name' => $name]);
                            $updated++;
                        } else {
                            $alerts[] = ['row' => $rowIdx, 'reason' => 'New row would be added'];
                            $skipped++;
                        }

                        break;
                }
            }

            app('cache')->store('database')->forget('tenant_'.tenant('id').'_countries');

            $totalProcessed = $imported + $updated + $skipped + count($errorsOut);
            $message = "Imported {$imported}, updated {$updated}, skipped {$skipped}.";

            return response()->json([
                'success' => ($imported + $updated) > 0,
                'message' => $message,
                'rows_processed' => $totalProcessed,
                'rows_imported' => $imported,
                'rows_updated' => $updated,
                'rows_skipped_count' => $skipped,
                'skipped_rows' => $errorsOut,
                'alerts' => $alerts,
            ]);
        }

        $import = new DynamicExcelImport(
            Country::class,
            ['name'],
            function ($row) use ($mapping) {
                $errors = [];

                $nameKey = $mapping ? array_search('name', $mapping) : 'name';
                $rawName = $row[$nameKey] ?? '';
                $name = is_string($rawName) ? trim($rawName) : '';

                if ($name === '') {
                    $errors[] = 'Missing name';
                } elseif (preg_match('/[0-9]/', $name)) {
                    $errors[] = 'Country name must not contain numbers';
                }

                return $errors;
            },
            function ($row) use ($mapping) {
                $nameKey = $mapping ? array_search('name', $mapping) : 'name';
                $rawName = $row[$nameKey] ?? '';
                $name = is_string($rawName) ? trim($rawName) : '';

                return [
                    'name' => $name,
                ];
            },
            // When mapping is provided, disable header validation because headers may not match schema
            $mapping ? false : true
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

        app('cache')->store('database')->forget('tenant_'.tenant('id').'_countries');

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

    public function importSchema(Request $request)
    {
        // Optional: accept table param but currently only countries supported here
        $schema = [
            'table' => (new \App\Models\Country)->getTable(),
            'upsertKeys' => ['name'],
            'fields' => [
                [
                    'field' => 'name',
                    'label' => 'Name',
                    'type' => 'string',
                    'required' => true,
                    'enum' => null,
                    'relation' => null,
                    'default' => null,
                    'allowedTransforms' => ['trim', 'uppercase', 'lowercase'],
                    'validators' => ['no_numbers'],
                ],
            ],
        ];

        return response()->json($schema);
    }

    public function importHeaders(Request $request)
    {
        $request->validate([
            'file' => [
                'required',
                'file',
                'mimes:xlsx,xls,csv,txt,text/plain,text/csv,application/csv',
            ],
        ], [
            'file.mimes' => 'The file field must be a file of type: xlsx, xls, csv',
        ]);

        $filePath = $request->file('file')->getRealPath();
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        $highestColumn = $sheet->getHighestColumn();
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);
        $headers = [];
        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $value = $sheet->getCellByColumnAndRow($col, 1)->getValue();
            if ($value !== null && $value !== '') {
                $headers[] = trim((string) $value);
            }
        }

        $suggestions = [];
        foreach ($headers as $h) {
            $norm = strtolower(trim($h));
            if ($norm === 'name' || $norm === 'country' || $norm === 'country name') {
                $suggestions[$h] = 'name';
            }
        }

        $sampleRow = [];
        if ($sheet->getHighestRow() >= 2) {
            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $header = $sheet->getCellByColumnAndRow($col, 1)->getValue();
                if ($header !== null && $header !== '') {
                    $sampleRow[trim((string) $header)] = $sheet->getCellByColumnAndRow($col, 2)->getValue();
                }
            }
        }

        return response()->json([
            'headers' => $headers,
            'sampleRow' => $sampleRow,
            'suggestions' => $suggestions,
        ]);
    }

    public function importDryRun(Request $request)
    {
        $request->validate([
            'file' => [
                'required',
                'file',
                'mimes:xlsx,xls,csv,txt,text/plain,text/csv,application/csv',
            ],
            'mapping' => 'required|array',
            'strictHeaders' => 'nullable|string|in:exact',
            'importMethod' => 'nullable|string|in:add_new_and_update_existing,add_new_only,update_existing_only,add_new_and_alert_existing,update_existing_and_alert_new',
        ]);

        $mapping = $request->input('mapping');
        $strictHeaders = $request->input('strictHeaders') === 'exact';
        $importMethod = $request->input('importMethod');

        $filePath = $request->file('file')->getRealPath();
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $highestColumn = $sheet->getHighestColumn();
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);
        $highestRow = $sheet->getHighestRow();

        // Build header array
        $excelHeaders = [];
        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $val = $sheet->getCellByColumnAndRow($col, 1)->getValue();
            if ($val !== null && $val !== '') {
                $excelHeaders[$col] = trim((string) $val);
            }
        }

        // Pre-check required field mappings and header existence
        $requiredFields = ['name'];
        $mappedFieldsLower = array_map(fn ($v) => mb_strtolower((string) $v), array_values($mapping));
        $missingRequired = [];
        foreach ($requiredFields as $req) {
            if (! in_array($req, $mappedFieldsLower, true)) {
                $missingRequired[] = $req;
            }
        }
        if (! empty($missingRequired)) {
            return response()->json([
                'ok' => false,
                'message' => 'Missing required field mappings',
                'missing_mappings' => $missingRequired,
            ], 422);
        }

        $headersLower = [];
        foreach ($excelHeaders as $col => $h) {
            $headersLower[mb_strtolower($h)] = true;
        }
        $missingMappedHeaders = [];
        foreach ($mapping as $excelHeader => $field) {
            if (! isset($headersLower[mb_strtolower((string) $excelHeader)])) {
                $missingMappedHeaders[] = $excelHeader;
            }
        }
        if (! empty($missingMappedHeaders)) {
            return response()->json([
                'ok' => false,
                'message' => 'One or more mapped headers were not found in the file',
                'missing_mapped_headers' => $missingMappedHeaders,
            ], 422);
        }
        if ($strictHeaders) {
            $mappedHeaderSetLower = [];
            foreach (array_keys($mapping) as $mh) {
                $mappedHeaderSetLower[mb_strtolower((string) $mh)] = true;
            }
            $extraHeaders = [];
            foreach (array_values($excelHeaders) as $h) {
                $hl = mb_strtolower($h);
                if (! isset($mappedHeaderSetLower[$hl])) {
                    $extraHeaders[] = $h;
                }
            }
            if (! empty($extraHeaders)) {
                return response()->json([
                    'ok' => false,
                    'message' => 'File contains headers not present in mapping (strict mode)',
                    'extra_headers' => $extraHeaders,
                ], 422);
            }
        }

        $rows = [];
        for ($rowIdx = 2; $rowIdx <= $highestRow; $rowIdx++) {
            $rowAssoc = [];
            foreach ($excelHeaders as $col => $headerLabel) {
                $rowAssoc[$headerLabel] = $sheet->getCellByColumnAndRow($col, $rowIdx)->getValue();
            }
            $rows[] = $rowAssoc;
        }

        $results = [];
        $insertCount = 0;
        $updateCount = 0;
        $skipCount = 0;
        $errorCount = 0;
        $alerts = [];
        foreach ($rows as $index => $row) {
            // Apply mapping (excel header -> model field) with case-insensitive header matching
            $modelData = [];
            $rowLower = [];
            foreach ($row as $k => $v) {
                $rowLower[mb_strtolower($k)] = $v;
            }
            foreach ($mapping as $excelHeader => $modelField) {
                $value = $rowLower[mb_strtolower($excelHeader)] ?? null;
                if (is_string($value)) {
                    $value = trim($value);
                }

                // Normalize target field name (case-insensitive)
                $normalizedField = mb_strtolower(trim((string) $modelField));

                if (in_array($normalizedField, ['active'])) {
                    if (is_string($value)) {
                        $lv = strtolower(trim($value));
                        $value = in_array($lv, ['1', 'true', 'yes', 'y']) ? true : (in_array($lv, ['0', 'false', 'no', 'n']) ? false : $value);
                    }
                }
                // Only accept known fields
                if (in_array($normalizedField, ['name'])) {
                    $modelData[$normalizedField] = $value;
                }
            }

            // Validate per-field
            $errors = [];
            $name = isset($modelData['name']) ? trim((string) $modelData['name']) : '';
            if ($name === '') {
                $errors['name'][] = 'Missing name';
            } elseif (preg_match('/[0-9]/', $name)) {
                $errors['name'][] = 'Country name must not contain numbers';
            }

            // Determine action (insert/update/skip) based on unique name
            $existing = null;
            if ($name !== '') {
                $existing = \App\Models\Country::whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($name)])->first();
            }

            // Classify based on importMethod (default add_new_only)
            $method = $importMethod ?: 'add_new_only';
            $action = 'insert';
            if ($existing) {
                if (in_array($method, ['add_new_and_update_existing', 'update_existing_only', 'update_existing_and_alert_new'])) {
                    $action = 'update';
                } elseif (in_array($method, ['add_new_only', 'add_new_and_alert_existing'])) {
                    $action = 'skip';
                    if ($method === 'add_new_and_alert_existing') {
                        $alerts[] = [
                            'row' => $index + 2,
                            'reason' => 'Exists',
                            'existing' => ['id' => $existing->id, 'name' => $existing->name],
                        ];
                    }
                }
            } else {
                if (in_array($method, ['add_new_and_update_existing', 'add_new_only', 'add_new_and_alert_existing'])) {
                    $action = 'insert';
                } elseif (in_array($method, ['update_existing_only', 'update_existing_and_alert_new'])) {
                    $action = 'skip';
                    if ($method === 'update_existing_and_alert_new') {
                        $alerts[] = [
                            'row' => $index + 2,
                            'reason' => 'New row would be added',
                            'data' => ['name' => $name],
                        ];
                    }
                }
            }

            if (! empty($errors)) {
                $errorCount++;
                $results[] = [
                    'row' => $index + 2,
                    'action' => 'error',
                    'errors' => $errors,
                    'data' => $modelData,
                ];

                continue;
            }

            if ($action === 'insert') {
                $insertCount++;
            } elseif ($action === 'update') {
                $updateCount++;
            } else {
                $skipCount++;
            }

            $results[] = [
                'row' => $index + 2,
                'action' => $action,
                'errors' => (object) [],
                'data' => $modelData,
                'existing' => $existing ? ['id' => $existing->id, 'name' => $existing->name] : null,
            ];
        }

        return response()->json([
            'stats' => [
                'total' => count($rows),
                'insert' => $insertCount,
                'update' => $updateCount,
                'skip' => $skipCount,
                'errors' => $errorCount,
            ],
            'rows' => $results,
            'alerts' => $alerts,
        ]);
    }
}
