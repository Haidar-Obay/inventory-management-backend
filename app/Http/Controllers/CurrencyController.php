<?php

namespace App\Http\Controllers;

use App\Http\Requests\Currency\StoreCurrencyRequest;
use App\Http\Requests\Currency\UpdateCurrencyRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use App\Models\Currency;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;

class CurrencyController extends Controller
{
    public function index()
    {
        return response()->json(Currency::all());
    }

    public function store(StoreCurrencyRequest $request)
    {
        $apiKey = config('services.exchange_rate.key');
        $baseCurrency = 'USD';
        $url = "https://v6.exchangerate-api.com/v6/{$apiKey}/latest/{$baseCurrency}";

        $response = Http::get($url);
        if (!$response->ok()) {
            return response()->json(['message' => 'Failed to fetch exchange rate.'], 500);
        }

        $rate = $response['conversion_rates'][$request->code] ?? null;
        if (!$rate) {
            return response()->json(['message' => 'Invalid currency code.'], 422);
        }

        $currency = Currency::create([
            'name' => $request->name,
            'code' => $request->code,
            'iso_code' => $request->iso_code,
            'rate' => $rate,
        ]);

        return response()->json($currency, 201);
    }

    public function show($id)
    {
        $currency = Currency::findOrFail($id);

        $apiKey = config('services.exchange_rate.key');
        $baseCurrency = 'USD';
        $url = "https://v6.exchangerate-api.com/v6/{$apiKey}/latest/{$baseCurrency}";

        $response = Http::get($url);
        if ($response->ok()) {
            $currency->rate = $response['conversion_rates'][$currency->code] ?? $currency->rate;
        }

        return response()->json($currency);
    }

    public function update(UpdateCurrencyRequest $request, $id)
    {
        $currency = Currency::findOrFail($id);

        $apiKey = config('services.exchange_rate.key');
        $baseCurrency = 'USD';
        $url = "https://v6.exchangerate-api.com/v6/{$apiKey}/latest/{$baseCurrency}";

        $response = Http::get($url);
        if (!$response->ok()) {
            return response()->json(['message' => 'Failed to fetch exchange rate.'], 500);
        }

        $rate = $response['conversion_rates'][$request->code] ?? null;
        if (!$rate) {
            return response()->json(['message' => 'Invalid currency code.'], 422);
        }

        $currency->update([
            'name' => $request->name,
            'code' => $request->code,
            'iso_code' => $request->iso_code,
            'rate' => $rate,
        ]);

        return response()->json($currency);
    }

    public function destroy($id)
    {
        $currency = Currency::findOrFail($id);
        $currency->delete();

        return response()->json(['message' => 'Currency deleted successfully.']);
    }

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:currencies,id',
        ]);

        $skipped = [];
        $deleted = 0;

        foreach ($request->ids as $id) {
            try {
                $deleted += Currency::where('id', $id)->delete();
            } catch (\Illuminate\Database\QueryException $e) {
                $skipped[] = ['id' => $id, 'reason' => $e->getMessage()];
            }
        }

        return response()->json([
            'message' => 'Bulk delete completed.',
            'deleted_count' => $deleted,
            'skipped' => $skipped,
        ]);
    }

    public function exportExcell()
    {
        $currencies = Currency::query();
        $collection = $currencies->get();
        if ($collection->isEmpty()) {
            return response()->json(['message' => 'No currencies found.'], 404);
        }
        $columns = ['id', 'name', 'code', 'iso_code', 'rate'];
        $headings = ['ID', 'Name', 'Code', 'ISO Code', 'Rate'];
        return Excel::download(new Export($currencies, $columns, $headings), 'currencies.xlsx');
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $currencies = Currency::select(
            'id',
            'name',
            'code',
            'iso_code',
            'rate'
        )->get();

        if ($currencies->isEmpty()) {
            return response()->json(['message' => 'No currencies found.'], 404);
        }

        $title = 'Currency Report';
        $headers = [
            'id' => 'Currency ID',
            'name' => 'Currency Name',
            'code' => 'Currency Code',
            'iso_code' => 'ISO Code',
            'rate' => 'Exchange Rate'
        ];
        $data = $currencies->toArray();

        $pdf = $pdfService->generatePdf($title, $headers, $data);
        return $pdf->download('Currencies.pdf');
    }

    public function importFromExcel(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv',
        ]);

        $import = new DynamicExcelImport(
            Currency::class,
            ['name', 'code', 'iso_code', 'rate'],
            function ($row) {
                $errors = [];

                if (empty($row['name']))
                    $errors[] = 'Missing name';
                if (empty($row['code']))
                    $errors[] = 'Missing code';
                if (empty($row['iso_code']))
                    $errors[] = 'Missing ISO code';
                elseif (!is_numeric($row['iso_code']))
                    $errors[] = 'ISO code must be numeric';

                if (!isset($row['rate']) || !is_numeric($row['rate'])) {
                    $errors[] = 'Rate must be numeric';
                }

                return $errors;
            },
            function ($row) {
                return [
                    'name' => $row['name'],
                    'code' => $row['code'],
                    'iso_code' => $row['iso_code'],
                    'rate' => $row['rate'],
                ];
            }
        );

        Excel::import($import, $request->file('file'));

        return response()->json([
            'success' => true,
            'rows_imported' => $import->getImportedCount(),
            'rows_skipped_count' => $import->getSkippedCount(),
            'skipped_rows' => $import->getSkippedRows(),
        ]);
    }


}
