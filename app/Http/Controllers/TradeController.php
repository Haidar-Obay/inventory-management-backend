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
            'is_inactive' => 'boolean',
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
            'is_inactive' => 'boolean',
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

        $columns = ['id', 'code', 'name', 'is_inactive'];
        $headings = ['ID', 'Code', 'Name', 'Is Inactive'];

        return Excel::download(new Export($trades, $columns, $headings), 'trades.xlsx');
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $trades = Trade::select('id', 'code', 'name', 'is_inactive')->get();

        if ($trades->isEmpty()) {
            return response()->json(['message' => 'No trades found.'], 404);
        }

        $title = 'Trade Report';
        $headers = [
            'id' => 'Trade ID',
            'code' => 'Code',
            'name' => 'Name',
            'is_inactive' => 'Status'
        ];
        $data = $trades->toArray();

        $pdf = $pdfService->generatePdf($title, $headers, $data);
        return $pdf->download('Trades.pdf');
    }

    public function importFromExcel(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv',
        ]);

        $import = new DynamicExcelImport(
            Trade::class,
            ['code', 'name'],
            function ($row) {
                $errors = [];

                if (empty($row['code'])) {
                    $errors[] = 'Missing code';
                }
                if (empty($row['name'])) {
                    $errors[] = 'Missing name';
                }

                return $errors;
            },
            function ($row) {
                return [
                    'code' => $row['code'],
                    'name' => $row['name'],
                    'is_inactive' => boolval($row['is_inactive'] ?? false),
                ];
            }
        );

        Excel::import($import, $request->file('file'));

        app('cache')->store('database')->forget('tenant_' . tenant('id') . '_trades');

        return response()->json([
            'success' => true,
            'rows_imported' => $import->getImportedCount(),
            'rows_skipped_count' => $import->getSkippedCount(),
            'skipped_rows' => $import->getSkippedRows(),
        ]);
    }
}
