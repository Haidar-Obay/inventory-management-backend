<?php

namespace App\Http\Controllers;

use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Http\Requests\SalesChannel\StoreSalesChannelRequest;
use App\Http\Requests\SalesChannel\UpdateSalesChannelRequest;
use App\Imports\DynamicExcelImport;
use App\Models\SalesChannel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

class SalesChannelController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_sales_channels";

        $salesChannels = app('cache')->store('database')->get($key);

        if (! $salesChannels) {
            $salesChannels = SalesChannel::with('parent')->get();
            app('cache')->store('database')->forever($key, $salesChannels);
        }

        return response()->json([
            'status' => true,
            'message' => 'Sales channels fetched successfully.',
            'data' => $salesChannels,
        ]);
    }

    public function store(StoreSalesChannelRequest $request)
    {
        $validated = $request->validated();

        // Check if the parent sales channel is not itself a sub-sales channel
        if (isset($validated['sub_sales_of']) && $validated['sub_sales_of']) {
            $parent = SalesChannel::find($validated['sub_sales_of']);
            if ($parent && $parent->sub_sales_of) {
                return response()->json([
                    'status' => false,
                    'message' => 'Cannot create sub-sales channel under another sub-sales channel. Only top-level sales channels can have sub-sales channels.',
                ], 422);
            }
        }

        $tenantId = tenant('id');
        $salesChannel = SalesChannel::create($validated);
        app('cache')->store('database')->forget("tenant_{$tenantId}_sales_channels");

        return response()->json([
            'status' => true,
            'message' => 'Sales channel created successfully.',
            'data' => $salesChannel,
        ], 201);
    }

    public function show($id)
    {
        try {
            $salesChannel = SalesChannel::findOrFail($id);

            return response()->json([
                'status' => true,
                'message' => 'Sales channel fetched successfully.',
                'data' => $salesChannel,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching sales channel: '.$e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Sales channel not found',
            ], 404);
        }
    }

    public function update(UpdateSalesChannelRequest $request, SalesChannel $salesChannel)
    {
        $validated = $request->validated();

        // Check if the parent sales channel is not itself a sub-sales channel
        if (isset($validated['sub_sales_of']) && $validated['sub_sales_of']) {
            $parent = SalesChannel::find($validated['sub_sales_of']);
            if ($parent && $parent->sub_sales_of) {
                return response()->json([
                    'status' => false,
                    'message' => 'Cannot assign sub-sales channel under another sub-sales channel. Only top-level sales channels can have sub-sales channels.',
                ], 422);
            }
        }

        $tenantId = tenant('id');
        $salesChannel->update($validated);
        app('cache')->store('database')->forget("tenant_{$tenantId}_sales_channels");

        return response()->json([
            'status' => true,
            'message' => 'Sales channel updated successfully.',
            'data' => $salesChannel,
        ]);
    }

    public function destroy(SalesChannel $salesChannel)
    {
        $tenantId = tenant('id');
        if ($salesChannel->hasSubSalesChannels()) {
            return response()->json([
                'status' => false,
                'message' => 'Cannot delete sales channel with associated sub-sales channels',
            ], 422);
        }
        $salesChannel->delete();
        app('cache')->store('database')->forget("tenant_{$tenantId}_sales_channels");

        return response()->json([
            'status' => true,
            'message' => 'Sales channel deleted successfully.',
        ]);
    }

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:sales_channels,id',
        ]);

        $ids = $request->input('ids');
        $skipped = [];
        $deleted = 0;

        foreach ($ids as $id) {
            try {
                $salesChannel = SalesChannel::find($id);
                
                if (!$salesChannel) {
                    $skipped[] = [
                        'id' => $id,
                        'reason' => 'Sales channel not found.',
                    ];
                    continue;
                }

                // Check if sales channel has sub-sales channels
                if ($salesChannel->hasSubSalesChannels()) {
                    $skipped[] = [
                        'id' => $id,
                        'reason' => 'Cannot delete sales channel. It has sub-sales channels.',
                    ];
                    continue;
                }

                // Check if the sales channel has any customers linked to it
                if ($salesChannel->customers()->exists()) {
                    $skipped[] = [
                        'id' => $id,
                        'reason' => 'Cannot delete sales channel. It is being used by one or more customers.',
                    ];
                    continue;
                }

                $salesChannel->delete();
                app('cache')->store('database')->forget('sales_channels_'.tenant('id'));
                app('cache')->store('database')->forget("sales_channel_{$salesChannel->id}_".tenant('id'));
                $deleted++;
                
            } catch (\Illuminate\Database\QueryException $e) {
                // Check if it's a foreign key constraint error
                if ($e->getCode() == '23503') {
                    $skipped[] = [
                        'id' => $id,
                        'reason' => 'Cannot delete sales channel. It is being used by other records in the system.',
                    ];
                } else {
                    Log::error('Error deleting sales channel '.$id.': '.$e->getMessage());
                    $skipped[] = [
                        'id' => $id, 
                        'reason' => $e->getMessage()
                    ];
                }
            } catch (\Exception $e) {
                Log::error('Error deleting sales channel '.$id.': '.$e->getMessage());
                $skipped[] = [
                    'id' => $id, 
                    'reason' => $e->getMessage()
                ];
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
        $salesChannels = SalesChannel::query()
            ->leftJoin('sales_channels as parent', 'sales_channels.sub_sales_of', '=', 'parent.id')
            ->select([
                'sales_channels.id',
                'sales_channels.code',
                'sales_channels.name',
                'parent.code as parent_code',
            ]);

        $collection = $salesChannels->get();

        if ($collection->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No data to export',
            ]);
        }

        $columns = ['id', 'code', 'name', 'parent_code', 'created_at', 'updated_at'];
        $headings = ['ID', 'Code', 'Name', 'Parent Sales Channel', 'Created At', 'Updated At'];

        return Excel::download(new Export($salesChannels, $columns, $headings), 'sales_channels.xlsx');
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $salesChannels = SalesChannel::query()
            ->leftJoin('sales_channels as parent', 'sales_channels.sub_sales_of', '=', 'parent.id')
            ->select([
                'sales_channels.id',
                'sales_channels.code',
                'sales_channels.name',
                'parent.code as parent_code',
                'created_at', 'updated_at'])
            ->get();

        if ($salesChannels->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No data to export',
            ]);
        }

        $title = 'Sales Channels Report';
        $headers = [
            'id' => 'ID',
            'code' => 'Code',
            'name' => 'Name',
            'parent_code' => 'Parent Sales Channel',
        ];
        $data = $salesChannels->toArray();

        $pdf = $pdfService->generatePdf($title, $headers, $data);

        return $pdf->download('SalesChannels.pdf');
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

        // If type is 'fresh', delete all records first
        if ($request->input('type') === 'fresh') {
            // Get model class from the import
            SalesChannel::truncate();
        }

        // If type is 'mapping', use provided mapping, else use default
        $mapping = $request->input('mapping');

        try {
            $import = new DynamicExcelImport(SalesChannel::class, ['code', 'name'], function ($row) use ($mapping) {
                foreach ($row as $k => $v) {
                    if (is_string($v)) {
                        $row[$k] = trim($v);
                    }
                }
                $errors = [];
                $codeKey = $mapping ? array_search('code', $mapping) : 'code';
                $nameKey = $mapping ? array_search('name', $mapping) : 'name';
                $subKey = $mapping ? array_search('sub_sales_of', $mapping) : 'sub_sales_of';
                if ((($row[$codeKey] ?? '') === '')) {
                    $errors[] = 'Code is required';
                }
                if ((($row[$nameKey] ?? '') === '')) {
                    $errors[] = 'Name is required';
                }
                // Validate parent sales channel code if provided
                if (! empty($row[$subKey])) {
                    $parentChannel = SalesChannel::whereRaw('LOWER(TRIM(code)) = ?', [mb_strtolower($row[$subKey])])->first();
                    if (! $parentChannel) {
                        $errors[] = "Parent sales channel with code '{$row[$subKey]}' not found";
                    }
                }

                return $errors;
            }, function ($row) use ($mapping) {
                foreach ($row as $k => $v) {
                    if (is_string($v)) {
                        $row[$k] = trim($v);
                    }
                }
                $subSalesOfId = null;
                $codeKey = $mapping ? array_search('code', $mapping) : 'code';
                $nameKey = $mapping ? array_search('name', $mapping) : 'name';
                $subKey = $mapping ? array_search('sub_sales_of', $mapping) : 'sub_sales_of';

                // If sub_sales_of is provided, resolve the code to ID
                if (! empty($row[$subKey])) {
                    $parentChannel = SalesChannel::whereRaw('LOWER(TRIM(code)) = ?', [mb_strtolower($row[$subKey])])->first();
                    if ($parentChannel) {
                        $subSalesOfId = $parentChannel->id;
                    }
                }

                return [
                    'code' => $row[$codeKey] ?? null,
                    'name' => $row[$nameKey] ?? null,
                    'sub_sales_of' => $subSalesOfId,
                ];
            }, $mapping ? false : true);
            Excel::import($import, $request->file('file'));

            // Check if headers were valid
            if (! $import->areHeadersValid()) {
                $headerResult = $import->getHeaderValidationResult();

                return response()->json([
                    'status' => false,
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

            app('cache')->store('database')->forget("tenant_{$tenantId}_sales_channels");

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
                'message' => 'Import failed: '.$e->getMessage(),
            ], 422);
        }
    }

    public function getSubSalesChannels($salesChannelId)
    {
        $tenantId = tenant('id');
        $cacheKey = "sales_channel_subs_{$salesChannelId}_{$tenantId}";

        return Cache::remember($cacheKey, 3600, function () use ($salesChannelId) {
            return SalesChannel::where('sub_sales_of', $salesChannelId)
                ->with('parent')
                ->get();
        });
    }

    public function getNames()
    {
        $salesChannels = SalesChannel::whereNull('sub_sales_of')
            ->select('id', 'name', 'created_at', 'updated_at')
            ->orderBy('name')
            ->get()
            ->map(function ($salesChannel) {
                return [
                    'id' => $salesChannel->id,
                    'name' => $salesChannel->name,
                    'created_at' => $salesChannel->created_at,
                    'updated_at' => $salesChannel->updated_at,
                ];
            });

        return response()->json([
            'status' => true,
            'message' => 'Sales channel names fetched successfully.',
            'data' => $salesChannels,
        ]);
    }
}
