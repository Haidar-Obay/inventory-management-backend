<?php

namespace App\Http\Controllers;

use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

class CategoryController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_categories";

        $categories = app('cache')->store('database')->get($key);

        if (! $categories) {
            $categories = Category::with('productLine')->get();
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
            'product_line_id' => 'nullable|exists:product_lines,id',
            'active' => 'boolean',
        ]);

        // Normalize product_line_id: convert empty string to null
        $data = $request->only(['code', 'name', 'product_line_id', 'active']);
        if (isset($data['product_line_id']) && $data['product_line_id'] === '') {
            $data['product_line_id'] = null;
        }

        $nextId = $this->computeNextAvailableId(Category::class, 'id');
        $category = new Category($data);
        $category->id = $nextId;
        $category->save();
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
        $category = Category::with('subcategories', 'productLine')->find($category->id);

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
            'product_line_id' => 'nullable|exists:product_lines,id',
            'active' => 'boolean',
        ]);

        // Normalize product_line_id: convert empty string to null
        $data = $request->only(['code', 'name', 'product_line_id', 'active']);
        if (isset($data['product_line_id']) && $data['product_line_id'] === '') {
            $data['product_line_id'] = null;
        }

        $category->update($data);
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
        // Prevent deletion if related subcategories exist; include helpful details
        if ($category->subcategories()->exists()) {
            $count = $category->subcategories()->count();
            $sampleIds = $category->subcategories()->limit(1)->pluck('id');

            $identifier = $category->name ?? $category->code ?? "ID: {$category->id}";

            return response()->json([
                'status' => false,
                'message' => "Cannot delete category \"{$identifier}\" (ID: {$category->id}). It is referenced by existing subcategories.",
                'details' => [
                    'subcategories' => [
                        'count' => $count,
                        'sample_ids' => $sampleIds,
                    ],
                ],
            ], 409);
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

        $ids = $request->input('ids');
        $skipped = [];
        $deleted = 0;

        foreach ($ids as $id) {
            try {
                $category = Category::find($id);

                if (! $category) {
                    $skipped[] = [
                        'id' => $id,
                        'reason' => 'Category not found.',
                    ];

                    continue;
                }

                // Check if category has subcategories and include details
                if ($category->subcategories()->exists()) {
                    $subcategoriesCount = $category->subcategories()->count();
                    $details = [
                        'subcategories' => [
                            'count' => $subcategoriesCount,
                            'sample_ids' => $category->subcategories()->limit(1)->pluck('id'),
                        ],
                    ];

                    $identifier = $category->name ?? $category->code ?? "ID: {$id}";
                    $skipped[] = [
                        'id' => $id,
                        'name' => $identifier,
                        'reason' => 'Cannot delete category. It is referenced by existing subcategories.',
                        'details' => $details,
                    ];

                    continue;
                }

                $category->delete();
                $deleted++;

            } catch (\Illuminate\Database\QueryException $e) {
                // Check if it's a foreign key constraint error and include details
                if ($e->getCode() == '23503') {
                    $details = [];

                    try {
                        $category = Category::find($id);
                        $subcategoriesCount = $category?->subcategories()->count() ?? 0;
                        if ($subcategoriesCount > 0) {
                            $details['subcategories'] = [
                                'count' => $subcategoriesCount,
                                'sample_ids' => $category->subcategories()->limit(1)->pluck('id'),
                            ];
                        }
                    } catch (\Throwable $ignored) {
                    }

                    $category = Category::find($id);
                    $identifier = $category?->name ?? $category?->code ?? "ID: {$id}";
                    $skipped[] = [
                        'id' => $id,
                        'name' => $identifier,
                        'reason' => 'Cannot delete category. It is referenced by existing subcategories.',
                        'details' => $details,
                    ];
                } else {
                    Log::error('Error deleting category '.$id.': '.$e->getMessage());
                    $category = Category::find($id);
                    $identifier = $category?->name ?? $category?->code ?? "ID: {$id}";
                    $skipped[] = [
                        'id' => $id,
                        'name' => $identifier,
                        'reason' => $e->getMessage(),
                    ];
                }
            } catch (\Exception $e) {
                Log::error('Error deleting category '.$id.': '.$e->getMessage());
                $category = Category::find($id);
                $identifier = $category?->name ?? $category?->code ?? "ID: {$id}";
                $skipped[] = [
                    'id' => $id,
                    'name' => $identifier,
                    'reason' => $e->getMessage(),
                ];
            }
        }

        // Invalidate cache after bulk delete
        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_categories");

        return response()->json([
            'message' => 'Bulk delete completed.',
            'deleted_count' => $deleted,
            'skipped' => $skipped,
        ]);
    }

    public function exportExcell()
    {
        $categories = Category::orderBy('name');
        $collection = $categories->get();

        if ($collection->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No categories to export',
            ], 404);
        }

        $columns = ['id', 'code', 'name', 'product_line_id', 'active', 'created_at', 'updated_at'];
        $headings = ['ID', 'Code', 'Name', 'Product Line', 'Active', 'Created At', 'Updated At'];

        $fileName = 'categories_'.date('Y-m-d_H-i-s').'.xlsx';

        return Excel::download(new Export($categories, $columns, $headings), $fileName);
    }

    public function exportPdf()
    {
        $categories = Category::with('productLine')
            ->select('id', 'code', 'name', 'product_line_id', 'active', 'created_at', 'updated_at')
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
            'product_line_id' => 'Product Line',
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
        $fields = $mapping ? array_values($mapping) : ['code', 'name', 'product_line_id', 'active'];

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

                    if (($row[$codeKey] ?? '') === '') {
                        $errors[] = 'Missing code';
                    }
                    if (($row[$nameKey] ?? '') === '') {
                        $errors[] = 'Missing name';
                    }

                    return $errors;
                },
                function ($row) use ($mapping) {
                    foreach ($row as $k => $v) {
                        if (is_string($v)) {
                            $row[$k] = trim($v);
                        }
                    }
                    $codeKey = $mapping ? array_search('code', $mapping) : 'code';
                    $nameKey = $mapping ? array_search('name', $mapping) : 'name';
                    $productLineKey = $mapping ? array_search('product_line_id', $mapping) : 'product_line_id';
                    $activeKey = $mapping ? array_search('active', $mapping) : 'active';

                    $productLineId = null;
                    if (! empty($row[$productLineKey])) {
                        $pl = \App\Models\ProductLine::whereRaw('LOWER(TRIM(code)) = ?', [mb_strtolower(trim($row[$productLineKey]))])->first();
                        if ($pl) {
                            $productLineId = $pl->id;
                        }
                    }

                    return [
                        'code' => $row[$codeKey] ?? null,
                        'name' => $row[$nameKey] ?? null,
                        'product_line_id' => $productLineId,
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
        $categories = Category::select('id', 'name', 'created_at', 'updated_at')
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
