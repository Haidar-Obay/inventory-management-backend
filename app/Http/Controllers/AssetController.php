<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Http\Requests\Asset\StoreAssetRequest;
use App\Http\Requests\Asset\UpdateAssetRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;

class AssetController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_assets";

        $assets = app('cache')->store('database')->get($key);

        if (!$assets) {
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
        
        $asset = Asset::create($validated);

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

        if (!$cachedAsset) {
            $cachedAsset = $asset->load([
                'section:id,name,room_id', 
                'section.room:id,name,location',
                'assignments:id,user_id,start_at,end_at,status'
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
                'name' => 'unique:assets,name,' . $asset->id . ',id,section_id,' . $asset->section_id,
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
                $deleted += Asset::where('id', $id)->delete();
                app('cache')->store('database')->forget("tenant_{$tenantId}_asset_{$id}");
            } catch (\Illuminate\Database\QueryException $e) {
                $skipped[] = ['id' => $id, 'reason' => $e->getMessage()];
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
            ->select('id', 'name', 'type', 'status', 'section_id')
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
        ];
        $data = $assets->toArray();

        $pdfService = new ExportPDF();
        $pdf = $pdfService->generatePdf($title, $headers, $data);
        return $pdf->download('Assets.pdf');
    }

    public function importFromExcel(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv',
        ]);

        $import = new DynamicExcelImport(
            Asset::class,
            ['section_id', 'name', 'type', 'status'],
            function ($row) {
                $errors = [];

                if (empty($row['section_id'])) {
                    $errors[] = 'Missing section_id';
                }
                if (empty($row['name'])) {
                    $errors[] = 'Missing name';
                }
                if (empty($row['type'])) {
                    $errors[] = 'Missing type';
                }

                return $errors;
            },
            function ($row) {
                return [
                    'section_id' => $row['section_id'],
                    'name' => $row['name'],
                    'type' => $row['type'] ?? 'other',
                    'status' => $row['status'] ?? 'active',
                ];
            }
        );

        Excel::import($import, $request->file('file'));

        app('cache')->store('database')->forget('tenant_' . tenant('id') . '_assets');

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

        if (!$assets) {
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

        if (!$assets) {
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
