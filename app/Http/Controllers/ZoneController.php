<?php

namespace App\Http\Controllers;

use App\Models\Zone;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;

class ZoneController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_zones";

        $zones = app('cache')->store('database')->get($key);

        if (!$zones) {
            $zones = Zone::withCount('addresses')
                ->orderBy('name')
                ->get();

            app('cache')->store('database')->forever($key, $zones);
        }

        return response()->json([
            'status' => true,
            'message' => 'Zones fetched successfully.',
            'data' => $zones,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:zones,name',
        ]);

        $zone = Zone::create($validated);

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_zones");

        return response()->json([
            'status' => true,
            'message' => 'Zone created successfully.',
            'data' => $zone,
        ], 201);
    }

    public function show(Zone $zone)
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_zone_show_{$zone->id}";

        $cached = app('cache')->store('database')->get($key);

        if (!$cached) {
            $zone->loadCount('addresses');
            app('cache')->store('database')->forever($key, $zone);
        } else {
        }

        return response()->json([
            'status' => true,
            'message' => 'Zone details fetched successfully.',
            'data' => $zone,
        ]);
    }

    public function update(Request $request, Zone $zone)
    {
        $validated = $request->validate([
            'name' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('zones', 'name')->ignore($zone->id)
            ],
        ]);

        $zone->update($validated);

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_zones");
        app('cache')->store('database')->forget("tenant_{$tenantId}_zone_show_{$zone->id}");

        return response()->json([
            'status' => true,
            'message' => 'Zone updated successfully.',
            'data' => $zone,
        ]);
    }

    public function destroy(Zone $zone)
    {
        $zone->delete();

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_zones");
        app('cache')->store('database')->forget("tenant_{$tenantId}_zone_show_{$zone->id}");

        return response()->json([
            'status' => true,
            'message' => 'Zone deleted successfully.',
        ]);
    }

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:zones,id',
        ]);

        $tenantId = tenant('id');
        $skipped = [];
        $deleted = 0;

        foreach ($request->ids as $id) {
            try {
                $deleted += Zone::where('id', $id)->delete();
                app('cache')->store('database')->forget("tenant_{$tenantId}_zone_{$id}");
            } catch (\Illuminate\Database\QueryException $e) {
                $skipped[] = ['id' => $id, 'reason' => $e->getMessage()];
            }
        }

        app('cache')->store('database')->forget("tenant_{$tenantId}_zones");

        return response()->json([
            'message' => 'Bulk delete completed.',
            'deleted_count' => $deleted,
            'skipped' => $skipped,
        ]);
    }

    public function exportExcell()
    {
        $Zone = Zone::query();
        $collection = $Zone->get();
        if ($collection->isEmpty()) {
            return response()->json(['message' => 'No Zone found.'], 404);
        }
        $columns = ['id', 'name'];
        $headings = ['ID', 'Name'];
        return Excel::download(new Export($Zone, $columns, $headings), 'zones.xlsx');
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $zones = Zone::select('id', 'name')->get();

        if ($zones->isEmpty()) {
            return response()->json(['message' => 'No zones found.'], 404);
        }

        $title = 'Zone Report';
        $headers = [
            'id' => 'Zone ID',
            'name' => 'Zone Name'
        ];
        $data = $zones->toArray();

        $pdf = $pdfService->generatePdf($title, $headers, $data);
        return $pdf->download('Zones.pdf');
    }

    public function importFromExcel(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv',
        ]);

        $import = new DynamicExcelImport(
            Zone::class,
            ['name'],
            function ($row) {
                $errors = [];

                if (empty($row['name'])) {
                    $errors[] = 'Missing name';
                } elseif (preg_match('/[0-9]/', $row['name'])) {
                    $errors[] = 'Zone name must not contain numbers';
                }

                return $errors;
            },
            function ($row) {
                return ['name' => $row['name']];
            }
        );

        Excel::import($import, $request->file('file'));

        app('cache')->store('database')->forget("tenant_" . tenant('id') . "_zones");

        return response()->json([
            'success' => true,
            'rows_imported' => $import->getImportedCount(),
            'rows_skipped_count' => $import->getSkippedCount(),
            'skipped_rows' => $import->getSkippedRows(),
        ]);
    }
}
