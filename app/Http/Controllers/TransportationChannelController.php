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
            TransportationChannel::truncate();
        }

        $mapping = $request->input('mapping');
        $fields = $mapping ? array_values($mapping) : ['code', 'name'];

        try {
            $import = new DynamicExcelImport(
                TransportationChannel::class,
                $fields,
                function ($row) use ($mapping) {
                    $errors = [];
                    $codeKey = $mapping ? array_search('code', $mapping) : 'code';
                    $nameKey = $mapping ? array_search('name', $mapping) : 'name';
                    $subKey = $mapping ? array_search('sub_transportation_of', $mapping) : 'sub_transportation_of';
                    if (empty($row[$codeKey])) $errors[] = 'Code is required';
                    if (empty($row[$nameKey])) $errors[] = 'Name is required';
                    if (!empty($row[$subKey])) {
                        $parentChannel = TransportationChannel::where('code', $row[$subKey])->first();
                        if (!$parentChannel) {
                            $errors[] = "Parent transportation channel with code '{$row[$subKey]}' not found";
                        }
                    }
                    return $errors;
                },
                function ($row) use ($mapping) {
                    $codeKey = $mapping ? array_search('code', $mapping) : 'code';
                    $nameKey = $mapping ? array_search('name', $mapping) : 'name';
                    $subKey = $mapping ? array_search('sub_transportation_of', $mapping) : 'sub_transportation_of';
                    $subTransportationOfId = null;
                    if (!empty($row[$subKey])) {
                        $parentChannel = TransportationChannel::where('code', $row[$subKey])->first();
                        if ($parentChannel) {
                            $subTransportationOfId = $parentChannel->id;
                        }
                    }
                    return [
                        'code' => $row[$codeKey] ?? null,
                        'name' => $row[$nameKey] ?? null,
                        'sub_transportation_of' => $subTransportationOfId,
                    ];
                },
                true // Enable header validation
            );
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
            app('cache')->store('database')->forget("tenant_{$tenantId}_transportation_channels");
            return response()->json([
                'status' => true,
                'message' => 'Import successful',
                'rows_imported' => $import->getImportedCount(),
                'rows_skipped_count' => $import->getSkippedCount(),
                'skipped_rows' => $import->getSkippedRows(),
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
