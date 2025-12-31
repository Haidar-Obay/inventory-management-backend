<?php

namespace App\Http\Controllers;

use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class WarehouseController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_warehouses";

        $warehouses = app('cache')->store('database')->get($key);

        if (! $warehouses) {
            $warehouses = Warehouse::with('parentWarehouse')->get();
            app('cache')->store('database')->forever($key, $warehouses);
        }

        return response()->json([
            'status' => true,
            'message' => 'Warehouses fetched successfully.',
            'data' => $warehouses,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate(Warehouse::$rules);

        // Check if the parent warehouse exists and is active
        if (! empty($validated['sub_warehouse_of'])) {
            $parentWarehouse = Warehouse::find($validated['sub_warehouse_of']);
            if (! $parentWarehouse) {
                return response()->json(['message' => 'Parent warehouse not found'], 404);
            }
            if (! $parentWarehouse->active) {
                return response()->json(['message' => 'Cannot create sub-warehouse for an inactive parent warehouse'], 422);
            }
        }

        $nextId = $this->computeNextAvailableId(Warehouse::class, 'id');
        $warehouse = new Warehouse($validated);
        $warehouse->id = $nextId;
        $warehouse->save();

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_warehouses");

        // Load the parent warehouse relationship for the response
        $warehouse->load('parentWarehouse');

        return response()->json($warehouse, 201);
    }

    public function show(Warehouse $warehouse)
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_warehouse_{$warehouse->id}";

        $cachedWarehouse = app('cache')->store('database')->get($key);

        if (! $cachedWarehouse) {
            $cachedWarehouse = $warehouse->load('parentWarehouse', 'subWarehouses');
            app('cache')->store('database')->forever($key, $cachedWarehouse);
        }

        return response()->json([
            'status' => true,
            'message' => 'Warehouse fetched successfully.',
            'data' => $cachedWarehouse,
        ]);
    }

    public function update(Request $request, Warehouse $warehouse)
    {
        $rules = Warehouse::$rules;
        $rules['code'] = 'required|string|max:50|unique:warehouses,code,'.$warehouse->id;

        $validated = $request->validate($rules);

        // Check if trying to set a sub-warehouse as parent
        if (! empty($validated['sub_warehouse_of'])) {
            $parentWarehouse = Warehouse::find($validated['sub_warehouse_of']);
            if (! $parentWarehouse) {
                return response()->json(['message' => 'Parent warehouse not found'], 404);
            }
            if (! $parentWarehouse->active) {
                return response()->json(['message' => 'Cannot set an inactive warehouse as parent'], 422);
            }
            if ($warehouse->isSubWarehouseOf($parentWarehouse)) {
                return response()->json(['message' => 'Cannot set a sub-warehouse as parent (circular reference)'], 422);
            }
        }

        $warehouse->update($validated);

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_warehouses");
        app('cache')->store('database')->forget("tenant_{$tenantId}_warehouse_{$warehouse->id}");

        // Load the parent warehouse relationship for the response
        $warehouse->load('parentWarehouse');

        return response()->json($warehouse);
    }

    public function destroy(Warehouse $warehouse)
    {
        $identifier = $warehouse->name ?? $warehouse->code ?? "ID: {$warehouse->id}";

        // Check if warehouse has sub-warehouses
        if ($warehouse->subWarehouses()->exists()) {
            $subWarehousesCount = $warehouse->subWarehouses()->count();
            $sampleIds = $warehouse->subWarehouses()->select('warehouses.id')->limit(1)->pluck('id');

            return response()->json([
                'status' => false,
                'message' => "Cannot delete warehouse \"{$identifier}\" (ID: {$warehouse->id}). It has sub-warehouses.",
                'details' => [
                    'sub_warehouses' => [
                        'count' => $subWarehousesCount,
                        'sample_ids' => $sampleIds,
                    ],
                ],
            ], 409);
        }

        $warehouse->delete();

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_warehouses");
        app('cache')->store('database')->forget("tenant_{$tenantId}_warehouse_{$warehouse->id}");

        return response()->json([
            'status' => true,
            'message' => 'Warehouse deleted successfully.',
        ]);
    }

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:warehouses,id',
        ]);

        $ids = $request->input('ids');
        $skipped = [];
        $deleted = 0;

        foreach ($ids as $id) {
            try {
                $warehouse = Warehouse::find($id);

                if (! $warehouse) {
                    $skipped[] = [
                        'id' => $id,
                        'name' => "ID: {$id}",
                        'reason' => 'Warehouse not found.',
                    ];

                    continue;
                }

                $identifier = $warehouse->name ?? $warehouse->code ?? "ID: {$id}";

                // Check if warehouse has sub-warehouses
                if ($warehouse->subWarehouses()->exists()) {
                    $subWarehousesCount = $warehouse->subWarehouses()->count();
                    $details = [
                        'sub_warehouses' => [
                            'count' => $subWarehousesCount,
                            'sample_ids' => $warehouse->subWarehouses()->select('warehouses.id')->limit(1)->pluck('id'),
                        ],
                    ];

                    $skipped[] = [
                        'id' => $id,
                        'name' => $identifier,
                        'reason' => 'Cannot delete warehouse. It has sub-warehouses.',
                        'details' => $details,
                    ];

                    continue;
                }

                $warehouse->delete();
                $deleted++;
                $tenantId = tenant('id');
                app('cache')->store('database')->forget("tenant_{$tenantId}_warehouse_{$id}");
            } catch (\Exception $e) {
                $warehouse = Warehouse::find($id);
                $identifier = $warehouse?->name ?? $warehouse?->code ?? "ID: {$id}";
                $skipped[] = [
                    'id' => $id,
                    'name' => $identifier,
                    'reason' => $e->getMessage(),
                ];
            }
        }

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_warehouses");

        return response()->json([
            'message' => 'Bulk delete completed.',
            'deleted_count' => $deleted,
            'skipped' => $skipped,
        ]);
    }

    public function exportExcell()
    {
        $warehouses = Warehouse::with('parentWarehouse')->get();

        if ($warehouses->isEmpty()) {
            return response()->json(['message' => 'No warehouses to export'], 404);
        }

        $fileName = 'warehouses_'.date('Y-m-d_H-i-s').'.xlsx';

        return Excel::download(new Export($warehouses, ['id', 'name', 'location', 'active', 'created_at', 'updated_at'], ['ID', 'Name', 'Location', 'Active', 'Created At', 'Updated At']), $fileName);
    }

    public function exportPdf()
    {
        $warehouses = Warehouse::with('parentWarehouse')->get();

        if ($warehouses->isEmpty()) {
            return response()->json(['message' => 'No warehouses to export'], 404);
        }

        $fileName = 'warehouses_'.date('Y-m-d_H-i-s').'.pdf';

        return Excel::download(new ExportPDF($warehouses, ['id', 'code', 'name', 'active', 'created_at', 'updated_at'], ['ID', 'Code', 'Name', 'Active', 'Created At', 'Updated At']), $fileName);
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
            Warehouse::truncate();
        }

        // If type is 'mapping', use provided mapping, else use default
        $mapping = $request->input('mapping');
        $fields = $mapping ? array_values($mapping) : ['name', 'address', 'city', 'country', 'active'];

        try {
            $import = new DynamicExcelImport(
                Warehouse::class,
                $fields,
                function ($row) use ($mapping) {
                    $errors = [];
                    $nameKey = $mapping ? array_search('name', $mapping) : 'name';
                    if (empty($row[$nameKey])) {
                        $errors[] = 'Missing name';
                    }

                    return $errors;
                },
                function ($row) use ($mapping) {
                    $nameKey = $mapping ? array_search('name', $mapping) : 'name';
                    $addressKey = $mapping ? array_search('address', $mapping) : 'address';
                    $cityKey = $mapping ? array_search('city', $mapping) : 'city';
                    $countryKey = $mapping ? array_search('country', $mapping) : 'country';
                    $activeKey = $mapping ? array_search('active', $mapping) : 'active';

                    return [
                        'name' => $row[$nameKey] ?? null,
                        'address' => $row[$addressKey] ?? null,
                        'city' => $row[$cityKey] ?? null,
                        'country' => $row[$countryKey] ?? null,
                        'active' => boolval($row[$activeKey] ?? true),
                    ];
                },
                true // Enable header validation
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

            $tenantId = tenant('id');
            app('cache')->store('database')->forget("tenant_{$tenantId}_warehouses");

            return response()->json([
                'message' => 'Warehouses imported successfully',
                'rows_imported' => $import->getImportedCount(),
                'rows_skipped_count' => $import->getSkippedCount(),
                'skipped_rows' => $import->getSkippedRows(),
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error importing warehouses: '.$e->getMessage()], 500);
        }
    }
}
