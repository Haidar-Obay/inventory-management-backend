<?php

namespace App\Http\Controllers;

use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;
use App\Models\SubCategory;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class SubCategoryController extends Controller
{
    public function index()
    {
        $subCategories = SubCategory::with('category')->orderBy('category_id')->orderBy('name')->get();

        return response()->json([
            'status' => true,
            'message' => 'Sub categories fetched successfully.',
            'data' => $subCategories,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'category_id' => 'required|exists:categories,id',
        ]);

        if (SubCategory::where('category_id', $request->category_id)->where('name', $request->name)->exists()) {
            return response()->json(['message' => 'A subcategory with this name already exists for the selected category'], 422);
        }

        $nextId = $this->computeNextAvailableId(SubCategory::class, 'id');
        $subCategory = new SubCategory($request->only(['name', 'category_id']));
        $subCategory->id = $nextId;
        $subCategory->save();

        $subCategory->load('category');

        return response()->json([
            'status' => true,
            'message' => 'Sub category created successfully.',
            'data' => $subCategory,
        ]);
    }

    public function show(SubCategory $subCategory)
    {
        $subCategory->load('category');

        return response()->json([
            'status' => true,
            'message' => 'Sub category fetched successfully.',
            'data' => $subCategory,
        ]);
    }

    public function update(Request $request, SubCategory $subCategory)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'category_id' => 'required|exists:categories,id',
        ]);

        $exists = SubCategory::where('category_id', $request->category_id)
            ->where('name', $request->name)
            ->where('id', '!=', $subCategory->id)
            ->exists();
        if ($exists) {
            return response()->json(['message' => 'A subcategory with this name already exists for the selected category'], 422);
        }

        $subCategory->update($request->only(['name', 'category_id']));
        $subCategory->load('category');

        return response()->json([
            'status' => true,
            'message' => 'Sub category updated successfully.',
            'data' => $subCategory,
        ]);
    }

    public function destroy(SubCategory $subCategory)
    {
        $subCategory->delete();

        return response()->json([
            'status' => true,
            'message' => 'Sub category deleted successfully.',
        ]);
    }

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:sub_categories,id',
        ]);

        SubCategory::whereIn('id', $request->ids)->delete();

        return response()->json([
            'message' => 'Bulk delete completed.',
            'deleted_count' => count($request->ids),
        ]);
    }

    public function exportExcell()
    {
        $subCategories = SubCategory::with('category')->orderBy('category_id')->orderBy('name');

        if ($subCategories->get()->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No sub categories to export',
            ], 404);
        }

        $columns = ['id', 'name', 'category_id', 'created_at', 'updated_at'];
        $headings = ['ID', 'Name', 'Category ID', 'Created At', 'Updated At'];

        $fileName = 'sub_categories_'.date('Y-m-d_H-i-s').'.xlsx';

        return Excel::download(new Export($subCategories, $columns, $headings), $fileName);
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $subCategories = SubCategory::with('category')
            ->select('id', 'name', 'category_id', 'created_at', 'updated_at')
            ->orderBy('category_id')
            ->orderBy('name')
            ->get();

        if ($subCategories->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No sub categories to export',
            ], 404);
        }

        $title = 'Sub Categories Report';
        $headers = [
            'id' => 'ID',
            'name' => 'Name',
            'category_id' => 'Category ID',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
        ];

        $pdf = $pdfService->generatePdf($title, $headers, $subCategories->toArray());

        return $pdf->download('sub_categories_'.date('Y-m-d_H-i-s').'.pdf');
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
            SubCategory::truncate();
        }

        $mapping = $request->input('mapping');
        $fields = $mapping ? array_values($mapping) : ['name', 'category_id'];

        try {
            $import = new DynamicExcelImport(
                SubCategory::class,
                $fields,
                function ($row) use ($mapping) {
                    $nameKey = ($mapping && array_search('name', $mapping) !== false) ? array_search('name', $mapping) : 'name';
                    $categoryKey = ($mapping && array_search('category_id', $mapping) !== false) ? array_search('category_id', $mapping) : 'category_id';
                    $name = isset($row[$nameKey]) ? trim((string) $row[$nameKey]) : '';
                    $categoryId = isset($row[$categoryKey]) ? trim((string) $row[$categoryKey]) : '';

                    $errors = [];
                    if ($name === '') {
                        $errors[] = 'Missing name';
                    }
                    if ($categoryId === '' || ! \App\Models\Category::where('id', $categoryId)->exists()) {
                        $errors[] = 'Invalid or missing category_id';
                    }

                    return $errors;
                },
                function ($row) use ($mapping) {
                    $nameKey = ($mapping && array_search('name', $mapping) !== false) ? array_search('name', $mapping) : 'name';
                    $categoryKey = ($mapping && array_search('category_id', $mapping) !== false) ? array_search('category_id', $mapping) : 'category_id';
                    $name = trim((string) ($row[$nameKey] ?? ''));
                    $categoryId = (int) ($row[$categoryKey] ?? 0);

                    return [
                        'name' => $name,
                        'category_id' => $categoryId,
                    ];
                },
                true
            );
            Excel::import($import, $request->file('file'));

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
                'message' => 'Error importing sub categories: '.$e->getMessage(),
            ], 500);
        }
    }
}
