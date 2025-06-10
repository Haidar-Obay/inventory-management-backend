<?php

namespace App\Http\Controllers;

use App\Models\BusinessType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;

class BusinessTypeController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $cacheKey = "business_types_{$tenantId}";

        return Cache::remember($cacheKey, 3600, function () {
            return BusinessType::all();
        });
    }

    public function store(Request $request)
    {
        $validated = $request->validate(BusinessType::$rules);
        $businessType = BusinessType::create($validated);
        Cache::forget("business_types_" . tenant('id'));

        return response()->json($businessType, 201);
    }

    public function show(BusinessType $businessType)
    {
        $tenantId = tenant('id');
        $cacheKey = "business_type_{$businessType->id}_{$tenantId}";

        return Cache::remember($cacheKey, 3600, function () use ($businessType) {
            return $businessType;
        });
    }

    public function update(Request $request, BusinessType $businessType)
    {
        $rules = BusinessType::$rules;
        $rules['code'] = 'required|string|max:50|unique:business_types,code,' . $businessType->id;

        $validated = $request->validate($rules);
        $businessType->update($validated);

        Cache::forget("business_types_" . tenant('id'));
        Cache::forget("business_type_{$businessType->id}_" . tenant('id'));

        return response()->json($businessType);
    }

    public function destroy(BusinessType $businessType)
    {
        // Add any necessary checks before deletion
        // For example, check if the business type is being used in any customers or suppliers

        $businessType->delete();
        Cache::forget("business_types_" . tenant('id'));
        Cache::forget("business_type_{$businessType->id}_" . tenant('id'));

        return response()->json(null, 204);
    }

    public function bulkDelete(Request $request)
    {
        $ids = $request->input('ids');

        if (!$ids || !is_array($ids)) {
            return response()->json(['message' => 'No business types selected'], 400);
        }

        // Add any necessary checks before bulk deletion
        // For example, check if any of the business types are being used in customers or suppliers

        BusinessType::whereIn('id', $ids)->delete();
        Cache::forget("business_types_" . tenant('id'));

        return response()->json(['message' => 'Business types deleted successfully']);
    }

    public function exportExcell()
    {
        $businessTypes = BusinessType::all();

        if ($businessTypes->isEmpty()) {
            return response()->json(['message' => 'No business types to export'], 404);
        }

        $fileName = 'business_types_' . date('Y-m-d_H-i-s') . '.xlsx';
        return Excel::download(new Export($businessTypes), $fileName);
    }

    public function exportPdf()
    {
        $businessTypes = BusinessType::all();

        if ($businessTypes->isEmpty()) {
            return response()->json(['message' => 'No business types to export'], 404);
        }

        $fileName = 'business_types_' . date('Y-m-d_H-i-s') . '.pdf';
        return Excel::download(new ExportPDF($businessTypes), $fileName);
    }

    public function importFromExcel(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls',
        ]);

        try {
            $import = new DynamicExcelImport(new BusinessType());
            Excel::import($import, $request->file('file'));
            Cache::forget("business_types_" . tenant('id'));

            return response()->json(['message' => 'Business types imported successfully']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error importing business types: ' . $e->getMessage()], 500);
        }
    }
}
