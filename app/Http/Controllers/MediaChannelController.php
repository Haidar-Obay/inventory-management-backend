<?php

namespace App\Http\Controllers;

use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Http\Requests\MediaChannel\StoreMediaChannelRequest;
use App\Http\Requests\MediaChannel\UpdateMediaChannelRequest;
use App\Imports\DynamicExcelImport;
use App\Models\MediaChannel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

class MediaChannelController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_media_channels";

        $mediaChannels = app('cache')->store('database')->get($key);

        if (! $mediaChannels) {
            $mediaChannels = MediaChannel::with('parent')->get();
            app('cache')->store('database')->forever($key, $mediaChannels);
        }

        return response()->json([
            'status' => true,
            'message' => 'Media channels fetched successfully.',
            'data' => $mediaChannels,
        ]);
    }

    public function store(StoreMediaChannelRequest $request)
    {
        $validated = $request->validated();

        // Check if the parent media channel is not itself a sub-media channel
        if (isset($validated['sub_media_of']) && $validated['sub_media_of']) {
            $parent = MediaChannel::find($validated['sub_media_of']);
            if ($parent && $parent->sub_media_of) {
                return response()->json([
                    'status' => false,
                    'message' => 'Cannot create sub-media channel under another sub-media channel. Only top-level media channels can have sub-media channels.',
                ], 422);
            }
        }

        $tenantId = tenant('id');
        $nextId = $this->computeNextAvailableId(MediaChannel::class, 'id');
        $mediaChannel = new MediaChannel($validated);
        $mediaChannel->id = $nextId;
        $mediaChannel->save();
        app('cache')->store('database')->forget("tenant_{$tenantId}_media_channels");

        return response()->json([
            'status' => true,
            'message' => 'Media channel created successfully.',
            'data' => $mediaChannel,
        ], 201);
    }

    public function show($id)
    {
        try {
            $mediaChannel = MediaChannel::findOrFail($id);

            return response()->json([
                'status' => true,
                'message' => 'Media channel fetched successfully.',
                'data' => $mediaChannel,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching media channel: '.$e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Media channel not found',
            ], 404);
        }
    }

    public function update(UpdateMediaChannelRequest $request, MediaChannel $mediaChannel)
    {
        $validated = $request->validated();

        // Check if the parent media channel is not itself a sub-media channel
        if (isset($validated['sub_media_of']) && $validated['sub_media_of']) {
            $parent = MediaChannel::find($validated['sub_media_of']);
            if ($parent && $parent->sub_media_of) {
                return response()->json([
                    'status' => false,
                    'message' => 'Cannot assign sub-media channel under another sub-media channel. Only top-level media channels can have sub-media channels.',
                ], 422);
            }
        }

        $tenantId = tenant('id');
        $mediaChannel->update($validated);
        app('cache')->store('database')->forget("tenant_{$tenantId}_media_channels");

        return response()->json([
            'status' => true,
            'message' => 'Media channel updated successfully.',
            'data' => $mediaChannel,
        ]);
    }

    public function destroy(MediaChannel $mediaChannel)
    {
        $tenantId = tenant('id');
        // Prevent deletion if related sub-media channels exist; include helpful details
        if ($mediaChannel->hasSubMediaChannels()) {
            $subMediaChannelsCount = $mediaChannel->children()->count();
            $subMediaChannelsSampleIds = $mediaChannel->children()->select('media_channels.id')->limit(1)->pluck('id');

            $identifier = $mediaChannel->name ?? $mediaChannel->code ?? "ID: {$mediaChannel->id}";

            return response()->json([
                'status' => false,
                'message' => "Cannot delete media channel \"{$identifier}\" (ID: {$mediaChannel->id}). It is referenced by existing sub-media channels.",
                'details' => [
                    'sub_media_channels' => [
                        'count' => $subMediaChannelsCount,
                        'sample_ids' => $subMediaChannelsSampleIds,
                    ],
                ],
            ], 409);
        }
        // Prevent deletion if related customers exist; include helpful details
        if ($mediaChannel->customers()->exists()) {
            $count = $mediaChannel->customers()->count();
            $sampleIds = $mediaChannel->customers()->select('customers.id')->limit(1)->pluck('id');
            $identifier = $mediaChannel->name ?? $mediaChannel->code ?? "ID: {$mediaChannel->id}";

            return response()->json([
                'status' => false,
                'message' => "Cannot delete media channel \"{$identifier}\" (ID: {$mediaChannel->id}). It is referenced by existing customers.",
                'details' => [
                    'customers' => [
                        'count' => $count,
                        'sample_ids' => $sampleIds,
                    ],
                ],
            ], 409);
        }
        $mediaChannel->delete();
        app('cache')->store('database')->forget("tenant_{$tenantId}_media_channels");

        return response()->json([
            'status' => true,
            'message' => 'Media channel deleted successfully.',
        ]);
    }

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:media_channels,id',
        ]);

        $ids = $request->input('ids');
        $skipped = [];
        $deleted = 0;

        foreach ($ids as $id) {
            try {
                $mediaChannel = MediaChannel::find($id);

                if (! $mediaChannel) {
                    $skipped[] = [
                        'id' => $id,
                        'name' => "ID: {$id}",
                        'reason' => 'Media channel not found.',
                    ];

                    continue;
                }

                // Check if media channel has sub-media channels and include details
                if ($mediaChannel->hasSubMediaChannels()) {
                    $subMediaChannelsCount = $mediaChannel->children()->count();
                    $details = [
                        'sub_media_channels' => [
                            'count' => $subMediaChannelsCount,
                            'sample_ids' => $mediaChannel->children()->select('media_channels.id')->limit(1)->pluck('id'),
                        ],
                    ];

                    $identifier = $mediaChannel->name ?? $mediaChannel->code ?? "ID: {$id}";
                    $skipped[] = [
                        'id' => $id,
                        'name' => $identifier,
                        'reason' => 'Cannot delete media channel. It is referenced by existing sub-media channels.',
                        'details' => $details,
                    ];

                    continue;
                }

                // Check if the media channel has any customers linked to it and include details
                if ($mediaChannel->customers()->exists()) {
                    $customersCount = $mediaChannel->customers()->count();
                    $details = [
                        'customers' => [
                            'count' => $customersCount,
                            'sample_ids' => $mediaChannel->customers()->select('customers.id')->limit(1)->pluck('id'),
                        ],
                    ];

                    $identifier = $mediaChannel->name ?? $mediaChannel->code ?? "ID: {$id}";
                    $skipped[] = [
                        'id' => $id,
                        'name' => $identifier,
                        'reason' => 'Cannot delete media channel. It is referenced by existing customers.',
                        'details' => $details,
                    ];

                    continue;
                }

                $mediaChannel->delete();
                app('cache')->store('database')->forget('media_channels_'.tenant('id'));
                app('cache')->store('database')->forget("media_channel_{$mediaChannel->id}_".tenant('id'));
                $deleted++;

            } catch (\Illuminate\Database\QueryException $e) {
                // Check if it's a foreign key constraint error and include details
                if ($e->getCode() == '23503') {
                    $details = [];

                    try {
                        $mediaChannel = MediaChannel::find($id);
                        $customersCount = $mediaChannel?->customers()->count() ?? 0;
                        $subMediaChannelsCount = $mediaChannel?->children()->count() ?? 0;
                        if ($customersCount > 0) {
                            $details['customers'] = [
                                'count' => $customersCount,
                                'sample_ids' => $mediaChannel->customers()->select('customers.id')->limit(1)->pluck('id'),
                            ];
                        }
                        if ($subMediaChannelsCount > 0) {
                            $details['sub_media_channels'] = [
                                'count' => $subMediaChannelsCount,
                                'sample_ids' => $mediaChannel->children()->select('media_channels.id')->limit(1)->pluck('id'),
                            ];
                        }
                    } catch (\Throwable $ignored) {
                    }

                    $mediaChannel = MediaChannel::find($id);
                    $identifier = $mediaChannel?->name ?? $mediaChannel?->code ?? "ID: {$id}";
                    $skipped[] = [
                        'id' => $id,
                        'name' => $identifier,
                        'reason' => 'Cannot delete media channel. It is referenced by existing customers or sub-media channels.',
                        'details' => $details,
                    ];
                } else {
                    Log::error('Error deleting media channel '.$id.': '.$e->getMessage());
                    $mediaChannel = MediaChannel::find($id);
                    $identifier = $mediaChannel?->name ?? $mediaChannel?->code ?? "ID: {$id}";
                    $skipped[] = [
                        'id' => $id,
                        'name' => $identifier,
                        'reason' => $e->getMessage(),
                    ];
                }
            } catch (\Exception $e) {
                Log::error('Error deleting media channel '.$id.': '.$e->getMessage());
                $mediaChannel = MediaChannel::find($id);
                $identifier = $mediaChannel?->name ?? $mediaChannel?->code ?? "ID: {$id}";
                $skipped[] = [
                    'id' => $id,
                    'name' => $identifier,
                    'reason' => $e->getMessage(),
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
        $mediaChannels = MediaChannel::query()
            ->leftJoin('media_channels as parent', 'media_channels.sub_media_of', '=', 'parent.id')
            ->select([
                'media_channels.id',
                'media_channels.code',
                'media_channels.name',
                'parent.code as parent_code',
                'media_channels.created_at',
                'media_channels.updated_at',
            ]);

        $collection = $mediaChannels->get();

        if ($collection->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No data to export',
            ]);
        }

        $columns = ['id', 'code', 'name', 'parent_code', 'created_at', 'updated_at'];
        $headings = ['ID', 'Code', 'Name', 'Parent Media Channel', 'Created At', 'Updated At'];

        return Excel::download(new Export($mediaChannels, $columns, $headings), 'media_channels.xlsx');
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $mediaChannels = MediaChannel::query()
            ->leftJoin('media_channels as parent', 'media_channels.sub_media_of', '=', 'parent.id')
            ->select([
                'media_channels.id',
                'media_channels.code',
                'media_channels.name',
                'parent.code as parent_code',
                'media_channels.created_at',
                'media_channels.updated_at',
            ])
            ->get();

        if ($mediaChannels->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No data to export',
            ]);
        }

        $title = 'Media Channels Report';
        $headers = [
            'id' => 'ID',
            'code' => 'Code',
            'name' => 'Name',
            'parent_code' => 'Parent Media Channel',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
        ];
        $data = $mediaChannels->toArray();

        $pdf = $pdfService->generatePdf($title, $headers, $data);

        return $pdf->download('MediaChannels.pdf');
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
            MediaChannel::truncate();
        }

        // If type is 'mapping', use provided mapping, else use default
        $mapping = $request->input('mapping');

        try {
            // Optionally clear existing data
            if ($request->boolean('clear_existing')) {
                MediaChannel::truncate();
            }

            // First pass: Import all media channels without parent relationships
            $import = new DynamicExcelImport(MediaChannel::class, ['code', 'name'], function ($row) use ($mapping) {
                // Normalize inputs
                foreach ($row as $k => $v) {
                    if (is_string($v)) {
                        $row[$k] = trim($v);
                    }
                }
                $errors = [];
                $codeKey = $mapping ? array_search('code', $mapping) : 'code';
                $nameKey = $mapping ? array_search('name', $mapping) : 'name';
                if ((($row[$codeKey] ?? '') === '')) {
                    $errors[] = 'Code is required';
                }
                if ((($row[$nameKey] ?? '') === '')) {
                    $errors[] = 'Name is required';
                }

                return $errors;
            }, function ($row) use ($mapping) {
                foreach ($row as $k => $v) {
                    if (is_string($v)) {
                        $row[$k] = trim($v);
                    }
                }
                $codeKey = $mapping ? array_search('code', $mapping) : 'code';
                $nameKey = $mapping ? array_search('name', $mapping) : 'name';

                return [
                    'code' => $row[$codeKey] ?? null,
                    'name' => $row[$nameKey] ?? null,
                    'sub_media_of' => null,
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

            // Second pass: Update parent relationships
            $this->updateParentRelationships($request->file('file'), $mapping);

            app('cache')->store('database')->forget("tenant_{$tenantId}_media_channels");

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

    private function updateParentRelationships($file, $mapping = null)
    {
        $data = Excel::toArray(new \stdClass, $file);
        $rows = $data[0] ?? [];
        $errors = [];

        // Skip header row
        array_shift($rows);

        foreach ($rows as $index => $row) {
            $codeKey = $mapping ? array_search('code', $mapping) : 'code';
            $parentKey = $mapping ? array_search('sub_media_of', $mapping) : 'sub_media_of';
            if (empty($row[$codeKey]) || empty($row[$parentKey])) {
                continue;
            }

            $mediaChannel = MediaChannel::where('code', $row[$codeKey])->first();
            $parentChannel = MediaChannel::where('code', $row[$parentKey])->first();

            if (! $mediaChannel) {
                $errors[] = 'Row '.($index + 2).": Media channel with code '{$row[$codeKey]}' not found";

                continue;
            }

            if (! $parentChannel) {
                $errors[] = 'Row '.($index + 2).": Parent media channel with code '{$row[$parentKey]}' not found";

                continue;
            }

            // Check if the parent media channel is not itself a sub-media channel
            if ($parentChannel->sub_media_of) {
                $errors[] = 'Row '.($index + 2).": Cannot create sub-media channel under another sub-media channel '{$row['sub_media_of']}'";

                continue;
            }

            $mediaChannel->update(['sub_media_of' => $parentChannel->id]);
        }

        if (! empty($errors)) {
            Log::warning('Media channel import parent relationship errors', ['errors' => $errors]);
        }
    }

    public function getSubMediaChannels($mediaChannelId)
    {
        $tenantId = tenant('id');
        $cacheKey = "media_channel_subs_{$mediaChannelId}_{$tenantId}";

        return Cache::remember($cacheKey, 3600, function () use ($mediaChannelId) {
            return MediaChannel::where('sub_media_of', $mediaChannelId)
                ->with('parent')
                ->get();
        });
    }

    public function getNames()
    {
        try {
            // Verify tenant context is initialized
            $tenantId = tenant('id');
            if (! $tenantId) {
                Log::error('MediaChannelController::getNames() - Tenant context not initialized');

                return response()->json([
                    'status' => false,
                    'message' => 'Tenant context not initialized',
                    'data' => [],
                ], 500);
            }

            $mediaChannels = MediaChannel::whereNull('sub_media_of')
                ->select('id', 'name', 'created_at', 'updated_at')
                ->orderBy('name')
                ->get()
                ->map(function ($mediaChannel) {
                    return [
                        'id' => $mediaChannel->id,
                        'name' => $mediaChannel->name,
                        'created_at' => $mediaChannel->created_at,
                        'updated_at' => $mediaChannel->updated_at,
                    ];
                });

            return response()->json([
                'status' => true,
                'message' => 'Media channel names fetched successfully.',
                'data' => $mediaChannels,
            ]);
        } catch (\Exception $e) {
            Log::error('MediaChannelController::getNames() failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'tenant_id' => tenant('id'),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch media channel names: '.$e->getMessage(),
                'data' => [],
            ], 500);
        }
    }
}
