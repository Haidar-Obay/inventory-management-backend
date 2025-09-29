<?php

namespace App\Http\Controllers;

use App\Models\MediaChannel;
use App\Http\Requests\MediaChannel\StoreMediaChannelRequest;
use App\Http\Requests\MediaChannel\UpdateMediaChannelRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;
use Illuminate\Support\Facades\Log;

class MediaChannelController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_media_channels";

        $mediaChannels = app('cache')->store('database')->get($key);

        if (!$mediaChannels) {
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
        $mediaChannel = MediaChannel::create($validated);
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
            Log::error('Error fetching media channel: ' . $e->getMessage());
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
        if ($mediaChannel->hasSubMediaChannels()) {
            return response()->json([
                'status' => false,
                'message' => 'Cannot delete media channel with associated sub-media channels',
            ], 422);
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
        $tenantId = tenant('id');
        $ids = $request->input('ids');

        if (!$ids || !is_array($ids)) {
            return response()->json([
                'status' => false,
                'message' => 'No media channels selected for deletion',
            ], 400);
        }

        try {
            foreach ($ids as $id) {
                $mediaChannel = MediaChannel::findOrFail($id);
                if ($mediaChannel->hasSubMediaChannels()) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Cannot delete media channel with sub-media channels',
                    ], 422);
                }
                $mediaChannel->delete();
                Cache::forget("media_channels_" . tenant('id'));
                Cache::forget("media_channel_{$mediaChannel->id}_" . tenant('id'));
            }

            return response()->json(null, 204);
        } catch (\Exception $e) {
            Log::error('Error in bulk delete: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to delete media channels',
            ], 500);
        }
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
                'media_channels.updated_at'
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
                'media_channels.updated_at'
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
            $import = new DynamicExcelImport(MediaChannel::class, ['code', 'name'], function ($row) {
                // Normalize inputs
                foreach ($row as $k => $v) { if (is_string($v)) { $row[$k] = trim($v); } }
                $errors = [];
                if (($row['code'] ?? '') === '') { $errors[] = 'Code is required'; }
                if (($row['name'] ?? '') === '') { $errors[] = 'Name is required'; }
                return $errors;
            }, function ($row) {
                foreach ($row as $k => $v) { if (is_string($v)) { $row[$k] = trim($v); } }
                return [
                    'code' => $row['code'] ?? null,
                    'name' => $row['name'] ?? null,
                    'sub_media_of' => null,
                ];
            }, true); // Enable header validation
            
            Excel::import($import, $request->file('file'));
            
            // Check if headers were valid
            if (!$import->areHeadersValid()) {
                $headerResult = $import->getHeaderValidationResult();
                return response()->json([
                    'status' => false,
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

            // Second pass: Update parent relationships
            $this->updateParentRelationships($request->file('file'));
            
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
                'message' => 'Import failed: ' . $e->getMessage(),
            ], 422);
        }
    }

    private function updateParentRelationships($file)
    {
        $data = Excel::toArray(new \stdClass(), $file);
        $rows = $data[0] ?? [];
        $errors = [];
        
        // Skip header row
        array_shift($rows);
        
        foreach ($rows as $index => $row) {
            if (empty($row['code']) || empty($row['sub_media_of'])) {
                continue;
            }
            
            $mediaChannel = MediaChannel::where('code', $row['code'])->first();
            $parentChannel = MediaChannel::where('code', $row['sub_media_of'])->first();
            
            if (!$mediaChannel) {
                $errors[] = "Row " . ($index + 2) . ": Media channel with code '{$row['code']}' not found";
                continue;
            }
            
            if (!$parentChannel) {
                $errors[] = "Row " . ($index + 2) . ": Parent media channel with code '{$row['sub_media_of']}' not found";
                continue;
            }
            
            // Check if the parent media channel is not itself a sub-media channel
            if ($parentChannel->sub_media_of) {
                $errors[] = "Row " . ($index + 2) . ": Cannot create sub-media channel under another sub-media channel '{$row['sub_media_of']}'";
                continue;
            }
            
            $mediaChannel->update(['sub_media_of' => $parentChannel->id]);
        }
        
        if (!empty($errors)) {
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
        $mediaChannels = MediaChannel::whereNull('sub_media_of')
            ->select('id', 'name', 'created_at', 'updated_at', 'created_at', 'updated_at')
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
    }
}
