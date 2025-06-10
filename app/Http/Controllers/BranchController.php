<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;

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
        $branch = Branch::create($validated);
        Cache::forget("branches_" . tenant('id'));

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
        $rules['code'] = 'required|string|max:50|unique:branches,code,' . $branch->id;

        $validated = $request->validate($rules);
        $branch->update($validated);

        Cache::forget("branches_" . tenant('id'));
        Cache::forget("branch_{$branch->id}_" . tenant('id'));

        return response()->json($branch);
    }

    public function destroy(Branch $branch)
    {
        // Add any necessary checks before deletion
        // For example, check if the branch is being used in other tables

        $branch->delete();
        Cache::forget("branches_" . tenant('id'));
        Cache::forget("branch_{$branch->id}_" . tenant('id'));

        return response()->json(null, 204);
    }

    public function bulkDelete(Request $request)
    {
        $ids = $request->input('ids');

        if (!$ids || !is_array($ids)) {
            return response()->json(['message' => 'No branches selected'], 400);
        }

        // Add any necessary checks before bulk deletion
        // For example, check if any of the branches are being used in other tables

        Branch::whereIn('id', $ids)->delete();
        Cache::forget("branches_" . tenant('id'));

        return response()->json(['message' => 'Branches deleted successfully']);
    }

    public function exportExcell()
    {
        $branches = Branch::all();

        if ($branches->isEmpty()) {
            return response()->json(['message' => 'No branches to export'], 404);
        }

        $fileName = 'branches_' . date('Y-m-d_H-i-s') . '.xlsx';
        return Excel::download(new Export($branches), $fileName);
    }

    public function exportPdf()
    {
        $branches = Branch::all();

        if ($branches->isEmpty()) {
            return response()->json(['message' => 'No branches to export'], 404);
        }

        $fileName = 'branches_' . date('Y-m-d_H-i-s') . '.pdf';
        return Excel::download(new ExportPDF($branches), $fileName);
    }

    public function importFromExcel(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls',
        ]);

        try {
            $import = new DynamicExcelImport(new Branch());
            Excel::import($import, $request->file('file'));
            Cache::forget("branches_" . tenant('id'));

            return response()->json(['message' => 'Branches imported successfully']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error importing branches: ' . $e->getMessage()], 500);
        }
    }
}
