<?php

namespace App\Http\Controllers;

use App\Models\SalesChannel;
use App\Http\Requests\SalesChannel\StoreSalesChannelRequest;
use App\Http\Requests\SalesChannel\UpdateSalesChannelRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;
use Illuminate\Support\Facades\Log;

class SalesChannelController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_sales_channels";

        $salesChannels = app('cache')->store('database')->get($key);

        if (!$salesChannels) {
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
            Log::error('Error fetching sales channel: ' . $e->getMessage());
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
        $tenantId = tenant('id');
        $ids = $request->input('ids');

        if (!$ids || !is_array($ids)) {
            return response()->json([
                'status' => false,
                'message' => 'No sales channels selected for deletion',
            ], 400);
        }

        try {
            foreach ($ids as $id) {
                $salesChannel = SalesChannel::findOrFail($id);
                if ($salesChannel->hasSubSalesChannels()) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Cannot delete sales channel with sub-sales channels',
                    ], 422);
                }
                $salesChannel->delete();
                Cache::forget("sales_channels_" . tenant('id'));
                Cache::forget("sales_channel_{$salesChannel->id}_" . tenant('id'));
            }

            return response()->json(null, 204);
        } catch (\Exception $e) {
            Log::error('Error in bulk delete: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to delete sales channels',
            ], 500);
        }
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

        $columns = ['id', 'code', 'name', 'parent_code'];
        $headings = ['ID', 'Code', 'Name', 'Parent Sales Channel'];

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
            ])
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

    public function import(Request $request)
    {
        $tenantId = tenant('id');
        $request->validate([
            'file' => 'required|mimes:xlsx,xls,csv|max:2048'
        ]);

        try {
            $import = new DynamicExcelImport(SalesChannel::class, ['code', 'name'], function ($row) {
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
                    'sub_sales_of' => $row['sub_sales_of'] ?? null,
                ];
            });
            Excel::import($import, $request->file('file'));
            app('cache')->store('database')->forget("tenant_{$tenantId}_sales_channels");
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
            ->select('id', 'name')
            ->orderBy('name')
            ->get()
            ->map(function ($salesChannel) {
                return [
                    'id' => $salesChannel->id,
                    'name' => $salesChannel->name
                ];
            });

        return response()->json([
            'status' => true,
            'message' => 'Sales channel names fetched successfully.',
            'data' => $salesChannels,
        ]);
    }
}
