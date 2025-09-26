<?php

namespace App\Http\Controllers;

use App\Models\Item;
use Illuminate\Http\Request;
use App\Http\Requests\Item\StoreItemRequest;
use App\Http\Requests\Item\UpdateItemRequest;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;

class ItemController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_items";

        $items = app('cache')->store('database')->get($key);

        if (!$items) {
            $items = Item::orderBy('name')->get();
            app('cache')->store('database')->forever($key, $items);
        }

        return response()->json([
            'status' => true,
            'message' => 'Items fetched successfully.',
            'data' => $items,
        ]);
    }

    public function show(Item $item)
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_item_{$item->id}";

        $cachedItem = app('cache')->store('database')->get($key);

        if (!$cachedItem) {
            $cachedItem = $item;
            app('cache')->store('database')->forever($key, $cachedItem);
        }

        return response()->json([
            'status' => true,
            'message' => 'Item details fetched successfully.',
            'data' => $cachedItem,
        ]);
    }

    public function store(StoreItemRequest $request)
    {
        $item = Item::create($request->validated());

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_items");

        return response()->json([
            'status' => true,
            'message' => 'Item created successfully.',
            'data' => $item,
        ], 201);
    }

    public function update(UpdateItemRequest $request, Item $item)
    {
        $item->update($request->validated());

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_items");
        app('cache')->store('database')->forget("tenant_{$tenantId}_item_{$item->id}");

        return response()->json([
            'status' => true,
            'message' => 'Item updated successfully.',
            'data' => $item,
        ]);
    }

    public function destroy(Item $item)
    {
        $tenantId = tenant('id');
        $item->delete();
        app('cache')->store('database')->forget("tenant_{$tenantId}_items");
        app('cache')->store('database')->forget("tenant_{$tenantId}_item_{$item->id}");

        return response()->json([
            'status' => true,
            'message' => 'Item deleted successfully.',
        ]);
    }

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:items,id',
        ]);

        $tenantId = tenant('id');
        $skipped = [];
        $deleted = 0;

        foreach ($request->ids as $id) {
            try {
                $item = Item::find($id);
                $deleted += $item->delete();
                app('cache')->store('database')->forget("tenant_{$tenantId}_item_{$id}");
            } catch (\Illuminate\Database\QueryException $e) {
                $skipped[] = ['id' => $id, 'reason' => $e->getMessage()];
            }
        }

        app('cache')->store('database')->forget("tenant_{$tenantId}_items");

        return response()->json([
            'message' => 'Bulk delete completed.',
            'deleted_count' => $deleted,
            'skipped' => $skipped,
        ]);
    }

    public function exportExcell()
    {
        $items = Item::orderBy('name');
        $collection = $items->get();

        if ($collection->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No items to export',
            ], 404);
        }

        $columns = ['id', 'code', 'name', 'price'];
        $headings = ['ID', 'Code', 'Name', 'Price'];

        $fileName = 'items_' . date('Y-m-d_H-i-s') . '.xlsx';
        return Excel::download(new Export($items, $columns, $headings), $fileName);
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $items = Item::select('id', 'code', 'name', 'price')->get();

        if ($items->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No items to export',
            ], 404);
        }

        $title = 'Items Report';
        $headers = [
            'id' => 'ID',
            'code' => 'Code',
            'name' => 'Name',
            'price' => 'Price'
        ];
        $data = $items->toArray();

        $pdf = $pdfService->generatePdf($title, $headers, $data);
        return $pdf->download('Items.pdf');
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
            // Get model class from the import
            Item::truncate();
        }

        // If type is 'mapping', use provided mapping, else use default
        $mapping = $request->input('mapping');

        $import = new DynamicExcelImport(
            Item::class,
            ['code', 'name', 'price'],
            function ($row) {
                foreach ($row as $k => $v) { if (is_string($v)) { $row[$k] = trim($v); } }
                $errors = [];

                if (($row['code'] ?? '') === '') {
                    $errors[] = 'Missing code';
                }
                if (($row['name'] ?? '') === '') {
                    $errors[] = 'Missing name';
                }
                if (!isset($row['price']) || $row['price'] === '' || !is_numeric($row['price'])) {
                    $errors[] = 'Invalid price';
                }

                return $errors;
            },
            function ($row) {
                foreach ($row as $k => $v) { if (is_string($v)) { $row[$k] = trim($v); } }
                return [
                    'code' => $row['code'] ?? null,
                    'name' => $row['name'] ?? null,
                    'price' => isset($row['price']) ? floatval($row['price']) : null,
                ];
            },
            true // Enable header validation
        );

        Excel::import($import, $request->file('file'));

        // Check if headers were valid
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

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_items");

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
    }

    public function getNames()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_item_names";

        $items = app('cache')->store('database')->get($key);

        if (!$items) {
            $items = Item::select('id', 'code', 'name')
                ->orderBy('name')
                ->get()
                ->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'code' => $item->code,
                        'name' => $item->name
                    ];
                });

            app('cache')->store('database')->forever($key, $items);
        }

        return response()->json([
            'status' => true,
            'message' => 'Item names fetched successfully.',
            'data' => $items,
        ]);
    }
} 