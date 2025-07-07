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
            ]);

        $collection = $mediaChannels->get();

        if ($collection->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No data to export',
            ]);
        }

        $columns = ['id', 'code', 'name', 'parent_code'];
        $headings = ['ID', 'Code', 'Name', 'Parent Media Channel'];

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
        ];
        $data = $mediaChannels->toArray();

        $pdf = $pdfService->generatePdf($title, $headers, $data);
        return $pdf->download('MediaChannels.pdf');
    }

    public function import(Request $request)
    {
        $tenantId = tenant('id');
        $request->validate([
            'file' => 'required|mimes:xlsx,xls,csv|max:2048'
        ]);

        try {
            $import = new DynamicExcelImport(MediaChannel::class, ['code', 'name'], function ($row) {
                $errors = [];
                if (empty($row['code'])) {
                    $errors[] = 'Code is required';
                }
                if (empty($row['name'])) {
                    $errors[] = 'Name is required';
                }
                return $errors;
            }, function ($row) {
                return [
                    'code' => $row['code'],
                    'name' => $row['name'],
                    'sub_media_of' => $row['sub_media_of'] ?? null,
                ];
            });
            Excel::import($import, $request->file('file'));
            app('cache')->store('database')->forget("tenant_{$tenantId}_media_channels");
            return response()->json([
                'status' => true,
                'message' => 'Import successful',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Import failed: ' . $e->getMessage(),
            ], 422);
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
            ->select('id', 'name')
            ->orderBy('name')
            ->get()
            ->map(function ($mediaChannel) {
                return [
                    'id' => $mediaChannel->id,
                    'name' => $mediaChannel->name
                ];
            });

        return response()->json([
            'status' => true,
            'message' => 'Media channel names fetched successfully.',
            'data' => $mediaChannels,
        ]);
    }
}
