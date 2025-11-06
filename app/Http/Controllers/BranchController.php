<?php

namespace App\Http\Controllers;

use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Facades\Excel;

class BranchController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $cacheKey = "branches_{$tenantId}";

        return Cache::remember($cacheKey, 3600, function () {
            return Branch::all();
        });
    }

    public function store(Request $request)
    {
        $validated = $request->validate(Branch::$rules);
        $nextId = $this->computeNextAvailableId(Branch::class, 'id');
        $branch = new Branch($validated);
        $branch->id = $nextId;
        $branch->save();
        Cache::forget('branches_'.tenant('id'));

        return response()->json($branch, 201);
    }

    public function show(Branch $branch)
    {
        $tenantId = tenant('id');
        $cacheKey = "branch_{$branch->id}_{$tenantId}";

        return Cache::remember($cacheKey, 3600, function () use ($branch) {
            return $branch;
        });
    }

    public function update(Request $request, Branch $branch)
    {
        $rules = Branch::$rules;
        $rules['code'] = 'required|string|max:50|unique:branches,code,'.$branch->id;

        $validated = $request->validate($rules);
        $branch->update($validated);

        Cache::forget('branches_'.tenant('id'));
        Cache::forget("branch_{$branch->id}_".tenant('id'));

        return response()->json($branch);
    }

    public function destroy(Branch $branch)
    {
        // Add any necessary checks before deletion
        // For example, check if the branch is being used in other tables

        $branch->delete();
        Cache::forget('branches_'.tenant('id'));
        Cache::forget("branch_{$branch->id}_".tenant('id'));

        return response()->json(null, 204);
    }

    public function bulkDelete(Request $request)
    {
        $ids = $request->input('ids');

        if (! $ids || ! is_array($ids)) {
            return response()->json(['message' => 'No branches selected'], 400);
        }

        // Add any necessary checks before bulk deletion
        // For example, check if any of the branches are being used in other tables

        Branch::whereIn('id', $ids)->delete();
        Cache::forget('branches_'.tenant('id'));

        return response()->json(['message' => 'Branches deleted successfully']);
    }

    public function exportExcell()
    {
        $branches = Branch::all();

        if ($branches->isEmpty()) {
            return response()->json(['message' => 'No branches to export'], 404);
        }

        $columns = ['id', 'code', 'name', 'active', 'created_at', 'updated_at'];
        $headings = ['ID', 'Code', 'Name', 'Active', 'Created At', 'Updated At'];

        $fileName = 'branches'.'.xlsx';

        return Excel::download(new Export($branches, $columns, $headings), $fileName);
    }

    public function exportPdf()
    {
        $branches = Branch::all();

        if ($branches->isEmpty()) {
            return response()->json(['message' => 'No branches to export'], 404);
        }

        $branches = Branch::select('id', 'code', 'name', 'active', 'created_at', 'updated_at')->get();

        $title = 'Branches Report';
        $headers = ['id' => 'ID', 'code' => 'Code', 'name' => 'Name', 'active' => 'Active', 'created_at' => 'Created At', 'updated_at' => 'Updated At', 'created_at' => 'Created At', 'updated_at' => 'Updated At'];

        $pdfService = new ExportPDF;
        $pdf = $pdfService->generatePdf($title, $headers, $branches->toArray());

        return $pdf->download('branches'.'.pdf');
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
            Branch::truncate();
        }

        // If type is 'mapping', use provided mapping, else use default
        $mapping = $request->input('mapping');
        $fields = $mapping ? array_values($mapping) : ['code', 'name', 'active'];

        try {
            $import = new DynamicExcelImport(
                Branch::class,
                $fields,
                function ($row) use ($mapping) {
                    $errors = [];
                    $codeKey = $mapping ? array_search('code', $mapping) : 'code';
                    $nameKey = $mapping ? array_search('name', $mapping) : 'name';
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

            Cache::forget('branches_'.tenant('id'));

            return response()->json([
                'success' => true,
                'message' => 'Branches imported successfully',
                'rows_imported' => $import->getImportedCount(),
                'rows_skipped_count' => $import->getSkippedCount(),
                'skipped_rows' => $import->getSkippedRows(),
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error importing branches: '.$e->getMessage()], 500);
        }
    }
}
