<?php

namespace App\Http\Controllers;

use App\Models\TransactionSeries;
use App\Http\Requests\TransactionSeries\StoreTransactionSeriesRequest;
use App\Http\Requests\TransactionSeries\UpdateTransactionSeriesRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;

class TransactionSeriesController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $cacheKey = "transaction_series_{$tenantId}";

        return Cache::remember($cacheKey, 3600, function () {
            return TransactionSeries::with(['companyCode', 'trade'])->get();
        });
    }

    public function store(StoreTransactionSeriesRequest $request)
    {
        $transactionSeries = TransactionSeries::create($request->validated());
        Cache::forget("transaction_series_" . tenant('id'));

        $transactionSeries->load(['companyCode', 'trade']);
        
        return response()->json([
            'status' => true,
            'message' => 'Transaction series created successfully.',
            'data' => $transactionSeries,
        ], 201);
    }

    public function show(TransactionSeries $transactionSeries)
    {
        $tenantId = tenant('id');
        $cacheKey = "transaction_series_{$transactionSeries->id}_{$tenantId}";

        $cached = Cache::remember($cacheKey, 3600, function () use ($transactionSeries) {
            $transactionSeries->load(['companyCode', 'trade']);
            return $transactionSeries;
        });
        
        return response()->json([
            'status' => true,
            'message' => 'Transaction series details fetched successfully.',
            'data' => $cached,
        ]);
    }

    public function update(UpdateTransactionSeriesRequest $request, TransactionSeries $transactionSeries)
    {
        $transactionSeries->update($request->validated());
        Cache::forget("transaction_series_" . tenant('id'));
        Cache::forget("transaction_series_{$transactionSeries->id}_" . tenant('id'));

        $transactionSeries->load(['companyCode', 'trade']);
        
        return response()->json([
            'status' => true,
            'message' => 'Transaction series updated successfully.',
            'data' => $transactionSeries,
        ]);
    }

    public function destroy(TransactionSeries $transactionSeries)
    {
        $transactionSeries->delete();
        Cache::forget("transaction_series_" . tenant('id'));
        Cache::forget("transaction_series_{$transactionSeries->id}_" . tenant('id'));

        return response()->json(null, 204);
    }

    public function bulkDelete(Request $request)
    {
        $ids = $request->input('ids');

        if (!$ids || !is_array($ids)) {
            return response()->json(['message' => 'No transaction series selected'], 400);
        }

        TransactionSeries::whereIn('id', $ids)->delete();
        Cache::forget("transaction_series_" . tenant('id'));

        return response()->json(['message' => 'Transaction series deleted successfully']);
    }

    public function exportExcel()
    {
        $transactionSeries = TransactionSeries::with(['companyCode', 'trade'])->get();

        if ($transactionSeries->isEmpty()) {
            return response()->json(['message' => 'No data to export'], 404);
        }

        $headers = ['id' => 'ID', 'code' => 'Code', 'name' => 'Name', 'template' => 'Template', 'companyCode.code' => 'Company Code', 'trade.code' => 'Trade', 'created_at' => 'Created At', 'updated_at' => 'Updated At'];

        $columns = array_keys($headers);
        $headings = array_values($headers);
        $query = TransactionSeries::with(['companyCode', 'trade']);

        $export = new Export($query, $columns, $headings);

        return Excel::download($export, 'transaction_series.xlsx');
    }

    public function exportPdf()
    {
        $transactionSeries = TransactionSeries::with(['companyCode', 'trade'])->get();

        if ($transactionSeries->isEmpty()) {
            return response()->json(['message' => 'No data to export'], 404);
        }

        $headers = ['id' => 'ID', 'code' => 'Code', 'name' => 'Name', 'template' => 'Template', 'companyCode.code' => 'Company Code', 'trade.code' => 'Trade', 'created_at' => 'Created At', 'updated_at' => 'Updated At'];

        $columns = array_keys($headers);
        $data = $transactionSeries->map(function ($row) use ($columns) {
            $mapped = [];
            foreach ($columns as $column) {
                $mapped[$column] = data_get($row, $column);
            }
            return $mapped;
        })->toArray();

        $pdf = (new ExportPDF())->generatePdf('Transaction Series', $headers, $data);
        return $pdf->download('transaction_series.pdf');
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
            TransactionSeries::truncate();
        }

        // If type is 'mapping', use provided mapping, else use default
        $mapping = $request->input('mapping');

        try {
            $import = new DynamicExcelImport(
                TransactionSeries::class,
                ['code', 'name', 'company_code_id', 'trade_id', 'active'],
                function ($row) {
                    $errors = [];
                    if (empty($row['code'])) $errors[] = 'Missing code';
                    if (empty($row['name'])) $errors[] = 'Missing name';
                    if (empty($row['company_code_id'])) $errors[] = 'Missing company_code_id';
                    if (empty($row['trade_id'])) $errors[] = 'Missing trade_id';
                    return $errors;
                },
                function ($row) {
                    return [
                        'code' => $row['code'],
                        'name' => $row['name'],
                        'company_code_id' => $row['company_code_id'],
                        'trade_id' => $row['trade_id'],
                        'active' => boolval($row['active'] ?? true),
                    ];
                },
                true // Enable header validation
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
            
            Cache::forget("transaction_series_" . tenant('id'));
            return response()->json([
                'message' => 'Import successful',
                'rows_imported' => $import->getImportedCount(),
                'rows_skipped_count' => $import->getSkippedCount(),
                'skipped_rows' => $import->getSkippedRows(),
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Import failed: ' . $e->getMessage()], 422);
        }
    }

    public function getByCompanyCode($companyCodeId)
    {
        $tenantId = tenant('id');
        $cacheKey = "company_code_transaction_series_{$companyCodeId}_{$tenantId}";

        return Cache::remember($cacheKey, 3600, function () use ($companyCodeId) {
            return TransactionSeries::where('company_code_id', $companyCodeId)
                ->with(['companyCode', 'trade'])
                ->get();
        });
    }

    public function getByTrade($tradeId)
    {
        $tenantId = tenant('id');
        $cacheKey = "trade_transaction_series_{$tradeId}_{$tenantId}";

        return Cache::remember($cacheKey, 3600, function () use ($tradeId) {
            return TransactionSeries::where('trade_id', $tradeId)
                ->with(['companyCode', 'trade'])
                ->get();
        });
    }
}
