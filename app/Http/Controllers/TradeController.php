<?php

namespace App\Http\Controllers;

use App\Models\Trade;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;

class TradeController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_trades";

        $trades = app('cache')->store('database')->get($key);

        if (!$trades) {
            $trades = Trade::orderBy('name')->get();
            app('cache')->store('database')->forever($key, $trades);
        }

        return response()->json([
            'status' => true,
            'message' => 'Trades fetched successfully.',
            'data' => $trades,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|max:255|unique:trades,code',
            'name' => 'required|string|max:255',
            'active' => 'boolean',
        ]);

        $trade = Trade::create($validated);

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_trades");

        return response()->json([
            'status' => true,
            'message' => 'Trade created successfully.',
            'data' => $trade,
        ], 201);
    }

    public function show(Trade $trade)
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_trade_{$trade->id}";

        $cachedTrade = app('cache')->store('database')->get($key);

        if (!$cachedTrade) {
            $cachedTrade = $trade;
            app('cache')->store('database')->forever($key, $cachedTrade);
        }

        return response()->json([
            'status' => true,
            'message' => 'Trade details fetched successfully.',
            'data' => $cachedTrade,
        ]);
    }

    public function update(Request $request, Trade $trade)
    {
        $validated = $request->validate([
            'code' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('trades', 'code')->ignore($trade->id),
            ],
            'name' => 'sometimes|string|max:255',
            'active' => 'boolean',
        ]);

        $trade->update($validated);

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_trades");
        app('cache')->store('database')->forget("tenant_{$tenantId}_trade_{$trade->id}");

        return response()->json([
            'status' => true,
            'message' => 'Trade updated successfully.',
            'data' => $trade,
        ]);
    }

    public function destroy(Trade $trade)
    {
        $trade->delete();

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_trades");
        app('cache')->store('database')->forget("tenant_{$tenantId}_trade_{$trade->id}");

        return response()->json([
            'status' => true,
            'message' => 'Trade deleted successfully.',
        ]);
    }

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:trades,id',
        ]);

        $tenantId = tenant('id');
        $skipped = [];
        $deleted = 0;

        foreach ($request->ids as $id) {
            try {
                $deleted += Trade::where('id', $id)->delete();
                app('cache')->store('database')->forget("tenant_{$tenantId}_trade_{$id}");
            } catch (\Illuminate\Database\QueryException $e) {
                $skipped[] = ['id' => $id, 'reason' => $e->getMessage()];
            }
        }

        app('cache')->store('database')->forget("tenant_{$tenantId}_trades");

        return response()->json([
            'message' => 'Bulk delete completed.',
            'deleted_count' => $deleted,
            'skipped' => $skipped,
        ]);
    }

    public function exportExcell()
    {
        $trades = Trade::query();
        $collection = $trades->get();

        if ($collection->isEmpty()) {
            return response()->json(['message' => 'No trades found.'], 404);
        }

        $columns = ['id', 'code', 'name', 'active', 'created_at', 'updated_at'];
        $headings = ['ID', 'Code', 'Name', 'Status', 'Created At', 'Updated At'];

        return Excel::download(new Export($trades, $columns, $headings), 'trades.xlsx');
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $trades = Trade::select('id', 'code', 'name', 'active')->get();

        if ($trades->isEmpty()) {
            return response()->json(['message' => 'No trades found.'], 404);
        }

        $title = 'Trade Report';
        $headers = ['id' => 'Trade ID', 'code' => 'Code', 'name' => 'Name', 'active' => 'Status', 'created_at' => 'Created At', 'updated_at' => 'Updated At'];
        $data = $trades->toArray();

        $pdf = $pdfService->generatePdf($title, $headers, $data);
        return $pdf->download('Trades.pdf');
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
            // Get model class from the import
            Trade::truncate();
        }

        // If type is 'mapping', use provided mapping, else use default
        $mapping = $request->input('mapping');

        $import = new DynamicExcelImport(
            Trade::class,
            ['code', 'name'],
            function ($row) use ($mapping) {
                foreach ($row as $k => $v) { if (is_string($v)) { $row[$k] = trim($v); } }
                $errors = [];

                $codeKey = $mapping ? array_search('code', $mapping) : 'code';
                $nameKey = $mapping ? array_search('name', $mapping) : 'name';

                if ((($row[$codeKey] ?? '') === '')) {
                    $errors[] = 'Missing code';
                }
                if ((($row[$nameKey] ?? '') === '')) {
                    $errors[] = 'Missing name';
                }
                return $errors;
            },
            function ($row) use ($mapping) {
                foreach ($row as $k => $v) { if (is_string($v)) { $row[$k] = trim($v); } }
                $codeKey = $mapping ? array_search('code', $mapping) : 'code';
                $nameKey = $mapping ? array_search('name', $mapping) : 'name';
                $activeKey = $mapping ? array_search('active', $mapping) : 'active';
                return [
                    'code' => $row[$codeKey] ?? null,
                    'name' => $row[$nameKey] ?? null,
                    'active' => boolval($row[$activeKey] ?? true),
                ];
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

        app('cache')->store('database')->forget('tenant_' . tenant('id') . '_trades');

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
            'header_validation' => $import->getHeaderValidationResult(),
        ]);
    }

    public function getNames()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_trade_names";

        $trades = app('cache')->store('database')->get($key);

        if (!$trades) {
            $trades = Trade::where('active', true)
                ->select('id', 'code', 'name', 'created_at', 'updated_at', 'created_at', 'updated_at')
                ->orderBy('name')
                ->get()
                ->map(function ($trade) {
                    return [
                        'id' => $trade->id,
                        'code' => $trade->code,
                        'name' => $trade->name,
                        'created_at' => $trade->created_at,
                        'updated_at' => $trade->updated_at,
                    ];
                });

            app('cache')->store('database')->forever($key, $trades);
        }

        return response()->json([
            'status' => true,
            'message' => 'Trade names fetched successfully.',
            'data' => $trades,
        ]);
    }
}
