<?php

namespace App\Http\Controllers;

use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;
use App\Models\District;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

class DistrictController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_districts";

        $districts = app('cache')->store('database')->get($key);

        if (! $districts) {
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

        $nextId = $this->computeNextAvailableId(District::class, 'id');
        $district = new District($validated);
        $district->id = $nextId;
        $district->save();

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

        if (! $cachedDistrict) {
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
        // Prevent deletion if referenced by customers or suppliers (via addresses)
        $customerCount = DB::table('customer_addresses')
            ->join('addresses', 'customer_addresses.address_id', '=', 'addresses.id')
            ->where('addresses.district_id', $district->id)
            ->count();
        $supplierCount = DB::table('supplier_addresses')
            ->join('addresses', 'supplier_addresses.address_id', '=', 'addresses.id')
            ->where('addresses.district_id', $district->id)
            ->count();

        if ($customerCount > 0 || $supplierCount > 0) {
            $customerSample = DB::table('customer_addresses')
                ->join('addresses', 'customer_addresses.address_id', '=', 'addresses.id')
                ->where('addresses.district_id', $district->id)
                ->limit(1)
                ->pluck('customer_addresses.customer_id');
            $supplierSample = DB::table('supplier_addresses')
                ->join('addresses', 'supplier_addresses.address_id', '=', 'addresses.id')
                ->where('addresses.district_id', $district->id)
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
                'message' => 'Cannot delete district. It is referenced by existing customers or suppliers.',
                'details' => $details,
            ], 409);
        }

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
            $district = District::find($id);

            // Check if referenced by customers or suppliers (via addresses) and include details
            $customerCount = DB::table('customer_addresses')
                ->join('addresses', 'customer_addresses.address_id', '=', 'addresses.id')
                ->where('addresses.district_id', $id)
                ->count();
            $supplierCount = DB::table('supplier_addresses')
                ->join('addresses', 'supplier_addresses.address_id', '=', 'addresses.id')
                ->where('addresses.district_id', $id)
                ->count();

            if ($customerCount > 0 || $supplierCount > 0) {
                $customerSample = DB::table('customer_addresses')
                    ->join('addresses', 'customer_addresses.address_id', '=', 'addresses.id')
                    ->where('addresses.district_id', $id)
                    ->limit(1)
                    ->pluck('customer_addresses.customer_id');
                $supplierSample = DB::table('supplier_addresses')
                    ->join('addresses', 'supplier_addresses.address_id', '=', 'addresses.id')
                    ->where('addresses.district_id', $id)
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
                    'reason' => 'Cannot delete district. It is referenced by existing customers or suppliers.',
                    'details' => $details,
                ];

                continue;
            }

            try {
                $deleted += $district->delete();
                app('cache')->store('database')->forget("tenant_{$tenantId}_district_{$id}");
            } catch (\Illuminate\Database\QueryException $e) {
                // Check if it's a foreign key constraint error and include details
                if ($e->getCode() == '23503') {
                    $details = [];

                    try {
                        $customerCount = DB::table('customer_addresses')
                            ->join('addresses', 'customer_addresses.address_id', '=', 'addresses.id')
                            ->where('addresses.district_id', $id)
                            ->count();
                        $supplierCount = DB::table('supplier_addresses')
                            ->join('addresses', 'supplier_addresses.address_id', '=', 'addresses.id')
                            ->where('addresses.district_id', $id)
                            ->count();
                        if ($customerCount > 0) {
                            $customerSample = DB::table('customer_addresses')
                                ->join('addresses', 'customer_addresses.address_id', '=', 'addresses.id')
                                ->where('addresses.district_id', $id)
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
                                ->where('addresses.district_id', $id)
                                ->limit(1)
                                ->pluck('supplier_addresses.supplier_id');
                            $details['suppliers'] = [
                                'count' => $supplierCount,
                                'sample_ids' => $supplierSample,
                            ];
                        }
                    } catch (\Throwable $ignored) {
                    }

                    $skipped[] = [
                        'id' => $id,
                        'reason' => 'Cannot delete district. It is referenced by existing customers or suppliers.',
                        'details' => $details,
                    ];
                } else {
                    $skipped[] = ['id' => $id, 'reason' => $e->getMessage()];
                }
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
            District::truncate();
        }

        // If type is 'mapping', use provided mapping, else use default
        $mapping = $request->input('mapping');

        $import = new DynamicExcelImport(
            District::class,
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
                    $errors[] = 'District name must not contain numbers';
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

        app('cache')->store('database')->forget('tenant_'.tenant('id').'_districts');

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

    public function exportExcell()
    {
        $districts = District::withCount('addresses')->orderBy('name');
        $collection = $districts->get();

        if ($collection->isEmpty()) {
            return response()->json(['message' => 'No districts found.'], 404);
        }

        $columns = ['id', 'name', 'created_at', 'updated_at', 'created_at', 'updated_at'];
        $headings = ['ID', 'Name', 'Created At', 'Updated At', 'Created At', 'Updated At'];

        return Excel::download(new Export($districts, $columns, $headings), 'districts.xlsx');
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $districts = District::select('id', 'name', 'created_at', 'updated_at')->get();

        if ($districts->isEmpty()) {
            return response()->json(['message' => 'No districts found.'], 404);
        }

        $title = 'District Report';
        $headers = [
            'id' => 'District ID',
            'name' => 'District Name',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
        ];
        $data = $districts->toArray();

        $pdf = $pdfService->generatePdf($title, $headers, $data);

        return $pdf->download('Districts.pdf');
    }
}
