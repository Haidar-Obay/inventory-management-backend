<?php

namespace App\Http\Controllers;

use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Facades\Excel;

class WarehouseController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $cacheKey = "warehouses_{$tenantId}";

        return Cache::remember($cacheKey, 3600, function () {
            return Warehouse::with('parentWarehouse')->get();
        });
    }

    public function store(Request $request)
    {
        $validated = $request->validate(Warehouse::$rules);

        // Check if the parent warehouse exists and is not inactive
        if (! empty($validated['sub_warehouse_of'])) {
            $parentWarehouse = Warehouse::find($validated['sub_warehouse_of']);
            if (! $parentWarehouse) {
                return response()->json(['message' => 'Parent warehouse not found'], 404);
            }
            if ($parentWarehouse->is_inactive) {
                return response()->json(['message' => 'Cannot create sub-warehouse for an inactive parent warehouse'], 422);
            }
        }

        $warehouse = Warehouse::create($validated);
        Cache::forget('warehouses_'.tenant('id'));

        return response()->json($warehouse, 201);
    }

    public function show(Warehouse $warehouse)
    {
        $tenantId = tenant('id');
        $cacheKey = "warehouse_{$warehouse->id}_{$tenantId}";

        return Cache::remember($cacheKey, 3600, function () use ($warehouse) {
            return $warehouse->load('parentWarehouse', 'subWarehouses');
        });
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
            if ($parentWarehouse->is_inactive) {
                return response()->json(['message' => 'Cannot set an inactive warehouse as parent'], 422);
            }
            if ($warehouse->isSubWarehouseOf($parentWarehouse)) {
                return response()->json(['message' => 'Cannot set a sub-warehouse as parent (circular reference)'], 422);
            }
        }

        $warehouse->update($validated);

        Cache::forget('warehouses_'.tenant('id'));
        Cache::forget("warehouse_{$warehouse->id}_".tenant('id'));

        return response()->json($warehouse);
    }

    public function destroy(Warehouse $warehouse)
    {
        // Check if warehouse has sub-warehouses
        if ($warehouse->subWarehouses()->exists()) {
            return response()->json(['message' => 'Cannot delete warehouse with sub-warehouses'], 422);
        }

        $warehouse->delete();
        Cache::forget('warehouses_'.tenant('id'));
        Cache::forget("warehouse_{$warehouse->id}_".tenant('id'));

        return response()->json(null, 204);
    }

    public function bulkDelete(Request $request)
    {
        $ids = $request->input('ids');

        if (! $ids || ! is_array($ids)) {
            return response()->json(['message' => 'No warehouses selected'], 400);
        }

        $warehouses = Warehouse::whereIn('id', $ids)->get();
        $errors = [];

        foreach ($warehouses as $warehouse) {
            if ($warehouse->subWarehouses()->exists()) {
                $errors[] = "Warehouse {$warehouse->name} has sub-warehouses and cannot be deleted";
            }
        }

        if (! empty($errors)) {
            return response()->json(['message' => 'Some warehouses could not be deleted', 'errors' => $errors], 422);
        }

        Warehouse::whereIn('id', $ids)->delete();
        Cache::forget('warehouses_'.tenant('id'));

        return response()->json(['message' => 'Warehouses deleted successfully']);
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

        return Excel::download(new ExportPDF($warehousecontroller, ['id', 'code', 'name', 'active', 'created_at', 'updated_at'], ['ID', 'Code', 'Name', 'Active', 'Created At', 'Updated At']), $fileName);
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

            Cache::forget('warehouses_'.tenant('id'));

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
