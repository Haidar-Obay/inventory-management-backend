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
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class CostCenterController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_cost_centers";

        $costCenters = app('cache')->store('database')->get($key);

        if (!$costCenters) {
            $costCenters = CostCenter::with('parent')->get();
            app('cache')->store('database')->forever($key, $costCenters);
        }

        return response()->json([
            'status' => true,
            'message' => 'Cost centers fetched successfully.',
            'data' => $costCenters,
        ]);
    }

    public function store(StoreCostCenterRequest $request)
    {
        $validated = $request->validated();

        // Check if the parent cost center is not itself a sub-cost center
        if (isset($validated['sub_cost_center_of']) && $validated['sub_cost_center_of']) {
            $parentCostCenter = CostCenter::find($validated['sub_cost_center_of']);
            if ($parentCostCenter && $parentCostCenter->sub_cost_center_of) {
                return response()->json([
                    'status' => false,
                    'message' => 'Cannot create sub-cost center under another sub-cost center. Only top-level cost centers can have sub-cost centers.',
                ], 422);
            }
        }

        $tenantId = tenant('id');
        $costCenter = CostCenter::create($validated);
        app('cache')->store('database')->forget("tenant_{$tenantId}_cost_centers");
        return response()->json([
            'status' => true,
            'message' => 'Cost center created successfully.',
            'data' => $costCenter,
        ], 201);
    }

    public function show($id)
    {
        try {
            $costCenter = CostCenter::findOrFail($id);
            return response()->json([
                'status' => true,
                'message' => 'Cost center fetched successfully.',
                'data' => $costCenter,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching cost center: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Cost center not found',
            ], 404);
        }
    }

    public function update(UpdateCostCenterRequest $request, CostCenter $costCenter)
    {
        $validated = $request->validated();

        // Check if the parent cost center is not itself a sub-cost center
        if (isset($validated['sub_cost_center_of']) && $validated['sub_cost_center_of']) {
            $parentCostCenter = CostCenter::find($validated['sub_cost_center_of']);
            if ($parentCostCenter && $parentCostCenter->sub_cost_center_of) {
                return response()->json([
                    'status' => false,
                    'message' => 'Cannot assign sub-cost center under another sub-cost center. Only top-level cost centers can have sub-cost centers.',
                ], 422);
            }
        }

        $tenantId = tenant('id');
        $costCenter->update($validated);
        app('cache')->store('database')->forget("tenant_{$tenantId}_cost_centers");
        return response()->json([
            'status' => true,
            'message' => 'Cost center updated successfully.',
            'data' => $costCenter,
        ]);
    }

    public function destroy(CostCenter $costCenter)
    {
        $tenantId = tenant('id');
        if ($costCenter->hasSubCostCenters()) {
            return response()->json([
                'status' => false,
                'message' => 'Cannot delete cost center with associated sub-cost centers',
            ], 422);
        }
        $costCenter->delete();
        app('cache')->store('database')->forget("tenant_{$tenantId}_cost_centers");
        return response()->json([
            'status' => true,
            'message' => 'Cost center deleted successfully.',
        ]);
    }

    public function bulkDelete(Request $request)
    {
        $ids = $request->input('ids');

        if (!$ids || !is_array($ids)) {
            return response()->json([
                'status' => false,
                'message' => 'No cost centers selected for deletion',
            ], 400);
        }

        try {
            foreach ($ids as $id) {
                $costCenter = CostCenter::findOrFail($id);
                if ($costCenter->hasSubCostCenters()) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Cannot delete cost center with sub-cost centers',
                    ], 422);
                }
                $costCenter->delete();
                Cache::forget("cost_centers_" . tenant('id'));
                Cache::forget("cost_center_{$costCenter->id}_" . tenant('id'));
            }

            return response()->json(null, 204);
        } catch (\Exception $e) {
            Log::error('Error in bulk delete: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to delete cost centers',
            ], 500);
        }
    }

    public function exportExcell()
    {
        $costCenters = CostCenter::query()
            ->leftJoin('cost_centers as parent', 'cost_centers.sub_cost_center_of', '=', 'parent.id')
            ->select([
                'cost_centers.id',
                'cost_centers.code',
                'cost_centers.name',
                'parent.code as parent_code',
                'cost_centers.active'
            ]);

        $collection = $costCenters->get();

        if ($collection->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No data to export',
            ]);
        }

        $columns = ['id', 'code', 'name', 'parent_code', 'active'];
        $headings = ['ID', 'Code', 'Name', 'Parent Cost Center', 'Status'];

        return Excel::download(new Export($costCenters, $columns, $headings), 'cost_centers.xlsx');
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $costCenters = CostCenter::query()
            ->leftJoin('cost_centers as parent', 'cost_centers.sub_cost_center_of', '=', 'parent.id')
            ->select([
                'cost_centers.id',
                'cost_centers.code',
                'cost_centers.name',
                'parent.code as parent_code',
                'cost_centers.active'
            ])
            ->get();

        if ($costCenters->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No data to export',
            ]);
        }

        $title = 'Cost Centers Report';
        $headers = [
            'id' => 'ID',
            'code' => 'Code',
            'name' => 'Name',
            'parent_code' => 'Parent Cost Center',
            'active' => 'Status'
        ];
        $data = $costCenters->toArray();

        $pdf = $pdfService->generatePdf($title, $headers, $data);
        return $pdf->download('Cost_Centers.pdf');
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
            return response()->json([
                'status' => true,
                'message' => 'Import successful',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Import failed: ' . $e->getMessage(),
            ], 422);
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

        try {
            $parent = CostCenter::find($parentId);
            if ($parent && $parent->isSubCostCenter()) {
                $ancestors = $this->getAncestors($parent);
                if (in_array($currentId, $ancestors)) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'sub_cost_center_of' => ['Circular reference detected in cost center hierarchy']
                    ]);
                }
            }
        } catch (\Exception $e) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'sub_cost_center_of' => ['An error occurred while validating the cost center hierarchy']
            ]);
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

    public function getNames()
    {
            $costCenters = CostCenter::whereNull('sub_cost_center_of')
                ->select('id', 'name')
                ->orderBy('name')
                ->get()
                ->map(function ($costCenter) {
                    return [
                        'id' => $costCenter->id,
                        'name' => $costCenter->name
                    ];
                });

        return response()->json([
            'status' => true,
            'message' => 'Cost center names fetched successfully.',
            'data' => $costCenters,
        ]);
    }
}
