<?php

namespace App\Http\Controllers;

use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;

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
        if (!empty($validated['sub_warehouse_of'])) {
            $parentWarehouse = Warehouse::find($validated['sub_warehouse_of']);
            if (!$parentWarehouse) {
                return response()->json(['message' => 'Parent warehouse not found'], 404);
            }
            if ($parentWarehouse->is_inactive) {
                return response()->json(['message' => 'Cannot create sub-warehouse for an inactive parent warehouse'], 422);
            }
        }

        $warehouse = Warehouse::create($validated);
        Cache::forget("warehouses_" . tenant('id'));

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
        $rules['code'] = 'required|string|max:50|unique:warehouses,code,' . $warehouse->id;

        $validated = $request->validate($rules);

        // Check if trying to set a sub-warehouse as parent
        if (!empty($validated['sub_warehouse_of'])) {
            $parentWarehouse = Warehouse::find($validated['sub_warehouse_of']);
            if (!$parentWarehouse) {
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

        Cache::forget("warehouses_" . tenant('id'));
        Cache::forget("warehouse_{$warehouse->id}_" . tenant('id'));

        return response()->json($warehouse);
    }

    public function destroy(Warehouse $warehouse)
    {
        // Check if warehouse has sub-warehouses
        if ($warehouse->subWarehouses()->exists()) {
            return response()->json(['message' => 'Cannot delete warehouse with sub-warehouses'], 422);
        }

        $warehouse->delete();
        Cache::forget("warehouses_" . tenant('id'));
        Cache::forget("warehouse_{$warehouse->id}_" . tenant('id'));

        return response()->json(null, 204);
    }

    public function bulkDelete(Request $request)
    {
        $ids = $request->input('ids');

        if (!$ids || !is_array($ids)) {
            return response()->json(['message' => 'No warehouses selected'], 400);
        }

        $warehouses = Warehouse::whereIn('id', $ids)->get();
        $errors = [];

        foreach ($warehouses as $warehouse) {
            if ($warehouse->subWarehouses()->exists()) {
                $errors[] = "Warehouse {$warehouse->name} has sub-warehouses and cannot be deleted";
            }
        }

        if (!empty($errors)) {
            return response()->json(['message' => 'Some warehouses could not be deleted', 'errors' => $errors], 422);
        }

        Warehouse::whereIn('id', $ids)->delete();
        Cache::forget("warehouses_" . tenant('id'));

        return response()->json(['message' => 'Warehouses deleted successfully']);
    }

    public function exportExcell()
    {
        $warehouses = Warehouse::with('parentWarehouse')->get();

        if ($warehouses->isEmpty()) {
            return response()->json(['message' => 'No warehouses to export'], 404);
        }

        $fileName = 'warehouses_' . date('Y-m-d_H-i-s') . '.xlsx';
        return Excel::download(new Export($warehouses), $fileName);
    }

    public function exportPdf()
    {
        $warehouses = Warehouse::with('parentWarehouse')->get();

        if ($warehouses->isEmpty()) {
            return response()->json(['message' => 'No warehouses to export'], 404);
        }

        $fileName = 'warehouses_' . date('Y-m-d_H-i-s') . '.pdf';
        return Excel::download(new ExportPDF($warehouses), $fileName);
    }

    public function importFromExcel(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls',
        ]);

        try {
            $import = new DynamicExcelImport(new Warehouse());
            Excel::import($import, $request->file('file'));
            Cache::forget("warehouses_" . tenant('id'));

            return response()->json(['message' => 'Warehouses imported successfully']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error importing warehouses: ' . $e->getMessage()], 500);
        }
    }
}
