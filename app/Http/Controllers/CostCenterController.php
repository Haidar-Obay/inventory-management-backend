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
                'cost_centers.active',
                'cost_centers.created_at',
                'cost_centers.updated_at'
            ]);

        $collection = $costCenters->get();

        if ($collection->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No data to export',
            ]);
        }

        $columns = ['id', 'code', 'name', 'parent_code', 'active', 'created_at', 'updated_at'];
        $headings = ['ID', 'Code', 'Name', 'Parent Cost Center', 'Status', 'Created At', 'Updated At'];

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
                'cost_centers.active',
                'created_at', 'updated_at'])
            ->get();

        if ($costCenters->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No data to export',
            ]);
        }

        $title = 'Cost Centers Report';
        $headers = ['id' => 'ID', 'code' => 'Code', 'name' => 'Name', 'parent_code' => 'Parent Cost Center', 'active' => 'Status', 'created_at' => 'Created At', 'updated_at' => 'Updated At'];
        $data = $costCenters->toArray();

        $pdf = $pdfService->generatePdf($title, $headers, $data);
        return $pdf->download('Cost_Centers.pdf');
    }

    public function importFromExcel(Request $request)
    {
        $tenantId = tenant('id');
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

        if ($request->input('type') === 'fresh') {
            CostCenter::truncate();
        }

        $mapping = $request->input('mapping');

        try {
            $import = new DynamicExcelImport(
                CostCenter::class,
                ['code', 'name'],
                function ($row) {
                    foreach ($row as $k => $v) { if (is_string($v)) { $row[$k] = trim($v); } }
                    $errors = [];
                    if (($row['code'] ?? '') === '') { $errors[] = 'Code is required'; }
                    if (($row['name'] ?? '') === '') { $errors[] = 'Name is required'; }
                    if (!empty($row['sub_cost_center_of'])) {
                        $parent = CostCenter::whereRaw('LOWER(TRIM(code)) = ?', [mb_strtolower($row['sub_cost_center_of'])])->first();
                        if (!$parent) {
                            $errors[] = "Parent cost center with code '{$row['sub_cost_center_of']}' not found";
                        }
                    }
                    return $errors;
                },
                function ($row) {
                    foreach ($row as $k => $v) { if (is_string($v)) { $row[$k] = trim($v); } }
                    $subCostCenterOfId = null;
                    if (!empty($row['sub_cost_center_of'])) {
                        $parent = CostCenter::whereRaw('LOWER(TRIM(code)) = ?', [mb_strtolower($row['sub_cost_center_of'])])->first();
                        if ($parent) {
                            $subCostCenterOfId = $parent->id;
                        }
                    }
                    return [
                        'code' => $row['code'] ?? null,
                        'name' => $row['name'] ?? null,
                        'sub_cost_center_of' => $subCostCenterOfId,
                    ];
                },
                true
            );

            Excel::import($import, $request->file('file'));

            if (!$import->areHeadersValid()) {
                $headerResult = $import->getHeaderValidationResult();
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid Excel file headers',
                    'header_validation' => $headerResult,
                    'errors' => [
                        'missing_headers' => $headerResult['missing'],
                        'extra_headers' => $headerResult['extra'],
                        'expected_headers' => $headerResult['expected_headers'],
                        'actual_headers' => $headerResult['excel_headers']
                    ]
                ], 422);
            }

            app('cache')->store('database')->forget("tenant_{$tenantId}_cost_centers");

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
                ->select('id', 'name', 'created_at', 'updated_at')
                ->orderBy('name')
                ->get()
                ->map(function ($costCenter) {
                    return [
                        'id' => $costCenter->id,
                        'name' => $costCenter->name,
                        'created_at' => $costCenter->created_at,
                        'updated_at' => $costCenter->updated_at,
                    ];
                });

        return response()->json([
            'status' => true,
            'message' => 'Cost center names fetched successfully.',
            'data' => $costCenters,
        ]);
    }
}
