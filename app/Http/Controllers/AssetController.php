<?php

namespace App\Http\Controllers;

use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Http\Requests\Asset\StoreAssetRequest;
use App\Http\Requests\Asset\UpdateAssetRequest;
use App\Imports\DynamicExcelImport;
use App\Models\Asset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;

class AssetController extends Controller
{
    public function index(Request $request)
    {
        $tenantId = tenant('id');

        // If a service_id is provided, return only active assets linked to that service (no global cache)
        if ($request->filled('service_id')) {
            $serviceId = $request->integer('service_id');

            $assets = Asset::active()
                ->with(['section:id,name,room_id', 'section.room:id,name,location'])
                ->whereHas('services', function ($q) use ($serviceId) {
                    $q->where('service_id', $serviceId);
                })
                ->orderBy('name')
                ->get();

            return response()->json([
                'status' => true,
                'message' => 'Assets for service fetched successfully.',
                'data' => $assets,
            ]);
        }

        // Default behaviour: cached list of all assets
        $key = "tenant_{$tenantId}_assets";

        $assets = app('cache')->store('database')->get($key);

        if (! $assets) {
            $assets = Asset::with(['section:id,name,room_id', 'section.room:id,name,location'])->orderBy('name')->get();

            app('cache')->store('database')->forever($key, $assets);
        }

        return response()->json([
            'status' => true,
            'message' => 'Assets fetched successfully.',
            'data' => $assets,
        ]);
    }

    public function store(StoreAssetRequest $request)
    {
        $validated = $request->validated();

        $nextId = $this->computeNextAvailableId(Asset::class, 'id');
        $asset = new Asset($validated);
        $asset->id = $nextId;
        $asset->save();

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_assets");

        return response()->json([
            'status' => true,
            'message' => 'Asset created successfully.',
            'data' => $asset->load(['section:id,name,room_id', 'section.room:id,name,location']),
        ], 201);
    }

    public function show(Asset $asset)
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_asset_{$asset->id}";

        $cachedAsset = app('cache')->store('database')->get($key);

        if (! $cachedAsset) {
            $cachedAsset = $asset->load([
                'section:id,name,room_id',
                'section.room:id,name,location',
                'appointments:id,specialist_id,start_at,end_at,status',
            ]);

            app('cache')->store('database')->forever($key, $cachedAsset);
        }

