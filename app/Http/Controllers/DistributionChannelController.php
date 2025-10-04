<?php

namespace App\Http\Controllers;

use App\Models\DistributionChannel;
use App\Http\Requests\DistributionChannel\StoreDistributionChannelRequest;
use App\Http\Requests\DistributionChannel\UpdateDistributionChannelRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;
use Illuminate\Support\Facades\Log;

class DistributionChannelController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_distribution_channels";

        $distributionChannels = app('cache')->store('database')->get($key);

        if (!$distributionChannels) {
            $distributionChannels = DistributionChannel::with('parent')->get();
            app('cache')->store('database')->forever($key, $distributionChannels);
        }

        return response()->json([
            'status' => true,
            'message' => 'Distribution channels fetched successfully.',
            'data' => $distributionChannels,
        ]);
    }

    public function store(StoreDistributionChannelRequest $request)
    {
        $validated = $request->validated();

        // Check if the parent distribution channel is not itself a sub-distribution channel
        if (isset($validated['sub_distribution_of']) && $validated['sub_distribution_of']) {
            $parent = DistributionChannel::find($validated['sub_distribution_of']);
            if ($parent && $parent->sub_distribution_of) {
                return response()->json([
                    'status' => false,
                    'message' => 'Cannot create sub-distribution channel under another sub-distribution channel. Only top-level distribution channels can have sub-distribution channels.',
                ], 422);
            }
        }

        $tenantId = tenant('id');
        $distributionChannel = DistributionChannel::create($validated);
        app('cache')->store('database')->forget("tenant_{$tenantId}_distribution_channels");
        return response()->json([
            'status' => true,
            'message' => 'Distribution channel created successfully.',
            'data' => $distributionChannel,
        ], 201);
    }

    public function show($id)
    {
        try {
            $distributionChannel = DistributionChannel::findOrFail($id);
            return response()->json([
                'status' => true,
                'message' => 'Distribution channel fetched successfully.',
                'data' => $distributionChannel,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching distribution channel: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Distribution channel not found',
            ], 404);
        }
    }

    public function update(UpdateDistributionChannelRequest $request, DistributionChannel $distributionChannel)
    {
        $validated = $request->validated();

        // Check if the parent distribution channel is not itself a sub-distribution channel
        if (isset($validated['sub_distribution_of']) && $validated['sub_distribution_of']) {
            $parent = DistributionChannel::find($validated['sub_distribution_of']);
            if ($parent && $parent->sub_distribution_of) {
                return response()->json([
                    'status' => false,
                    'message' => 'Cannot assign sub-distribution channel under another sub-distribution channel. Only top-level distribution channels can have sub-distribution channels.',
                ], 422);
            }
        }

        $tenantId = tenant('id');
        $distributionChannel->update($validated);
        app('cache')->store('database')->forget("tenant_{$tenantId}_distribution_channels");
        return response()->json([
            'status' => true,
            'message' => 'Distribution channel updated successfully.',
            'data' => $distributionChannel,
        ]);
    }

    public function destroy(DistributionChannel $distributionChannel)
    {
        $tenantId = tenant('id');
        if ($distributionChannel->hasSubDistributionChannels()) {
            return response()->json([
                'status' => false,
                'message' => 'Cannot delete distribution channel with associated sub-distribution channels',
            ], 422);
        }
        $distributionChannel->delete();
        app('cache')->store('database')->forget("tenant_{$tenantId}_distribution_channels");
        return response()->json([
            'status' => true,
            'message' => 'Distribution channel deleted successfully.',
        ]);
    }

    public function bulkDelete(Request $request)
    {
        $tenantId = tenant('id');
        $ids = $request->input('ids');

        if (!$ids || !is_array($ids)) {
            return response()->json([
                'status' => false,
                'message' => 'No distribution channels selected for deletion',
            ], 400);
        }

        try {
            foreach ($ids as $id) {
                $distributionChannel = DistributionChannel::findOrFail($id);
                if ($distributionChannel->hasSubDistributionChannels()) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Cannot delete distribution channel with sub-distribution channels',
                    ], 422);
                }
                $distributionChannel->delete();
                Cache::forget("distribution_channels_" . tenant('id'));
                Cache::forget("distribution_channel_{$distributionChannel->id}_" . tenant('id'));
            }

            return response()->json(null, 204);
        } catch (\Exception $e) {
            Log::error('Error in bulk delete: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to delete distribution channels',
            ], 500);
        }
    }

    public function exportExcell()
    {
        $distributionChannels = DistributionChannel::query()
            ->leftJoin('distribution_channels as parent', 'distribution_channels.sub_distribution_of', '=', 'parent.id')
            ->select([
                'distribution_channels.id',
                'distribution_channels.code',
                'distribution_channels.name',
                'parent.code as parent_code',
                'distribution_channels.created_at',
                'distribution_channels.updated_at'
            ]);

        $collection = $distributionChannels->get();

        if ($collection->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No data to export',
            ]);
        }

        $columns = ['id', 'code', 'name', 'parent_code', 'created_at', 'updated_at'];
        $headings = ['ID', 'Code', 'Name', 'Parent Distribution Channel', 'Created At', 'Updated At'];

        return Excel::download(new Export($distributionChannels, $columns, $headings), 'distribution_channels.xlsx');
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $distributionChannels = DistributionChannel::query()
            ->leftJoin('distribution_channels as parent', 'distribution_channels.sub_distribution_of', '=', 'parent.id')
            ->select([
                'distribution_channels.id',
                'distribution_channels.code',
                'distribution_channels.name',
                'parent.code as parent_code',
                'distribution_channels.created_at',
                'distribution_channels.updated_at'
            ])
            ->get();

        if ($distributionChannels->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No data to export',
            ]);
        }

        $title = 'Distribution Channels Report';
        $headers = [
            'id' => 'ID',
            'code' => 'Code',
            'name' => 'Name',
            'parent_code' => 'Parent Distribution Channel',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
        ];
        $data = $distributionChannels->toArray();

        $pdf = $pdfService->generatePdf($title, $headers, $data);
        return $pdf->download('DistributionChannels.pdf');
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
            DistributionChannel::truncate();
        }

        // If type is 'mapping', use provided mapping, else use default
        $mapping = $request->input('mapping');

        try {
            $import = new DynamicExcelImport(
                DistributionChannel::class, 
                ['code', 'name'], 
                function ($row) use ($mapping) {
                    foreach ($row as $k => $v) { if (is_string($v)) { $row[$k] = trim($v); } }
                    $errors = [];
                    $codeKey = $mapping ? array_search('code', $mapping) : 'code';
                    $nameKey = $mapping ? array_search('name', $mapping) : 'name';
                    $subKey = $mapping ? array_search('sub_distribution_of', $mapping) : 'sub_distribution_of';
                    if ((($row[$codeKey] ?? '') === '')) {
                        $errors[] = 'Code is required';
                    }
                    if ((($row[$nameKey] ?? '') === '')) {
                        $errors[] = 'Name is required';
                    }
                    // Validate parent distribution channel code if provided
                    if (!empty($row[$subKey])) {
                        $parentChannel = DistributionChannel::whereRaw('LOWER(TRIM(code)) = ?', [mb_strtolower($row[$subKey])])->first();
                        if (!$parentChannel) {
                            $errors[] = "Parent distribution channel with code '{$row[$subKey]}' not found";
                        }
                    }
                    return $errors;
                }, 
                function ($row) use ($mapping) {
                    foreach ($row as $k => $v) { if (is_string($v)) { $row[$k] = trim($v); } }
                    $subDistributionOfId = null;
                    $codeKey = $mapping ? array_search('code', $mapping) : 'code';
                    $nameKey = $mapping ? array_search('name', $mapping) : 'name';
                    $subKey = $mapping ? array_search('sub_distribution_of', $mapping) : 'sub_distribution_of';
                    
                    // If sub_distribution_of is provided, resolve the code to ID
                    if (!empty($row[$subKey])) {
                        $parentChannel = DistributionChannel::whereRaw('LOWER(TRIM(code)) = ?', [mb_strtolower($row[$subKey])])->first();
                        if ($parentChannel) {
                            $subDistributionOfId = $parentChannel->id;
                        }
                    }
                    
                    return [
                        'code' => $row[$codeKey] ?? null,
                        'name' => $row[$nameKey] ?? null,
                        'sub_distribution_of' => $subDistributionOfId,
                    ];
                },
                $mapping ? false : true
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
            
            app('cache')->store('database')->forget("tenant_{$tenantId}_distribution_channels");

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
                'message' => 'Import failed: ' . $e->getMessage(),
            ], 422);
        }
    }

    

    public function getSubDistributionChannels($distributionChannelId)
    {
        $tenantId = tenant('id');
        $cacheKey = "distribution_channel_subs_{$distributionChannelId}_{$tenantId}";

        return Cache::remember($cacheKey, 3600, function () use ($distributionChannelId) {
            return DistributionChannel::where('sub_distribution_of', $distributionChannelId)
                ->with('parent')
                ->get();
        });
    }

    public function getNames()
    {
        $distributionChannels = DistributionChannel::whereNull('sub_distribution_of')
            ->select('id', 'name', 'created_at', 'updated_at')
            ->orderBy('name')
            ->get()
            ->map(function ($distributionChannel) {
                return [
                    'id' => $distributionChannel->id,
                    'name' => $distributionChannel->name,
                    'created_at' => $distributionChannel->created_at,
                    'updated_at' => $distributionChannel->updated_at,
                ];
            });

        return response()->json([
            'status' => true,
            'message' => 'Distribution channel names fetched successfully.',
            'data' => $distributionChannels,
        ]);
    }
}
