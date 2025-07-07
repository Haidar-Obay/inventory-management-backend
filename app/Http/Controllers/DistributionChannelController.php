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
            ]);

        $collection = $distributionChannels->get();

        if ($collection->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No data to export',
            ]);
        }

        $columns = ['id', 'code', 'name', 'parent_code'];
        $headings = ['ID', 'Code', 'Name', 'Parent Distribution Channel'];

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
        ];
        $data = $distributionChannels->toArray();

        $pdf = $pdfService->generatePdf($title, $headers, $data);
        return $pdf->download('DistributionChannels.pdf');
    }

    public function import(Request $request)
    {
        $tenantId = tenant('id');
        $request->validate([
            'file' => 'required|mimes:xlsx,xls,csv|max:2048'
        ]);

        try {
            $import = new DynamicExcelImport(DistributionChannel::class, ['code', 'name'], function ($row) {
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
                    'sub_distribution_of' => $row['sub_distribution_of'] ?? null,
                ];
            });
            Excel::import($import, $request->file('file'));
            app('cache')->store('database')->forget("tenant_{$tenantId}_distribution_channels");
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
            ->select('id', 'name')
            ->orderBy('name')
            ->get()
            ->map(function ($distributionChannel) {
                return [
                    'id' => $distributionChannel->id,
                    'name' => $distributionChannel->name
                ];
            });

        return response()->json([
            'status' => true,
            'message' => 'Distribution channel names fetched successfully.',
            'data' => $distributionChannels,
        ]);
    }
}
