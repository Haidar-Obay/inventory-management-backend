<?php

namespace App\Http\Controllers;

use App\Models\AdjustmentType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;

class AdjustmentTypeController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $cacheKey = "adjustment_types_{$tenantId}";

        return Cache::remember($cacheKey, 3600, function () {
            return AdjustmentType::all();
        });
    }

    public function store(Request $request)
    {
        $validated = $request->validate(AdjustmentType::$rules);
        $adjustmentType = AdjustmentType::create($validated);
        Cache::forget("adjustment_types_" . tenant('id'));

        return response()->json($adjustmentType, 201);
    }

    public function show(AdjustmentType $adjustmentType)
    {
        $tenantId = tenant('id');
        $cacheKey = "adjustment_type_{$adjustmentType->id}_{$tenantId}";

        return Cache::remember($cacheKey, 3600, function () use ($adjustmentType) {
            return $adjustmentType;
        });
    }

    public function update(Request $request, AdjustmentType $adjustmentType)
    {
        $rules = AdjustmentType::$rules;
        $rules['code'] = 'required|string|max:50|unique:adjustment_types,code,' . $adjustmentType->id;

        $validated = $request->validate($rules);
        $adjustmentType->update($validated);

        Cache::forget("adjustment_types_" . tenant('id'));
        Cache::forget("adjustment_type_{$adjustmentType->id}_" . tenant('id'));

        return response()->json($adjustmentType);
    }

    public function destroy(AdjustmentType $adjustmentType)
    {
        // Add any necessary checks before deletion
        // For example, check if the adjustment type is being used in any transactions

        $adjustmentType->delete();
        Cache::forget("adjustment_types_" . tenant('id'));
        Cache::forget("adjustment_type_{$adjustmentType->id}_" . tenant('id'));

        return response()->json(null, 204);
    }

    public function bulkDelete(Request $request)
    {
        $ids = $request->input('ids');

        if (!$ids || !is_array($ids)) {
            return response()->json(['message' => 'No adjustment types selected'], 400);
        }

        // Add any necessary checks before bulk deletion
        // For example, check if any of the adjustment types are being used in transactions

        AdjustmentType::whereIn('id', $ids)->delete();
        Cache::forget("adjustment_types_" . tenant('id'));

        return response()->json(['message' => 'Adjustment types deleted successfully']);
    }

    public function exportExcell()
    {
        $adjustmentTypes = AdjustmentType::all();

        if ($adjustmentTypes->isEmpty()) {
            return response()->json(['message' => 'No adjustment types to export'], 404);
        }

        $fileName = 'adjustment_types_' . date('Y-m-d_H-i-s') . '.xlsx';
        return Excel::download(new Export($adjustmentTypes), $fileName);
    }

    public function exportPdf()
    {
        $adjustmentTypes = AdjustmentType::all();

        if ($adjustmentTypes->isEmpty()) {
            return response()->json(['message' => 'No adjustment types to export'], 404);
        }

        $fileName = 'adjustment_types_' . date('Y-m-d_H-i-s') . '.pdf';
        return Excel::download(new ExportPDF($adjustmentTypes), $fileName);
    }

    public function importFromExcel(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls',
        ]);

        try {
            $import = new DynamicExcelImport(new AdjustmentType());
            Excel::import($import, $request->file('file'));
            Cache::forget("adjustment_types_" . tenant('id'));

            return response()->json(['message' => 'Adjustment types imported successfully']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error importing adjustment types: ' . $e->getMessage()], 500);
        }
    }

    public function getTransactionTypes()
    {
        return response()->json(AdjustmentType::getTransactionTypes());
    }
}
