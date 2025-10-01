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
            $cached = $zone;
        }

        return response()->json([
            'status' => true,
            'message' => 'Zone details fetched successfully.',
            'data' => $cached,
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
        $columns = ['id', 'name', 'created_at', 'updated_at'];
        $headings = ['ID', 'Name', 'Created At', 'Updated At'];
        return Excel::download(new Export($Zone, $columns, $headings), 'zones.xlsx');
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $zones = Zone::select('id', 'name')->get();

        if ($zones->isEmpty()) {
            return response()->json(['message' => 'No zones found.'], 404);
        }

        $title = 'Zone Report';
        $headers = ['id' => 'Zone ID', 'name' => 'Zone Name', 'created_at' => 'Created At', 'updated_at' => 'Updated At'];
        $data = $zones->toArray();

        $pdf = $pdfService->generatePdf($title, $headers, $data);
        return $pdf->download('Zones.pdf');
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
            Zone::truncate();
        }

        // If type is 'mapping', use provided mapping, else use default
        $mapping = $request->input('mapping');
        $fields = $mapping ? array_values($mapping) : ['name'];

        $import = new DynamicExcelImport(
            Zone::class,
            $fields,
            function ($row) use ($mapping) {
                foreach ($row as $k => $v) { if (is_string($v)) { $row[$k] = trim($v); } }
                $errors = [];
                $nameKey = $mapping ? array_search('name', $mapping) : 'name';

                if (($row[$nameKey] ?? '') === '') {
                    $errors[] = 'Missing name';
                } elseif (preg_match('/[0-9]/', $row[$nameKey])) {
                    $errors[] = 'Zone name must not contain numbers';
                }

                return $errors;
            },
            function ($row) use ($mapping) {
                foreach ($row as $k => $v) { if (is_string($v)) { $row[$k] = trim($v); } }
                $nameKey = $mapping ? array_search('name', $mapping) : 'name';
                return ['name' => $row[$nameKey] ?? null];
            },
            $mapping ? false : true // Disable header validation when mapping provided
        );

        Excel::import($import, $request->file('file'));

        // Check if headers were valid
        if (!$import->areHeadersValid()) {
            $headerResult = $import->getHeaderValidationResult();
            return response()->json([
                'success' => false,
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

        app('cache')->store('database')->forget("tenant_" . tenant('id') . "_zones");

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
    }
}
