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

        return response()->json($transactionSeries->load(['companyCode', 'trade']), 201);
    }

    public function show(TransactionSeries $transactionSeries)
    {
        $tenantId = tenant('id');
        $cacheKey = "transaction_series_{$transactionSeries->id}_{$tenantId}";

        return Cache::remember($cacheKey, 3600, function () use ($transactionSeries) {
            return $transactionSeries->load(['companyCode', 'trade']);
        });
    }

    public function update(UpdateTransactionSeriesRequest $request, TransactionSeries $transactionSeries)
    {
        $transactionSeries->update($request->validated());
        Cache::forget("transaction_series_" . tenant('id'));
        Cache::forget("transaction_series_{$transactionSeries->id}_" . tenant('id'));

        return response()->json($transactionSeries->load(['companyCode', 'trade']));
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

        $export = new Export($transactionSeries, [
            'id' => 'ID',
            'code' => 'Code',
            'name' => 'Name',
            'template' => 'Template',
            'companyCode.code' => 'Company Code',
            'trade.code' => 'Trade'
        ]);

        return Excel::download($export, 'transaction_series.xlsx');
    }

    public function exportPdf()
    {
        $transactionSeries = TransactionSeries::with(['companyCode', 'trade'])->get();

        if ($transactionSeries->isEmpty()) {
            return response()->json(['message' => 'No data to export'], 404);
        }

        $export = new ExportPDF($transactionSeries, [
            'id' => 'ID',
            'code' => 'Code',
            'name' => 'Name',
            'template' => 'Template',
            'companyCode.code' => 'Company Code',
            'trade.code' => 'Trade'
        ]);

        return $export->download('transaction_series.pdf');
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls,csv|max:2048'
        ]);

        try {
            $import = new DynamicExcelImport(TransactionSeries::class);
            Excel::import($import, $request->file('file'));
            Cache::forget("transaction_series_" . tenant('id'));
            return response()->json(['message' => 'Import successful']);
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
