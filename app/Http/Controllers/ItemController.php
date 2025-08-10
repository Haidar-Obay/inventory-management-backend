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
            'file' => 'required|file|mimes:xlsx,xls,csv',
        ]);

        $import = new DynamicExcelImport(
            Item::class,
            ['code', 'name', 'price'],
            function ($row) {
                $errors = [];

                if (empty($row['code'])) {
                    $errors[] = 'Missing code';
                }
                if (empty($row['name'])) {
                    $errors[] = 'Missing name';
                }
                if (empty($row['price']) || !is_numeric($row['price'])) {
                    $errors[] = 'Invalid price';
                }

                return $errors;
            },
            function ($row) {
                return [
                    'code' => $row['code'],
                    'name' => $row['name'],
                    'price' => floatval($row['price']),
                ];
            }
        );

        Excel::import($import, $request->file('file'));

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_items");

        return response()->json([
            'success' => true,
            'rows_imported' => $import->getImportedCount(),
            'rows_skipped_count' => $import->getSkippedCount(),
            'skipped_rows' => $import->getSkippedRows(),
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