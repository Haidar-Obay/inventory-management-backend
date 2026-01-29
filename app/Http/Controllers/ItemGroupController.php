<?php

namespace App\Http\Controllers;

use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Http\Requests\ItemGroup\StoreItemGroupRequest;
use App\Http\Requests\ItemGroup\UpdateItemGroupRequest;
use App\Imports\DynamicExcelImport;
use App\Models\ItemGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

class ItemGroupController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_item_groups";

        $itemGroups = app('cache')->store('database')->get($key);

        if (! $itemGroups) {
            $itemGroups = ItemGroup::all();

            app('cache')->store('database')->forever($key, $itemGroups);
        }

        return response()->json([
            'status' => true,
            'message' => 'Item groups fetched successfully.',
            'data' => $itemGroups,
        ]);
    }

    public function store(StoreItemGroupRequest $request)
    {
        $tenantId = tenant('id');
        $data = $request->validated();
        $nextId = $this->computeNextAvailableId(ItemGroup::class, 'id');
        $itemGroup = new ItemGroup($data);
        $itemGroup->id = $nextId;
        $itemGroup->save();
        app('cache')->store('database')->forget("tenant_{$tenantId}_item_groups");

        return response()->json([
            'status' => true,
            'message' => 'Item group created successfully.',
            'data' => $itemGroup,
        ], 201);
    }

    public function show(ItemGroup $itemGroup)
    {
        $itemGroup->load('items');

        return response()->json([
            'status' => true,
            'message' => 'Item group details fetched successfully.',
            'data' => $itemGroup,
        ]);
    }

    public function update(UpdateItemGroupRequest $request, ItemGroup $itemGroup)
    {
        $tenantId = tenant('id');
        $itemGroup->update($request->validated());
        app('cache')->store('database')->forget("tenant_{$tenantId}_item_groups");

        return response()->json([
            'status' => true,
            'message' => 'Item group updated successfully.',
            'data' => $itemGroup,
        ]);
    }

    public function destroy(ItemGroup $itemGroup)
    {
        if ($itemGroup->items()->exists()) {
            $count = $itemGroup->items()->count();
            $sampleIds = $itemGroup->items()->select('items.id')->limit(1)->pluck('id');

            $identifier = $itemGroup->name ?? $itemGroup->code ?? "ID: {$itemGroup->id}";

            return response()->json([
                'status' => false,
                'message' => "Cannot delete item group \"{$identifier}\" (ID: {$itemGroup->id}). It is referenced by existing items.",
                'details' => [
                    'items' => [
                        'count' => $count,
                        'sample_ids' => $sampleIds,
                    ],
                ],
            ], 409);
        }

        $tenantId = tenant('id');
        $itemGroup->delete();
        app('cache')->store('database')->forget("tenant_{$tenantId}_item_groups");

        return response()->json([
            'status' => true,
            'message' => 'Item group deleted successfully.',
        ]);
    }

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:item_groups,id',
        ]);

        $ids = $request->input('ids');
        $skipped = [];
        $deleted = 0;

        foreach ($ids as $id) {
            try {
                $itemGroup = ItemGroup::find($id);

                if (! $itemGroup) {
                    $skipped[] = [
                        'id' => $id,
                        'name' => "ID: {$id}",
                        'reason' => 'Item group not found.',
                    ];

                    continue;
                }

                if ($itemGroup->items()->exists()) {
                    $itemsCount = $itemGroup->items()->count();
                    $details = [
                        'items' => [
                            'count' => $itemsCount,
                            'sample_ids' => $itemGroup->items()->select('items.id')->limit(1)->pluck('id'),
                        ],
                    ];

                    $identifier = $itemGroup->name ?? $itemGroup->code ?? "ID: {$id}";
                    $skipped[] = [
                        'id' => $id,
                        'name' => $identifier,
                        'reason' => 'Cannot delete item group. It is referenced by existing items.',
                        'details' => $details,
                    ];

                    continue;
                }

                $itemGroup->delete();
                $deleted++;

            } catch (\Exception $e) {
                Log::error('Error deleting item group '.$id.': '.$e->getMessage());
                $itemGroup = ItemGroup::find($id);
                $identifier = $itemGroup?->name ?? $itemGroup?->code ?? "ID: {$id}";
                $skipped[] = [
                    'id' => $id,
                    'name' => $identifier,
                    'reason' => $e->getMessage(),
                ];
            }
        }

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_item_groups");

        return response()->json([
            'message' => 'Bulk delete completed.',
            'deleted_count' => $deleted,
            'skipped' => $skipped,
        ]);
    }

    public function exportExcel()
    {
        $itemGroups = ItemGroup::orderBy('name');
        $collection = $itemGroups->get();

        if ($collection->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No item groups to export',
            ], 404);
        }

        $columns = ['id', 'code', 'name', 'active', 'created_at', 'updated_at'];
        $headings = ['ID', 'Code', 'Name', 'Active', 'Created At', 'Updated At'];

        $fileName = 'item_groups'.'.xlsx';

        return Excel::download(new Export($itemGroups, $columns, $headings), $fileName);
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $itemGroups = ItemGroup::select('id', 'code', 'name', 'active', 'created_at', 'updated_at')->get();

        if ($itemGroups->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No item groups to export',
            ], 404);
        }

        $title = 'Item Groups Report';
        $headers = [
            'id' => 'ID',
            'code' => 'Code',
            'name' => 'Name',
            'active' => 'Active',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
        ];

        $data = $itemGroups->toArray();
        $pdf = $pdfService->generatePdf($title, $headers, $data);

        return $pdf->download('item_groups'.'.pdf');
    }

    public function importFromExcel(Request $request)
    {
        $tenantId = tenant('id');
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
            ItemGroup::truncate();
        }

        $mapping = $request->input('mapping');

        try {
            $import = new DynamicExcelImport(
                ItemGroup::class,
                ['code', 'name', 'active'],
                function ($row) {
                    $errors = [];

                    $code = isset($row['code']) ? trim((string) $row['code']) : '';
                    $name = isset($row['name']) ? trim((string) $row['name']) : '';

                    if ($code === '') {
                        $errors[] = 'Missing code';
                    } elseif (ItemGroup::where('code', $code)->exists()) {
                        $errors[] = "Item group code '{$code}' already exists";
                    }

                    if ($name === '') {
                        $errors[] = 'Missing name';
                    }

                    return $errors;
                },
                function ($row) {
                    $code = trim((string) ($row['code'] ?? ''));
                    $name = trim((string) ($row['name'] ?? ''));
                    $active = isset($row['active']) ? (bool) $row['active'] : false;

                    return [
                        'code' => $code,
                        'name' => $name,
                        'active' => $active,
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

            app('cache')->store('database')->forget("tenant_{$tenantId}_item_groups");

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
                'message' => 'Error importing item groups: '.$e->getMessage(),
            ], 500);
        }
    }

    public function getNames()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_item_group_names";

        $itemGroups = app('cache')->store('database')->get($key);

        if (! $itemGroups) {
            $itemGroups = ItemGroup::where('active', true)
                ->select('id', 'code', 'name', 'created_at', 'updated_at')
                ->orderBy('name')
                ->get()
                ->map(function ($itemGroup) {
                    return [
                        'id' => $itemGroup->id,
                        'code' => $itemGroup->code,
                        'name' => $itemGroup->name,
                        'created_at' => $itemGroup->created_at,
                        'updated_at' => $itemGroup->updated_at,
                    ];
                });

            app('cache')->store('database')->forever($key, $itemGroups);
        }

        return response()->json([
            'status' => true,
            'message' => 'Item group names fetched successfully.',
            'data' => $itemGroups,
        ]);
    }
}
