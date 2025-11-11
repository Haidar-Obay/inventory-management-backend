<?php

namespace App\Http\Controllers;

use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;
use App\Models\ProductLine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

class ProductLineController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_product_lines";

        $productLines = app('cache')->store('database')->get($key);

        if (! $productLines) {
            $productLines = ProductLine::get();
            app('cache')->store('database')->forever($key, $productLines);
        }

        return response()->json([
            'status' => true,
            'message' => 'Product lines fetched successfully.',
            'data' => $productLines,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'code' => 'required|string|unique:product_lines,code',
            'name' => 'required|string',
            'active' => 'boolean',
        ]);

        $data = $request->all();
        $nextId = $this->computeNextAvailableId(ProductLine::class, 'id');
        $productLine = new ProductLine($data);
        $productLine->id = $nextId;
        $productLine->save();

        // Invalidate cache after creating new product line
        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_product_lines");

        return response()->json([
            'status' => true,
            'message' => 'Product line created successfully.',
            'data' => $productLine,
        ]);
    }

    public function show(ProductLine $productLine)
    {
        return response()->json([
            'status' => true,
            'message' => 'Product line fetched successfully.',
            'data' => $productLine,
        ]);
    }

    public function update(Request $request, ProductLine $productLine)
    {
        $request->validate([
            'code' => 'required|string|unique:product_lines,code,'.$productLine->id,
            'name' => 'required|string',
            'active' => 'boolean',
        ]);

        $productLine->update($request->all());

        // Invalidate cache after updating product line
        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_product_lines");

        return response()->json([
            'status' => true,
            'message' => 'Product line updated successfully.',
            'data' => $productLine,
        ]);
    }

    public function destroy(ProductLine $productLine)
    {
        $identifier = $productLine->name ?? $productLine->code ?? "ID: {$productLine->id}";

        // Check if product line has items
        $itemsCount = \App\Models\Item::where('product_line_id', $productLine->id)->count();
        if ($itemsCount > 0) {
            $sampleIds = \App\Models\Item::where('product_line_id', $productLine->id)->select('items.id')->limit(1)->pluck('id');

            return response()->json([
                'status' => false,
                'message' => "Cannot delete product line \"{$identifier}\" (ID: {$productLine->id}). It is referenced by existing items.",
                'details' => [
                    'items' => [
                        'count' => $itemsCount,
                        'sample_ids' => $sampleIds,
                    ],
                ],
            ], 409);
        }

        $productLine->delete();

        // Invalidate cache after deleting product line
        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_product_lines");

        return response()->json([
            'status' => true,
            'message' => 'Product line deleted successfully.',
        ]);
    }

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:product_lines,id',
        ]);

        $ids = $request->input('ids');
        $skipped = [];
        $deleted = 0;

        foreach ($ids as $id) {
            try {
                $productLine = ProductLine::find($id);

                if (! $productLine) {
                    $skipped[] = [
                        'id' => $id,
                        'name' => "ID: {$id}",
                        'reason' => 'Product line not found.',
                    ];

                    continue;
                }

                $identifier = $productLine->name ?? $productLine->code ?? "ID: {$id}";

                // Check if product line has items
                $itemsCount = \App\Models\Item::where('product_line_id', $id)->count();
                if ($itemsCount > 0) {
                    $details = [
                        'items' => [
                            'count' => $itemsCount,
                            'sample_ids' => \App\Models\Item::where('product_line_id', $id)->select('items.id')->limit(1)->pluck('id'),
                        ],
                    ];

                    $skipped[] = [
                        'id' => $id,
                        'name' => $identifier,
                        'reason' => 'Cannot delete product line. It is referenced by existing items.',
                        'details' => $details,
                    ];

                    continue;
                }

                $productLine->delete();
                $deleted++;

            } catch (\Exception $e) {
                Log::error('Error deleting product line '.$id.': '.$e->getMessage());
                $productLine = ProductLine::find($id);
                $identifier = $productLine?->name ?? $productLine?->code ?? "ID: {$id}";
                $skipped[] = [
                    'id' => $id,
                    'name' => $identifier,
                    'reason' => $e->getMessage(),
                ];
            }
        }

        // Invalidate cache after bulk delete
        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_product_lines");

        return response()->json([
            'message' => 'Bulk delete completed.',
            'deleted_count' => $deleted,
            'skipped' => $skipped,
        ]);
    }

    public function exportExcel()
    {
        $productLines = ProductLine::orderBy('name');
        $collection = $productLines->get();

        if ($collection->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No product lines to export',
            ], 404);
        }

        $columns = ['id', 'code', 'name', 'active', 'created_at', 'updated_at'];
        $headings = ['ID', 'Code', 'Name', 'Active', 'Created At', 'Updated At'];

        $fileName = 'product_lines'.'.xlsx';

        return Excel::download(new Export($productLines, $columns, $headings), $fileName);
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $productLines = ProductLine::select('id', 'code', 'name', 'active', 'created_at', 'updated_at')->get();

        if ($productLines->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No product lines to export',
            ], 404);
        }

        $title = 'Product Lines Report';
        $headers = [
            'id' => 'ID',
            'code' => 'Code',
            'name' => 'Name',
            'active' => 'Active',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
        ];

        $data = $productLines->toArray();
        $pdf = $pdfService->generatePdf($title, $headers, $data);

        return $pdf->download('product_lines'.'.pdf');
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
            ProductLine::truncate();
        }

        // If type is 'mapping', use provided mapping, else use default
        $mapping = $request->input('mapping');
        $fields = $mapping ? array_values($mapping) : ['code', 'name', 'active'];

        try {
            $import = new DynamicExcelImport(
                ProductLine::class,
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
                    $activeKey = $mapping ? array_search('active', $mapping) : 'active';

                    return [
                        'code' => $row[$codeKey] ?? null,
                        'name' => $row[$nameKey] ?? null,
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

            // Invalidate cache after import
            $tenantId = tenant('id');
            app('cache')->store('database')->forget("tenant_{$tenantId}_product_lines");

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
                'message' => 'Error importing product lines: '.$e->getMessage(),
            ], 500);
        }
    }
}
