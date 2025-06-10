<?php

namespace App\Http\Controllers;

use App\Models\CostCenter;
use App\Http\Requests\CostCenter\StoreCostCenterRequest;
use App\Http\Requests\CostCenter\UpdateCostCenterRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;

class CostCenterController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $cacheKey = "cost_centers_{$tenantId}";

        return Cache::remember($cacheKey, 3600, function () {
            return CostCenter::with('parentCostCenter')->get();
        });
    }

    public function store(StoreCostCenterRequest $request)
    {
        $validated = $request->validated();

        // Prevent circular reference
        if (isset($validated['sub_cost_center_of'])) {
            $this->validateNoCircularReference($validated['sub_cost_center_of']);
        }

        $costCenter = CostCenter::create($validated);
        Cache::forget("cost_centers_" . tenant('id'));

        return response()->json($costCenter->load('parentCostCenter'), 201);
    }

    public function show(CostCenter $costCenter)
    {
        $tenantId = tenant('id');
        $cacheKey = "cost_center_{$costCenter->id}_{$tenantId}";

        return Cache::remember($cacheKey, 3600, function () use ($costCenter) {
            return $costCenter->load(['parentCostCenter', 'subCostCenters']);
        });
    }

    public function update(UpdateCostCenterRequest $request, CostCenter $costCenter)
    {
        $rules = CostCenter::$rules;
        $rules['code'] = 'required|string|max:50|unique:cost_centers,code,' . $costCenter->id;

        $validated = $request->validated();

        // Prevent circular reference
        if (isset($validated['sub_cost_center_of'])) {
            $this->validateNoCircularReference($validated['sub_cost_center_of'], $costCenter->id);
        }

        $costCenter->update($validated);

        Cache::forget("cost_centers_" . tenant('id'));
        Cache::forget("cost_center_{$costCenter->id}_" . tenant('id'));

        return response()->json($costCenter->load(['parentCostCenter', 'subCostCenters']));
    }

    public function destroy(CostCenter $costCenter)
    {
        if ($costCenter->hasSubCostCenters()) {
            return response()->json([
                'message' => 'Cannot delete cost center with sub-cost centers'
            ], 422);
        }

        $costCenter->delete();
        Cache::forget("cost_centers_" . tenant('id'));
        Cache::forget("cost_center_{$costCenter->id}_" . tenant('id'));

        return response()->json(null, 204);
    }

    public function bulkDelete(Request $request)
    {
        $ids = $request->input('ids');

        if (!$ids || !is_array($ids)) {
            return response()->json(['message' => 'No cost centers selected'], 400);
        }

        // Check if any cost center has sub-cost centers
        $costCentersWithSubs = CostCenter::whereIn('id', $ids)
            ->whereHas('subCostCenters')
            ->pluck('id');

        if ($costCentersWithSubs->isNotEmpty()) {
            return response()->json([
                'message' => 'Cannot delete cost centers with sub-cost centers',
                'cost_centers' => $costCentersWithSubs
            ], 422);
        }

        CostCenter::whereIn('id', $ids)->delete();
        Cache::forget("cost_centers_" . tenant('id'));

        return response()->json(['message' => 'Cost centers deleted successfully']);
    }

    public function exportExcel()
    {
        $costCenters = CostCenter::with('parentCostCenter')->get();

        if ($costCenters->isEmpty()) {
            return response()->json(['message' => 'No cost centers to export'], 404);
        }

        $export = new Export($costCenters, [
            'id' => 'ID',
            'code' => 'Code',
            'name' => 'Name',
            'parentCostCenter.code' => 'Parent Cost Center',
            'is_inactive' => 'Status'
        ]);

        return Excel::download($export, 'cost_centers.xlsx');
    }

    public function exportPdf()
    {
        $costCenters = CostCenter::with('parentCostCenter')->get();

        if ($costCenters->isEmpty()) {
            return response()->json(['message' => 'No cost centers to export'], 404);
        }

        $export = new ExportPDF($costCenters, [
            'id' => 'ID',
            'code' => 'Code',
            'name' => 'Name',
            'parentCostCenter.code' => 'Parent Cost Center',
            'is_inactive' => 'Status'
        ]);

        return $export->download('cost_centers.pdf');
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls,csv|max:2048'
        ]);

        try {
            $import = new DynamicExcelImport(CostCenter::class);
            Excel::import($import, $request->file('file'));
            Cache::forget("cost_centers_" . tenant('id'));
            return response()->json(['message' => 'Import successful']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Import failed: ' . $e->getMessage()], 422);
        }
    }

    public function getSubCostCenters($costCenterId)
    {
        $tenantId = tenant('id');
        $cacheKey = "cost_center_subs_{$costCenterId}_{$tenantId}";

        return Cache::remember($cacheKey, 3600, function () use ($costCenterId) {
            return CostCenter::where('sub_cost_center_of', $costCenterId)
                ->with('parentCostCenter')
                ->get();
        });
    }

    protected function validateNoCircularReference($parentId, $currentId = null)
    {
        if ($currentId && $parentId == $currentId) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'sub_cost_center_of' => ['A cost center cannot be a sub-cost center of itself']
            ]);
        }

        $parent = CostCenter::find($parentId);
        if ($parent && $parent->isSubCostCenter()) {
            $ancestors = $this->getAncestors($parent);
            if (in_array($currentId, $ancestors)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'sub_cost_center_of' => ['Circular reference detected in cost center hierarchy']
                ]);
            }
        }
    }

    protected function getAncestors($costCenter)
    {
        $ancestors = [];
        while ($costCenter->parentCostCenter) {
            $ancestors[] = $costCenter->parentCostCenter->id;
            $costCenter = $costCenter->parentCostCenter;
        }
        return $ancestors;
    }
}
