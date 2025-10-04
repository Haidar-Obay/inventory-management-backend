<?php

namespace App\Http\Controllers;

use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;
use App\Models\Category;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class CategoryController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_categories";

        $categories = app('cache')->store('database')->get($key);

        if (! $categories) {
            $categories = Category::with('parentCategory')->get();
            app('cache')->store('database')->forever($key, $categories);
        }

        return response()->json([
            'status' => true,
            'message' => 'Categories fetched successfully.',
            'data' => $categories,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'code' => 'required|string|unique:categories,code',
            'name' => 'required|string',
            'subcategory_of' => 'nullable|exists:categories,id',
            'active' => 'boolean',
        ]);

        // Check if the parent category is not itself a subcategory
        if ($request->subcategory_of) {
            $parentCategory = Category::find($request->subcategory_of);
            if ($parentCategory->subcategory_of) {
                return response()->json([
                    'status' => false,
                    'message' => 'Cannot create subcategory under another subcategory. Only top-level categories can have subcategories.',
                ], 422);
            }
        }

        // Prevent circular references
        if ($request->subcategory_of) {
            $parentCategory = Category::find($request->subcategory_of);
            if ($parentCategory->subcategory_of === $request->subcategory_of) {
                return response()->json(['message' => 'Circular reference detected in category hierarchy'], 422);
            }
        }

        $category = Category::create($request->all());
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_categories";

        app('cache')->store('database')->forget($key);

        return response()->json([
            'status' => true,
            'message' => 'Category created successfully.',
            'data' => $category,
        ]);
    }

    public function show(Category $category)
    {
        $category = Category::with('parentCategory', 'subcategories')->find($category->id);

        return response()->json([
            'status' => true,
            'message' => 'Category fetched successfully.',
            'data' => $category,
        ]);
    }

    public function update(Request $request, Category $category)
    {
        $request->validate([
            'code' => 'required|string|unique:categories,code,'.$category->id,
            'name' => 'required|string',
            'subcategory_of' => 'nullable|exists:categories,id',
            'active' => 'boolean',
        ]);

        // Check if the parent category is not itself a subcategory
        if ($request->subcategory_of) {
            $parentCategory = Category::find($request->subcategory_of);
            if ($parentCategory->subcategory_of) {
                return response()->json([
                    'status' => false,
                    'message' => 'Cannot assign subcategory under another subcategory. Only top-level categories can have subcategories.',
                ], 422);
            }
        }

        // Prevent circular references
        if ($request->subcategory_of) {
            if ($request->subcategory_of === $category->id) {
                return response()->json(['message' => 'A category cannot be a subcategory of itself'], 422);
            }

            $parentCategory = Category::find($request->subcategory_of);
            if ($parentCategory->subcategory_of === $category->id) {
                return response()->json(['message' => 'Circular reference detected in category hierarchy'], 422);
            }
        }

        $category->update($request->all());
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_categories";

        app('cache')->store('database')->forget($key);

        return response()->json([
            'status' => true,
            'message' => 'Category updated successfully.',
            'data' => $category,
        ]);
    }

    public function destroy(Category $category)
    {
        // Check if category has subcategories
        if ($category->subcategories()->exists()) {
            return response()->json(['message' => 'Cannot delete category with subcategories'], 422);
        }

        $category->delete();
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_categories";

        app('cache')->store('database')->forget($key);

        return response()->json([
            'status' => true,
            'message' => 'Category deleted successfully.',
        ]);
    }

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:categories,id',
        ]);

        // Check for categories with subcategories
        $categoriesWithSubcategories = Category::whereIn('id', $request->ids)
            ->whereHas('subcategories')
            ->pluck('id');

        if ($categoriesWithSubcategories->isNotEmpty()) {
            return response()->json([
                'message' => 'Some categories have subcategories and cannot be deleted',
                'categories_with_subcategories' => $categoriesWithSubcategories,
            ], 422);
        }

        Category::whereIn('id', $request->ids)->delete();
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_categories";

        app('cache')->store('database')->forget($key);

        return response()->json([
            'status' => true,
            'message' => 'Categories deleted successfully.',
        ]);
    }

    public function exportExcell()
    {
        $categories = Category::with('parentCategory')->orderBy('name');
        $collection = $categories->get();

        if ($collection->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No categories to export',
            ], 404);
        }

        $columns = ['id', 'code', 'name', 'subcategory_of', 'active', 'created_at', 'updated_at'];
        $headings = ['ID', 'Code', 'Name', 'Subcategory Of', 'Active', 'Created At', 'Updated At'];

        $fileName = 'categories_'.date('Y-m-d_H-i-s').'.xlsx';

        return Excel::download(new Export($categories, $columns, $headings), $fileName);
    }

    public function exportPdf()
    {
        $categories = Category::with('parentCategory')
            ->select('id', 'code', 'name', 'subcategory_of', 'active', 'created_at', 'updated_at')
            ->get();

        if ($categories->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No categories to export',
            ], 404);
        }

        $title = 'Categories Report';
        $headers = [
            'id' => 'ID',
            'code' => 'Code',
            'name' => 'Name',
            'subcategory_of' => 'Subcategory Of',
            'active' => 'Active',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
        ];

        $pdfService = new ExportPDF;
        $pdf = $pdfService->generatePdf($title, $headers, $categories->toArray());

        return $pdf->download('categories_'.date('Y-m-d_H-i-s').'.pdf');
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
            Category::truncate();
        }

        // If type is 'mapping', use provided mapping, else use default
        $mapping = $request->input('mapping');
        $fields = $mapping ? array_values($mapping) : ['code', 'name', 'subcategory_of', 'active'];

        try {
            $import = new DynamicExcelImport(
                Category::class,
                $fields,
                function ($row) use ($mapping) {
                    foreach ($row as $k => $v) {
                        if (is_string($v)) {
                            $row[$k] = trim($v);
                        }
                    }
                    $errors = [];
                    $codeKey = $mapping ? array_search('code', $mapping) : 'code';
                    $nameKey = $mapping ? array_search('name', $mapping) : 'name';
                    $subcategoryKey = $mapping ? array_search('subcategory_of', $mapping) : 'subcategory_of';

                    if (($row[$codeKey] ?? '') === '') {
                        $errors[] = 'Missing code';
                    }
                    if (($row[$nameKey] ?? '') === '') {
                        $errors[] = 'Missing name';
                    }

                    // Validate parent category code if provided
                    if (! empty($row[$subcategoryKey])) {
                        $parentCategory = Category::whereRaw('LOWER(TRIM(code)) = ?', [mb_strtolower($row[$subcategoryKey])])->first();
                        if (! $parentCategory) {
                            $errors[] = "Parent category with code '{$row[$subcategoryKey]}' not found";
                        }
                    }

                    return $errors;
                },
                function ($row) use ($mapping) {
                    foreach ($row as $k => $v) {
                        if (is_string($v)) {
                            $row[$k] = trim($v);
                        }
                    }
                    $subcategoryOf = null;

                    $codeKey = $mapping ? array_search('code', $mapping) : 'code';
                    $nameKey = $mapping ? array_search('name', $mapping) : 'name';
                    $subcategoryKey = $mapping ? array_search('subcategory_of', $mapping) : 'subcategory_of';
                    $activeKey = $mapping ? array_search('active', $mapping) : 'active';

                    // If subcategory_of is provided and not empty, look up the parent category by code
                    if (! empty($row[$subcategoryKey])) {
                        $parentCategory = Category::whereRaw('LOWER(TRIM(code)) = ?', [mb_strtolower($row[$subcategoryKey])])->first();
                        if ($parentCategory) {
                            $subcategoryOf = $parentCategory->id;
                        }
                    }

                    return [
                        'code' => $row[$codeKey] ?? null,
                        'name' => $row[$nameKey] ?? null,
                        'subcategory_of' => $subcategoryOf,
                        'active' => boolval($row[$activeKey] ?? true),
                    ];
                },
                $mapping ? false : true // Disable header validation when mapping provided
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

            $tenantId = tenant('id');
            $key = "tenant_{$tenantId}_categories";

            app('cache')->store('database')->forget($key);

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
                'message' => 'Error importing categories: '.$e->getMessage(),
            ], 500);
        }
    }

    public function getNames()
    {
        // Only get top-level categories (not subcategories)
        $categories = Category::whereNull('subcategory_of')
            ->select('id', 'name', 'created_at', 'updated_at')
            ->orderBy('name')
            ->get()
            ->map(function ($category) {
                return [
                    'id' => $category->id,
                    'name' => $category->name,
                    'created_at' => $category->created_at,
                    'updated_at' => $category->updated_at,
                ];
            });

        return response()->json([
            'status' => true,
            'message' => 'Category names fetched successfully.',
            'data' => $categories,
        ]);
    }
}