        return response()->json([
            'status' => true,
            'message' => 'Asset details fetched successfully.',
            'data' => $cachedAsset,
        ]);
    }

    public function update(UpdateAssetRequest $request, Asset $asset)
    {
        $validated = $request->validated();

        // Handle unique validation for name field within the same section
        if (isset($validated['name'])) {
            $validator = Validator::make(['name' => $validated['name']], [
                'name' => 'unique:assets,name,'.$asset->id.',id,section_id,'.$asset->section_id,
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }
        }

        $asset->update($validated);

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_assets");
        app('cache')->store('database')->forget("tenant_{$tenantId}_asset_{$asset->id}");

        return response()->json([
            'status' => true,
            'message' => 'Asset updated successfully.',
            'data' => $asset->load(['section:id,name,room_id', 'section.room:id,name,location']),
        ]);
    }

    public function destroy(Asset $asset)
    {
        $identifier = $asset->name ?? "ID: {$asset->id}";
        $details = [];

        // Check if asset has appointments through pivot table
        $appointmentsCount = DB::table('appointment_service')
            ->where('asset_id', $asset->id)
            ->whereNotNull('asset_id')
            ->distinct('appointment_id')
            ->count('appointment_id');
        
        if ($appointmentsCount > 0) {
            $sampleAppointmentId = DB::table('appointment_service')
                ->where('asset_id', $asset->id)
                ->whereNotNull('asset_id')
                ->select('appointment_id')
                ->first()?->appointment_id;
            
            $details['appointments'] = [
                'count' => $appointmentsCount,
                'sample_ids' => $sampleAppointmentId ? [$sampleAppointmentId] : [],
            ];
        }

        // Check if asset has services
        if ($asset->services()->exists()) {
            $servicesCount = $asset->services()->count();
            $details['services'] = [
                'count' => $servicesCount,
                'sample_ids' => $asset->services()->select('services.id')->limit(1)->pluck('id'),
            ];
        }

        if (! empty($details)) {
            return response()->json([
                'status' => false,
                'message' => "Cannot delete asset \"{$identifier}\" (ID: {$asset->id}). It is referenced by existing records.",
                'details' => $details,
            ], 409);
        }

        $asset->delete();

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_assets");
        app('cache')->store('database')->forget("tenant_{$tenantId}_asset_{$asset->id}");

        return response()->json([
            'status' => true,
            'message' => 'Asset deleted successfully.',
        ]);
    }

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:assets,id',
        ]);

        $tenantId = tenant('id');
        $skipped = [];
        $deleted = 0;

        foreach ($request->ids as $id) {
            try {
                $asset = Asset::find($id);

                if (! $asset) {
                    $skipped[] = [
                        'id' => $id,
                        'name' => "ID: {$id}",
                        'reason' => 'Asset not found.',
                    ];

                    continue;
                }

                $identifier = $asset->name ?? "ID: {$id}";
                $details = [];

                // Check if asset has appointments through pivot table
                $appointmentsCount = DB::table('appointment_service')
                    ->where('asset_id', $asset->id)
                    ->whereNotNull('asset_id')
                    ->distinct('appointment_id')
                    ->count('appointment_id');
                
                if ($appointmentsCount > 0) {
                    $sampleAppointmentId = DB::table('appointment_service')
                        ->where('asset_id', $asset->id)
                        ->whereNotNull('asset_id')
                        ->select('appointment_id')
                        ->first()?->appointment_id;
                    
                    $details['appointments'] = [
                        'count' => $appointmentsCount,
                        'sample_ids' => $sampleAppointmentId ? [$sampleAppointmentId] : [],
                    ];
                }

                // Check if asset has services
                if ($asset->services()->exists()) {
                    $servicesCount = $asset->services()->count();
                    $details['services'] = [
                        'count' => $servicesCount,
                        'sample_ids' => $asset->services()->select('services.id')->limit(1)->pluck('id'),
                    ];
                }

                if (! empty($details)) {
                    $skipped[] = [
                        'id' => $id,
                        'name' => $identifier,
                        'reason' => 'Cannot delete asset. It is referenced by existing records.',
                        'details' => $details,
                    ];

                    continue;
                }

                $deleted += $asset->delete();
                app('cache')->store('database')->forget("tenant_{$tenantId}_asset_{$id}");
            } catch (\Illuminate\Database\QueryException $e) {
                $asset = Asset::find($id);
                $identifier = $asset?->name ?? "ID: {$id}";
                $skipped[] = [
                    'id' => $id,
                    'name' => $identifier,
                    'reason' => $e->getMessage(),
                ];
            }
        }

        app('cache')->store('database')->forget("tenant_{$tenantId}_assets");

        return response()->json([
            'status' => true,
            'message' => 'Bulk delete completed.',
            'data' => [
                'deleted_count' => $deleted,
                'skipped' => $skipped,
            ],
        ]);
    }

    public function exportExcell()
    {
        $assets = Asset::with(['section:id,name,room_id', 'section.room:id,name'])->orderBy('name');
        $collection = $assets->get();

        if ($collection->isEmpty()) {
            return response()->json(['message' => 'No assets found.'], 404);
        }

        $columns = ['id', 'section_id', 'name', 'type', 'status', 'created_at', 'updated_at'];
        $headings = ['ID', 'Section ID', 'Name', 'Type', 'Status', 'Created At', 'Updated At'];

        return Excel::download(new Export($assets, $columns, $headings), 'assets.xlsx');
    }

    public function exportPdf()
    {
        $assets = Asset::with(['section:id,name,room_id', 'section.room:id,name'])
            ->select('id', 'name', 'type', 'status', 'section_id', 'created_at', 'updated_at')
            ->get();

        if ($assets->isEmpty()) {
            return response()->json(['message' => 'No assets found.'], 404);
        }

        $title = 'Asset Report';
        $headers = [
            'id' => 'Asset ID',
            'name' => 'Asset Name',
            'type' => 'Type',
            'status' => 'Status',
            'section_id' => 'Section ID',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
        ];
        $data = $assets->toArray();

        $pdfService = new ExportPDF;
        $pdf = $pdfService->generatePdf($title, $headers, $data);

        return $pdf->download('Assets.pdf');
    }

    public function importFromExcel(Request $request)
    {
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
            Asset::truncate();
        }

        // If type is 'mapping', use provided mapping, else use default
        $mapping = $request->input('mapping');
        $fields = $mapping ? array_values($mapping) : ['section_id', 'name', 'type', 'status'];

        $import = new DynamicExcelImport(
            Asset::class,
            $fields,
            function ($row) use ($mapping) {
                $errors = [];
                $sectionIdKey = $mapping ? array_search('section_id', $mapping) : 'section_id';
                $nameKey = $mapping ? array_search('name', $mapping) : 'name';
                $typeKey = $mapping ? array_search('type', $mapping) : 'type';

                if (empty($row[$sectionIdKey])) {
                    $errors[] = 'Missing section_id';
                }
                if (empty($row[$nameKey])) {
                    $errors[] = 'Missing name';
                }
                if (empty($row[$typeKey])) {
                    $errors[] = 'Missing type';
                }

                return $errors;
            },
            function ($row) use ($mapping) {
                $sectionIdKey = $mapping ? array_search('section_id', $mapping) : 'section_id';
                $nameKey = $mapping ? array_search('name', $mapping) : 'name';
                $typeKey = $mapping ? array_search('type', $mapping) : 'type';
                $statusKey = $mapping ? array_search('status', $mapping) : 'status';

                return [
                    'section_id' => $row[$sectionIdKey] ?? null,
                    'name' => $row[$nameKey] ?? null,
                    'type' => $row[$typeKey] ?? 'other',
                    'status' => $row[$statusKey] ?? 'active',
                ];
            },
            true // Enable header validation
        );

        Excel::import($import, $request->file('file'));

        // Check if headers were valid
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

        app('cache')->store('database')->forget('tenant_'.tenant('id').'_assets');

        return response()->json([
            'status' => true,
            'message' => 'Import completed successfully.',
            'data' => [
                'rows_imported' => $import->getImportedCount(),
                'rows_skipped_count' => $import->getSkippedCount(),
                'skipped_rows' => $import->getSkippedRows(),
            ],
        ]);
    }

    public function bySection($sectionId)
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_section_{$sectionId}_assets";

        $assets = app('cache')->store('database')->get($key);

        if (! $assets) {
            $assets = Asset::with(['section:id,name,room_id', 'section.room:id,name,location'])
                ->where('section_id', $sectionId)
                ->orderBy('name')
                ->get();

            app('cache')->store('database')->forever($key, $assets);
        }

        return response()->json([
            'status' => true,
            'message' => 'Assets for section fetched successfully.',
            'data' => $assets,
        ]);
    }

    public function available()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_available_assets";

        $assets = app('cache')->store('database')->get($key);

        if (! $assets) {
            $assets = Asset::available()
                ->with(['section:id,name,room_id', 'section.room:id,name,location'])
                ->orderBy('name')
                ->get();

            app('cache')->store('database')->forever($key, $assets);
        }

        return response()->json([
            'status' => true,
            'message' => 'Available assets fetched successfully.',
            'data' => $assets,
        ]);
    }
}
