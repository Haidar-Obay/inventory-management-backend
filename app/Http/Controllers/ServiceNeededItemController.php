<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreServiceNeededItemRequest;
use App\Http\Requests\UpdateServiceNeededItemRequest;
use App\Models\Service;
use App\Models\ServiceNeededItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;

class ServiceNeededItemController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ServiceNeededItem::query()->with(['service:id,name', 'asset:id,name']);
        if ($request->filled('service_id')) {
            $query->where('service_id', $request->integer('service_id'));
        }
        if ($request->filled('asset_id')) {
            $query->where('asset_id', $request->integer('asset_id'));
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
                'asset_id' => ['required', 'integer', 'exists:assets,id'],
                'description' => ['nullable', 'string', 'max:255'],
                'unit' => ['nullable', 'string', 'max:50'],
                'qty' => ['required', 'numeric', 'min:0'],
                'notes_multiline' => ['nullable', 'string'],
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
                        ->load(['service:id,name', 'asset:id,name']);
                }
                if (!empty($errors) && empty($created)) {
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
            'asset_id' => ['required', 'integer', 'exists:assets,id'],
            'description' => ['nullable', 'string', 'max:255'],
            'unit' => ['nullable', 'string', 'max:50'],
            'qty' => ['required', 'numeric', 'min:0'],
            'notes_multiline' => ['nullable', 'string'],
        ]);
        $validator->validate();
        $item = ServiceNeededItem::create($validator->validated());
        return response()->json($item->load(['service:id,name', 'asset:id,name']), 201);
    }

    public function show(ServiceNeededItem $serviceNeededItem): JsonResponse
    {
        return response()->json($serviceNeededItem->load(['service:id,name', 'asset:id,name']));
    }

    public function update(UpdateServiceNeededItemRequest $request, ServiceNeededItem $serviceNeededItem): JsonResponse
    {
        $serviceNeededItem->update($request->validated());
        return response()->json($serviceNeededItem->load(['service:id,name', 'asset:id,name']));
    }

    public function destroy(ServiceNeededItem $serviceNeededItem): JsonResponse
    {
        $serviceNeededItem->delete();
        return response()->json(['message' => 'Deleted']);
    }

    // Nested under services
    public function indexByService(Service $service): JsonResponse
    {
        $items = ServiceNeededItem::with('asset:id,name')
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
            'id', 'service_id', 'asset_id', 'description', 'unit', 'qty', 'notes_multiline', 'created_at', 'updated_at'
        ];
        $headings = [
            'ID', 'Service ID', 'Asset ID', 'Description', 'Unit', 'Quantity', 'Notes', 'Created At', 'Updated At'
        ];

        $fileName = 'service_needed_items_' . date('Y-m-d_H-i-s') . '.xlsx';
        return Excel::download(new Export($query, $columns, $headings), $fileName);
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $rows = ServiceNeededItem::select('id', 'service_id', 'asset_id', 'description', 'unit', 'qty')->get();
        if ($rows->isEmpty()) {
            return response()->json(['message' => 'No service needed items found.'], 404);
        }
        $title = 'Service Needed Items';
        $headers = [
            'id' => 'ID',
            'service_id' => 'Service ID',
            'asset_id' => 'Asset ID',
            'description' => 'Description',
            'unit' => 'Unit',
            'qty' => 'Quantity',
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
            ['service_id', 'asset_id', 'description', 'unit', 'qty', 'notes_multiline'],
            function ($row) {
                $errors = [];
                if (empty($row['service_id'])) $errors[] = 'Missing service_id';
                if (empty($row['asset_id'])) $errors[] = 'Missing asset_id';
                if (isset($row['qty']) && !is_numeric($row['qty'])) $errors[] = 'qty must be numeric';
                return $errors;
            },
            function ($row) {
                return [
                    'service_id' => (int) $row['service_id'],
                    'asset_id' => (int) $row['asset_id'],
                    'description' => $row['description'] ?? null,
                    'unit' => $row['unit'] ?? null,
                    'qty' => isset($row['qty']) ? (float) $row['qty'] : 0,
                    'notes_multiline' => $row['notes_multiline'] ?? null,
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


