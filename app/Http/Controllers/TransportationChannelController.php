<?php

namespace App\Http\Controllers;

use App\Models\TransportationChannel;
use App\Http\Requests\TransportationChannel\StoreTransportationChannelRequest;
use App\Http\Requests\TransportationChannel\UpdateTransportationChannelRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;
use Illuminate\Support\Facades\Log;

class TransportationChannelController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_transportation_channels";

        $transportationChannels = app('cache')->store('database')->get($key);

        if (!$transportationChannels) {
            $transportationChannels = TransportationChannel::with('parent')->get();
            app('cache')->store('database')->forever($key, $transportationChannels);
        }

        return response()->json([
            'status' => true,
            'message' => 'Transportation channels fetched successfully.',
            'data' => $transportationChannels,
        ]);
    }

    public function store(StoreTransportationChannelRequest $request)
    {
        $validated = $request->validated();

        // Check if the parent transportation channel is not itself a sub-transportation channel
        if (isset($validated['sub_transportation_of']) && $validated['sub_transportation_of']) {
            $parent = TransportationChannel::find($validated['sub_transportation_of']);
            if ($parent && $parent->sub_transportation_of) {
                return response()->json([
                    'status' => false,
                    'message' => 'Cannot create sub-transportation channel under another sub-transportation channel. Only top-level transportation channels can have sub-transportation channels.',
                ], 422);
            }
        }

        $tenantId = tenant('id');
        $transportationChannel = TransportationChannel::create($validated);
        app('cache')->store('database')->forget("tenant_{$tenantId}_transportation_channels");
        return response()->json([
            'status' => true,
            'message' => 'Transportation channel created successfully.',
            'data' => $transportationChannel,
        ], 201);
    }

    public function show($id)
    {
        try {
            $transportationChannel = TransportationChannel::findOrFail($id);
            return response()->json([
                'status' => true,
                'message' => 'Transportation channel fetched successfully.',
                'data' => $transportationChannel,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching transportation channel: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Transportation channel not found',
            ], 404);
        }
    }

    public function update(UpdateTransportationChannelRequest $request, TransportationChannel $transportationChannel)
    {
        $validated = $request->validated();

        // Check if the parent transportation channel is not itself a sub-transportation channel
        if (isset($validated['sub_transportation_of']) && $validated['sub_transportation_of']) {
            $parent = TransportationChannel::find($validated['sub_transportation_of']);
            if ($parent && $parent->sub_transportation_of) {
                return response()->json([
                    'status' => false,
                    'message' => 'Cannot assign sub-transportation channel under another sub-transportation channel. Only top-level transportation channels can have sub-transportation channels.',
                ], 422);
            }
        }

        $tenantId = tenant('id');
        $transportationChannel->update($validated);
        app('cache')->store('database')->forget("tenant_{$tenantId}_transportation_channels");
        return response()->json([
            'status' => true,
            'message' => 'Transportation channel updated successfully.',
            'data' => $transportationChannel,
        ]);
    }

    public function destroy(TransportationChannel $transportationChannel)
    {
        $tenantId = tenant('id');
        if ($transportationChannel->hasSubTransportationChannels()) {
            return response()->json([
                'status' => false,
                'message' => 'Cannot delete transportation channel with associated sub-transportation channels',
            ], 422);
        }
        $transportationChannel->delete();
        app('cache')->store('database')->forget("tenant_{$tenantId}_transportation_channels");
        return response()->json([
            'status' => true,
            'message' => 'Transportation channel deleted successfully.',
        ]);
    }

    public function bulkDelete(Request $request)
    {
        $tenantId = tenant('id');
        $ids = $request->input('ids');

        if (!$ids || !is_array($ids)) {
            return response()->json([
                'status' => false,
                'message' => 'No transportation channels selected for deletion',
            ], 400);
        }

        try {
            foreach ($ids as $id) {
                $transportationChannel = TransportationChannel::findOrFail($id);
                if ($transportationChannel->hasSubTransportationChannels()) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Cannot delete transportation channel with sub-transportation channels',
                    ], 422);
                }
                $transportationChannel->delete();
                Cache::forget("transportation_channels_" . tenant('id'));
                Cache::forget("transportation_channel_{$transportationChannel->id}_" . tenant('id'));
            }

            return response()->json(null, 204);
        } catch (\Exception $e) {
            Log::error('Error in bulk delete: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to delete transportation channels',
            ], 500);
        }
    }

    public function exportExcell()
    {
        $transportationChannels = TransportationChannel::query()
            ->leftJoin('transportation_channels as parent', 'transportation_channels.sub_transportation_of', '=', 'parent.id')
            ->select([
                'transportation_channels.id',
                'transportation_channels.code',
                'transportation_channels.name',
                'parent.code as parent_code',
            ]);

        $collection = $transportationChannels->get();

        if ($collection->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No data to export',
            ]);
        }

        $columns = ['id', 'code', 'name', 'parent_code'];
        $headings = ['ID', 'Code', 'Name', 'Parent Transportation Channel'];

        return Excel::download(new Export($transportationChannels, $columns, $headings), 'transportation_channels.xlsx');
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $transportationChannels = TransportationChannel::query()
            ->leftJoin('transportation_channels as parent', 'transportation_channels.sub_transportation_of', '=', 'parent.id')
            ->select([
                'transportation_channels.id',
                'transportation_channels.code',
                'transportation_channels.name',
                'parent.code as parent_code',
            ])
            ->get();

        if ($transportationChannels->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No data to export',
            ]);
        }

        $title = 'Transportation Channels Report';
        $headers = [
            'id' => 'ID',
            'code' => 'Code',
            'name' => 'Name',
            'parent_code' => 'Parent Transportation Channel',
        ];
        $data = $transportationChannels->toArray();

        $pdf = $pdfService->generatePdf($title, $headers, $data);
        return $pdf->download('TransportationChannels.pdf');
    }

    public function import(Request $request)
    {
        $tenantId = tenant('id');
        $request->validate([
            'file' => 'required|mimes:xlsx,xls,csv|max:2048'
        ]);

        try {
            $import = new DynamicExcelImport(TransportationChannel::class, ['code', 'name'], function ($row) {
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
                    'sub_transportation_of' => $row['sub_transportation_of'] ?? null,
                ];
            });
            Excel::import($import, $request->file('file'));
            app('cache')->store('database')->forget("tenant_{$tenantId}_transportation_channels");
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

    public function getSubTransportationChannels($transportationChannelId)
    {
        $tenantId = tenant('id');
        $cacheKey = "transportation_channel_subs_{$transportationChannelId}_{$tenantId}";

        return Cache::remember($cacheKey, 3600, function () use ($transportationChannelId) {
            return TransportationChannel::where('sub_transportation_of', $transportationChannelId)
                ->with('parent')
                ->get();
        });
    }

    public function getNames()
    {
        $transportationChannels = TransportationChannel::whereNull('sub_transportation_of')
            ->select('id', 'name')
            ->orderBy('name')
            ->get()
            ->map(function ($transportationChannel) {
                return [
                    'id' => $transportationChannel->id,
                    'name' => $transportationChannel->name
                ];
            });

        return response()->json([
            'status' => true,
            'message' => 'Transportation channel names fetched successfully.',
            'data' => $transportationChannels,
        ]);
    }
}
