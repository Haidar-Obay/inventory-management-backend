<?php

namespace App\Http\Controllers;

use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;
use App\Models\AdjustmentType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Facades\Excel;

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
        Cache::forget('adjustment_types_'.tenant('id'));

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
        $rules['code'] = 'required|string|max:50|unique:adjustment_types,code,'.$adjustmentType->id;

        $validated = $request->validate($rules);
        $adjustmentType->update($validated);

        Cache::forget('adjustment_types_'.tenant('id'));
        Cache::forget("adjustment_type_{$adjustmentType->id}_".tenant('id'));

        return response()->json($adjustmentType);
    }

    public function destroy(AdjustmentType $adjustmentType)
    {
        // Add any necessary checks before deletion
        // For example, check if the adjustment type is being used in any transactions

        $adjustmentType->delete();
        Cache::forget('adjustment_types_'.tenant('id'));
        Cache::forget("adjustment_type_{$adjustmentType->id}_".tenant('id'));

        return response()->json(null, 204);
    }

    public function bulkDelete(Request $request)
    {
        $ids = $request->input('ids');

        if (! $ids || ! is_array($ids)) {
            return response()->json(['message' => 'No adjustment types selected'], 400);
        }

        // Add any necessary checks before bulk deletion
        // For example, check if any of the adjustment types are being used in transactions

        AdjustmentType::whereIn('id', $ids)->delete();
        Cache::forget('adjustment_types_'.tenant('id'));

        return response()->json(['message' => 'Adjustment types deleted successfully']);
    }

    public function exportExcell()
    {
        $adjustmentTypes = AdjustmentType::all();

        if ($adjustmentTypes->isEmpty()) {
            return response()->json(['message' => 'No adjustment types to export'], 404);
        }

        $columns = ['id', 'code', 'name', 'active', 'created_at', 'updated_at'];
        $headings = ['ID', 'Code', 'Name', 'Active', 'Created At', 'Updated At'];

        $fileName = 'adjustment_types_'.'.xlsx';

        return Excel::download(new Export($adjustmentTypes, $columns, $headings), $fileName);
    }

    public function exportPdf()
    {
        $adjustmentTypes = AdjustmentType::all();

        if ($adjustmentTypes->isEmpty()) {
            return response()->json(['message' => 'No adjustment types to export'], 404);
        }

        $adjustmentTypes = AdjustmentType::select('id', 'code', 'name', 'active', 'created_at', 'updated_at')->get();

        $title = 'Adjustment Types Report';
        $headers = ['id' => 'ID', 'code' => 'Code', 'name' => 'Name', 'active' => 'Active', 'created_at' => 'Created At', 'updated_at' => 'Updated At', 'created_at' => 'Created At', 'updated_at' => 'Updated At'];

        $pdfService = new ExportPDF;
        $pdf = $pdfService->generatePdf($title, $headers, $adjustmentTypes->toArray());

        return $pdf->download('adjustment_types_'.'.pdf');
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

        if ($request->input('type') === 'fresh') {
            AdjustmentType::truncate();
        }

        $mapping = $request->input('mapping');
        $fields = $mapping ? array_values($mapping) : ['code', 'name', 'active'];

        try {
            $import = new DynamicExcelImport(
                AdjustmentType::class,
                $fields,
                function ($row) use ($mapping) {
                    $errors = [];
                    $codeKey = $mapping ? array_search('code', $mapping) : 'code';
                    $nameKey = $mapping ? array_search('name', $mapping) : 'name';
                    $activeKey = $mapping ? array_search('active', $mapping) : 'active';
                    if (empty($row[$codeKey])) {
                        $errors[] = 'Missing code';
                    }
                    if (empty($row[$nameKey])) {
                        $errors[] = 'Missing name';
                    }

                    return $errors;
                },
                function ($row) use ($mapping) {
                    $codeKey = $mapping ? array_search('code', $mapping) : 'code';
                    $nameKey = $mapping ? array_search('name', $mapping) : 'name';
                    $activeKey = $mapping ? array_search('active', $mapping) : 'active';

                    return [
                        'code' => $row[$codeKey] ?? null,
                        'name' => $row[$nameKey] ?? null,
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

            Cache::forget('adjustment_types_'.tenant('id'));

            return response()->json([
                'success' => true,
                'message' => 'Adjustment types imported successfully',
                'rows_imported' => $import->getImportedCount(),
                'rows_skipped_count' => $import->getSkippedCount(),
                'skipped_rows' => $import->getSkippedRows(),
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error importing adjustment types: '.$e->getMessage()], 500);
        }
    }

    public function getTransactionTypes()
    {
        return response()->json(AdjustmentType::getTransactionTypes());
    }
}
