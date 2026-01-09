<?php

namespace App\Http\Controllers;

use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Http\Requests\UpdateServiceNeededItemRequest;
use App\Imports\DynamicExcelImport;
use App\Models\Service;
use App\Models\ServiceNeededItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;

class ServiceNeededItemController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ServiceNeededItem::query()->with(['service:id,name', 'item:id,name']);
        if ($request->filled('service_id')) {
            $query->where('service_id', $request->integer('service_id'));
        }
        if ($request->filled('item_id')) {
            $query->where('item_id', $request->integer('item_id'));
        }

        return response()->json($query->orderByDesc('id')->paginate());
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $request->all();

        // Bulk: array of items
        if (isset($payload[0]) && is_array($payload[0])) {
            $rules = [
                'service_id' => ['required', 'integer', 'exists:services,id'],
                'item_id' => ['required', 'integer', 'exists:items,id'],
                'description' => ['nullable', 'string'],
                'quantity' => ['nullable', 'numeric', 'min:0'],
            ];

            $created = [];
            $errors = [];

            DB::beginTransaction();

            try {
                foreach ($payload as $index => $row) {
                    $validator = Validator::make($row, $rules);
                    if ($validator->fails()) {
                        $errors[] = ['index' => $index, 'errors' => $validator->errors()];

                        continue;
                    }
                    $created[] = ServiceNeededItem::create($validator->validated())
                        ->load(['service:id,name', 'item:id,name']);
                }
                if (! empty($errors) && empty($created)) {
                    DB::rollBack();

                    return response()->json(['success' => false, 'errors' => $errors], 422);
                }
                DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();

                throw $e;
            }

            return response()->json([
                'success' => true,
                'created_count' => count($created),
                'errors' => $errors,
                'data' => $created,
            ], 201);
        }

        // Single
        $validator = Validator::make($payload, [
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'item_id' => ['required', 'integer', 'exists:items,id'],
            'description' => ['nullable', 'string'],
            'quantity' => ['nullable', 'numeric', 'min:0'],
        ]);
        $validator->validate();
        $item = ServiceNeededItem::create($validator->validated());

        return response()->json($item->load(['service:id,name', 'item:id,name']), 201);
    }

    public function show(ServiceNeededItem $serviceNeededItem): JsonResponse
    {
        return response()->json($serviceNeededItem->load(['service:id,name', 'item:id,name']));
    }

    public function update(UpdateServiceNeededItemRequest $request, ServiceNeededItem $serviceNeededItem): JsonResponse
    {
        $serviceNeededItem->update($request->validated());

        return response()->json($serviceNeededItem->load(['service:id,name', 'item:id,name']));
    }

    public function destroy(ServiceNeededItem $serviceNeededItem): JsonResponse
    {
        $serviceNeededItem->delete();

        return response()->json(['message' => 'Deleted']);
    }

    // Nested under services
    public function indexByService(Service $service): JsonResponse
    {
        $items = ServiceNeededItem::with('item:id,name')
            ->where('service_id', $service->id)
            ->orderByDesc('id')
            ->get();

        return response()->json($items);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', 'exists:service_needed_items,id'],
        ]);

        $skipped = [];
        $deleted = 0;

        foreach ($request->ids as $id) {
            try {
                $deleted += ServiceNeededItem::where('id', $id)->delete();
            } catch (\Illuminate\Database\QueryException $e) {
                $skipped[] = ['id' => $id, 'reason' => $e->getMessage()];
            }
        }

        return response()->json([
            'message' => 'Bulk delete completed.',
            'deleted_count' => $deleted,
            'skipped' => $skipped,
        ]);
    }

    public function exportExcell()
    {
        $query = ServiceNeededItem::query();
        $collection = $query->get();

        if ($collection->isEmpty()) {
            return response()->json(['message' => 'No service needed items found.'], 404);
        }

        $columns = [
            'id', 'service_id', 'item_id', 'description', 'quantity', 'created_at', 'updated_at',
        ];
        $headings = [
            'ID', 'Service ID', 'Item ID', 'Description', 'Quantity', 'Created At', 'Updated At',
        ];

        $fileName = 'service_needed_items_'.date('Y-m-d_H-i-s').'.xlsx';

        return Excel::download(new Export($query, $columns, $headings), $fileName);
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $rows = ServiceNeededItem::select('id', 'service_id', 'item_id', 'description', 'quantity')->get();
        if ($rows->isEmpty()) {
            return response()->json(['message' => 'No service needed items found.'], 404);
        }
        $title = 'Service Needed Items';
        $headers = [
            'id' => 'ID',
            'service_id' => 'Service ID',
            'item_id' => 'Item ID',
            'description' => 'Description',
            'quantity' => 'Quantity',
        ];
        $pdf = $pdfService->generatePdf($title, $headers, $rows->toArray());

        return $pdf->download('ServiceNeededItems.pdf');
    }

    public function importFromExcel(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv',
        ]);

        $import = new DynamicExcelImport(
            ServiceNeededItem::class,
            ['service_id', 'item_id', 'description', 'quantity'],
            function ($row) {
                $errors = [];
                if (empty($row['service_id'])) {
                    $errors[] = 'Missing service_id';
                }
                if (empty($row['item_id'])) {
                    $errors[] = 'Missing item_id';
                }
                if (isset($row['quantity']) && ! is_numeric($row['quantity'])) {
                    $errors[] = 'quantity must be numeric';
                }

                return $errors;
            },
            function ($row) {
                return [
                    'service_id' => (int) $row['service_id'],
                    'item_id' => (int) $row['item_id'],
                    'description' => isset($row['description']) ? (string) $row['description'] : null,
                    'quantity' => isset($row['quantity']) ? (float) $row['quantity'] : 0,
                ];
            }
        );

        Excel::import($import, $request->file('file'));

        return response()->json([
            'success' => true,
            'rows_imported' => $import->getImportedCount(),
            'rows_skipped_count' => $import->getSkippedCount(),
            'skipped_rows' => $import->getSkippedRows(),
        ]);
    }
}
