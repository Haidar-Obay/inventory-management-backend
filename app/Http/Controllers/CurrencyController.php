<?php

namespace App\Http\Controllers;

use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Http\Requests\Currency\StoreCurrencyRequest;
use App\Http\Requests\Currency\UpdateCurrencyRequest;
use App\Imports\DynamicExcelImport;
use App\Models\Currency;
use App\Services\ExchangeRateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Maatwebsite\Excel\Facades\Excel;

class CurrencyController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_currencies";

        $currencies = app('cache')->store('database')->get($key);

        if (! $currencies) {
            $currencies = Currency::all();
            app('cache')->store('database')->forever($key, $currencies);
        }

        return response()->json($currencies);
    }

    public function store(StoreCurrencyRequest $request)
    {
        $apiKey = config('services.exchange_rate.key');
        $baseCurrency = 'USD';
        $url = "https://v6.exchangerate-api.com/v6/{$apiKey}/latest/{$baseCurrency}";

        $response = Http::get($url);
        if (! $response->ok()) {
            return response()->json(['message' => 'Failed to fetch exchange rate.'], 500);
        }

        $rate = $response['conversion_rates'][$request->code] ?? null;
        if (! $rate) {
            return response()->json(['message' => 'Invalid currency code.'], 422);
        }

        $nextId = $this->computeNextAvailableId(Currency::class, 'id');
        $currency = new Currency([
            'name' => $request->name,
            'code' => $request->code,
            'iso_code' => $request->iso_code,
            'rate' => $rate,
        ]);
        $currency->id = $nextId;
        $currency->save();

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_currencies");

        return response()->json($currency, 201);
    }

    public function show($id)
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_currency_show_{$id}";

        $cached = app('cache')->store('database')->get($key);

        if (! $cached) {
            $currency = Currency::findOrFail($id);
            $apiKey = config('services.exchange_rate.key');
            $baseCurrency = 'USD';
            $url = "https://v6.exchangerate-api.com/v6/{$apiKey}/latest/{$baseCurrency}";
            $response = Http::get($url);
            if ($response->ok()) {
                $currency->rate = $response['conversion_rates'][$currency->code] ?? $currency->rate;
            }
            app('cache')->store('database')->forever($key, $currency);
        } else {
            $currency = $cached;
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
        if (! $response->ok()) {
            return response()->json(['message' => 'Failed to fetch exchange rate.'], 500);
        }

        $rate = $response['conversion_rates'][$request->code] ?? null;
        if (! $rate) {
            return response()->json(['message' => 'Invalid currency code.'], 422);
        }

        $currency->update([
            'name' => $request->name,
            'code' => $request->code,
            'iso_code' => $request->iso_code,
            'rate' => $rate,
        ]);

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_currencies");
        app('cache')->store('database')->forget("tenant_{$tenantId}_currency_show_{$id}");

        return response()->json($currency);
    }

    public function destroy($id)
    {
        $currency = Currency::findOrFail($id);

        // Prevent deletion if referenced by suppliers, customers, or any limit/balance tables
        $suppliersCount = \App\Models\Supplier::where('currency_id', $currency->id)->count();
        $customerCreditLimitsCount = \App\Models\CustomerCreditLimit::where('currency_id', $currency->id)->count();
        $customerChequeLimitsCount = \App\Models\CustomerChequeLimit::where('currency_id', $currency->id)->count();
        $customerOpeningBalancesCount = \App\Models\CustomerOpeningBalance::where('currency_id', $currency->id)->count();
        $supplierCreditLimitsCount = \App\Models\SupplierCreditLimit::where('currency_id', $currency->id)->count();
        $supplierChequeLimitsCount = \App\Models\SupplierChequeLimit::where('currency_id', $currency->id)->count();
        $supplierOpeningBalancesCount = \App\Models\SupplierOpeningBalance::where('currency_id', $currency->id)->count();

        if ($suppliersCount > 0 || $customerCreditLimitsCount > 0 || $customerChequeLimitsCount > 0 ||
            $customerOpeningBalancesCount > 0 || $supplierCreditLimitsCount > 0 ||
            $supplierChequeLimitsCount > 0 || $supplierOpeningBalancesCount > 0) {

            $details = [];
            if ($suppliersCount > 0) {
                $details['suppliers'] = [
                    'count' => $suppliersCount,
                    'sample_ids' => \App\Models\Supplier::where('currency_id', $currency->id)
                        ->select('suppliers.id')
                        ->limit(1)
                        ->pluck('id'),
                ];
            }
            if ($customerCreditLimitsCount > 0) {
                $details['customer_credit_limits'] = [
                    'count' => $customerCreditLimitsCount,
                    'sample_ids' => \App\Models\CustomerCreditLimit::where('currency_id', $currency->id)
                        ->select('customer_credit_limits.id')
                        ->limit(1)
                        ->pluck('id'),
                ];
            }
            if ($customerChequeLimitsCount > 0) {
                $details['customer_cheque_limits'] = [
                    'count' => $customerChequeLimitsCount,
                    'sample_ids' => \App\Models\CustomerChequeLimit::where('currency_id', $currency->id)
                        ->select('customer_cheque_limits.id')
                        ->limit(1)
                        ->pluck('id'),
                ];
            }
            if ($customerOpeningBalancesCount > 0) {
                $details['customer_opening_balances'] = [
                    'count' => $customerOpeningBalancesCount,
                    'sample_ids' => \App\Models\CustomerOpeningBalance::where('currency_id', $currency->id)
                        ->select('customer_opening_balances.id')
                        ->limit(1)
                        ->pluck('id'),
                ];
            }
            if ($supplierCreditLimitsCount > 0) {
                $details['supplier_credit_limits'] = [
                    'count' => $supplierCreditLimitsCount,
                    'sample_ids' => \App\Models\SupplierCreditLimit::where('currency_id', $currency->id)
                        ->select('supplier_credit_limits.id')
                        ->limit(1)
                        ->pluck('id'),
                ];
            }
            if ($supplierChequeLimitsCount > 0) {
                $details['supplier_cheque_limits'] = [
                    'count' => $supplierChequeLimitsCount,
                    'sample_ids' => \App\Models\SupplierChequeLimit::where('currency_id', $currency->id)
                        ->select('supplier_cheque_limits.id')
                        ->limit(1)
                        ->pluck('id'),
                ];
            }
            if ($supplierOpeningBalancesCount > 0) {
                $details['supplier_opening_balances'] = [
                    'count' => $supplierOpeningBalancesCount,
                    'sample_ids' => \App\Models\SupplierOpeningBalance::where('currency_id', $currency->id)
                        ->select('supplier_opening_balances.id')
                        ->limit(1)
                        ->pluck('id'),
                ];
            }

            $identifier = $currency->name ?? $currency->code ?? $currency->iso_code ?? "ID: {$currency->id}";

            return response()->json([
                'status' => false,
                'message' => "Cannot delete currency \"{$identifier}\" (ID: {$currency->id}). It is referenced by existing records (suppliers, customers, or their limits/balances).",
                'details' => $details,
            ], 409);
        }

        $currency->delete();

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_currencies");
        app('cache')->store('database')->forget("tenant_{$tenantId}_currency_show_{$id}");

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
        $tenantId = tenant('id');

        foreach ($request->ids as $id) {
            $currency = Currency::find($id);

            if (! $currency) {
                $skipped[] = [
                    'id' => $id,
                    'name' => "ID: {$id}",
                    'reason' => 'Currency not found.',
                ];

                continue;
            }

            // Check if referenced by suppliers, customers, or any limit/balance tables and include details
            $suppliersCount = \App\Models\Supplier::where('currency_id', $id)->count();
            $customerCreditLimitsCount = \App\Models\CustomerCreditLimit::where('currency_id', $id)->count();
            $customerChequeLimitsCount = \App\Models\CustomerChequeLimit::where('currency_id', $id)->count();
            $customerOpeningBalancesCount = \App\Models\CustomerOpeningBalance::where('currency_id', $id)->count();
            $supplierCreditLimitsCount = \App\Models\SupplierCreditLimit::where('currency_id', $id)->count();
            $supplierChequeLimitsCount = \App\Models\SupplierChequeLimit::where('currency_id', $id)->count();
            $supplierOpeningBalancesCount = \App\Models\SupplierOpeningBalance::where('currency_id', $id)->count();

            if ($suppliersCount > 0 || $customerCreditLimitsCount > 0 || $customerChequeLimitsCount > 0 ||
                $customerOpeningBalancesCount > 0 || $supplierCreditLimitsCount > 0 ||
                $supplierChequeLimitsCount > 0 || $supplierOpeningBalancesCount > 0) {

                $details = [];
                if ($suppliersCount > 0) {
                    $details['suppliers'] = [
                        'count' => $suppliersCount,
                        'sample_ids' => \App\Models\Supplier::where('currency_id', $id)
                            ->select('suppliers.id')
                            ->limit(1)
                            ->pluck('id'),
                    ];
                }
                if ($customerCreditLimitsCount > 0) {
                    $details['customer_credit_limits'] = [
                        'count' => $customerCreditLimitsCount,
                        'sample_ids' => \App\Models\CustomerCreditLimit::where('currency_id', $id)
                            ->select('customer_credit_limits.id')
                            ->limit(1)
                            ->pluck('id'),
                    ];
                }
                if ($customerChequeLimitsCount > 0) {
                    $details['customer_cheque_limits'] = [
                        'count' => $customerChequeLimitsCount,
                        'sample_ids' => \App\Models\CustomerChequeLimit::where('currency_id', $id)
                            ->select('customer_cheque_limits.id')
                            ->limit(1)
                            ->pluck('id'),
                    ];
                }
                if ($customerOpeningBalancesCount > 0) {
                    $details['customer_opening_balances'] = [
                        'count' => $customerOpeningBalancesCount,
                        'sample_ids' => \App\Models\CustomerOpeningBalance::where('currency_id', $id)
                            ->select('customer_opening_balances.id')
                            ->limit(1)
                            ->pluck('id'),
                    ];
                }
                if ($supplierCreditLimitsCount > 0) {
                    $details['supplier_credit_limits'] = [
                        'count' => $supplierCreditLimitsCount,
                        'sample_ids' => \App\Models\SupplierCreditLimit::where('currency_id', $id)
                            ->select('supplier_credit_limits.id')
                            ->limit(1)
                            ->pluck('id'),
                    ];
                }
                if ($supplierChequeLimitsCount > 0) {
                    $details['supplier_cheque_limits'] = [
                        'count' => $supplierChequeLimitsCount,
                        'sample_ids' => \App\Models\SupplierChequeLimit::where('currency_id', $id)
                            ->select('supplier_cheque_limits.id')
                            ->limit(1)
                            ->pluck('id'),
                    ];
                }
                if ($supplierOpeningBalancesCount > 0) {
                    $details['supplier_opening_balances'] = [
                        'count' => $supplierOpeningBalancesCount,
                        'sample_ids' => \App\Models\SupplierOpeningBalance::where('currency_id', $id)
                            ->select('supplier_opening_balances.id')
                            ->limit(1)
                            ->pluck('id'),
                    ];
                }

                $identifier = $currency->name ?? $currency->code ?? $currency->iso_code ?? "ID: {$id}";
                $skipped[] = [
                    'id' => $id,
                    'name' => $identifier,
                    'reason' => 'Cannot delete currency. It is referenced by existing records (suppliers, customers, or their limits/balances).',
                    'details' => $details,
                ];

                continue;
            }

            try {
                $deleted += Currency::where('id', $id)->delete();
                app('cache')->store('database')->forget("tenant_{$tenantId}_currency_show_{$id}");
            } catch (\Illuminate\Database\QueryException $e) {
                // Check if it's a foreign key constraint error and include details
                if ($e->getCode() == '23503') {
                    $details = [];

                    try {
                        $suppliersCount = \App\Models\Supplier::where('currency_id', $id)->count();
                        $customerCreditLimitsCount = \App\Models\CustomerCreditLimit::where('currency_id', $id)->count();
                        $customerChequeLimitsCount = \App\Models\CustomerChequeLimit::where('currency_id', $id)->count();
                        $customerOpeningBalancesCount = \App\Models\CustomerOpeningBalance::where('currency_id', $id)->count();
                        $supplierCreditLimitsCount = \App\Models\SupplierCreditLimit::where('currency_id', $id)->count();
                        $supplierChequeLimitsCount = \App\Models\SupplierChequeLimit::where('currency_id', $id)->count();
                        $supplierOpeningBalancesCount = \App\Models\SupplierOpeningBalance::where('currency_id', $id)->count();

                        if ($suppliersCount > 0) {
                            $details['suppliers'] = [
                                'count' => $suppliersCount,
                                'sample_ids' => \App\Models\Supplier::where('currency_id', $id)
                                    ->select('suppliers.id')
                                    ->limit(1)
                                    ->pluck('id'),
                            ];
                        }
                        if ($customerCreditLimitsCount > 0) {
                            $details['customer_credit_limits'] = [
                                'count' => $customerCreditLimitsCount,
                                'sample_ids' => \App\Models\CustomerCreditLimit::where('currency_id', $id)
                                    ->select('customer_credit_limits.id')
                                    ->limit(1)
                                    ->pluck('id'),
                            ];
                        }
                        if ($customerChequeLimitsCount > 0) {
                            $details['customer_cheque_limits'] = [
                                'count' => $customerChequeLimitsCount,
                                'sample_ids' => \App\Models\CustomerChequeLimit::where('currency_id', $id)
                                    ->select('customer_cheque_limits.id')
                                    ->limit(1)
                                    ->pluck('id'),
                            ];
                        }
                        if ($customerOpeningBalancesCount > 0) {
                            $details['customer_opening_balances'] = [
                                'count' => $customerOpeningBalancesCount,
                                'sample_ids' => \App\Models\CustomerOpeningBalance::where('currency_id', $id)
                                    ->select('customer_opening_balances.id')
                                    ->limit(1)
                                    ->pluck('id'),
                            ];
                        }
                        if ($supplierCreditLimitsCount > 0) {
                            $details['supplier_credit_limits'] = [
                                'count' => $supplierCreditLimitsCount,
                                'sample_ids' => \App\Models\SupplierCreditLimit::where('currency_id', $id)
                                    ->select('supplier_credit_limits.id')
                                    ->limit(1)
                                    ->pluck('id'),
                            ];
                        }
                        if ($supplierChequeLimitsCount > 0) {
                            $details['supplier_cheque_limits'] = [
                                'count' => $supplierChequeLimitsCount,
                                'sample_ids' => \App\Models\SupplierChequeLimit::where('currency_id', $id)
                                    ->select('supplier_cheque_limits.id')
                                    ->limit(1)
                                    ->pluck('id'),
                            ];
                        }
                        if ($supplierOpeningBalancesCount > 0) {
                            $details['supplier_opening_balances'] = [
                                'count' => $supplierOpeningBalancesCount,
                                'sample_ids' => \App\Models\SupplierOpeningBalance::where('currency_id', $id)
                                    ->select('supplier_opening_balances.id')
                                    ->limit(1)
                                    ->pluck('id'),
                            ];
                        }
                    } catch (\Throwable $ignored) {
                    }

                    $currency = Currency::find($id);
                    $identifier = $currency?->name ?? $currency?->code ?? $currency?->iso_code ?? "ID: {$id}";
                    $skipped[] = [
                        'id' => $id,
                        'name' => $identifier,
                        'reason' => 'Cannot delete currency. It is referenced by existing records (suppliers, customers, or their limits/balances).',
                        'details' => $details,
                    ];
                } else {
                    $currency = Currency::find($id);
                    $identifier = $currency?->name ?? $currency?->code ?? $currency?->iso_code ?? "ID: {$id}";
                    $skipped[] = [
                        'id' => $id,
                        'name' => $identifier,
                        'reason' => $e->getMessage(),
                    ];
                }
            }
        }

        app('cache')->store('database')->forget("tenant_{$tenantId}_currencies");

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
        $columns = ['id', 'name', 'code', 'iso_code', 'rate', 'created_at', 'updated_at'];
        $headings = ['ID', 'Name', 'Code', 'ISO Code', 'Rate', 'Created At', 'Updated At'];

        return Excel::download(new Export($currencies, $columns, $headings), 'currencies.xlsx');
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $currencies = Currency::select('id', 'name', 'code', 'iso_code', 'rate', 'created_at', 'updated_at')->get();

        if ($currencies->isEmpty()) {
            return response()->json(['message' => 'No currencies found.'], 404);
        }

        $title = 'Currency Report';
        $headers = [
            'id' => 'Currency ID',
            'name' => 'Currency Name',
            'code' => 'Currency Code',
            'iso_code' => 'ISO Code',
            'rate' => 'Exchange Rate',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
        ];
        $data = $currencies->toArray();

        $pdf = $pdfService->generatePdf($title, $headers, $data);

        return $pdf->download('Currencies.pdf');
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
            Currency::truncate();
        }

        // If type is 'mapping', use provided mapping, else use default
        $mapping = $request->input('mapping');

        $import = new DynamicExcelImport(
            Currency::class,
            ['name', 'code', 'iso_code', 'rate'],
            function ($row) {
                $errors = [];

                if (empty($row['name'])) {
                    $errors[] = 'Missing name';
                }
                if (empty($row['code'])) {
                    $errors[] = 'Missing code';
                }
                if (empty($row['iso_code'])) {
                    $errors[] = 'Missing ISO code';
                } elseif (! is_numeric($row['iso_code'])) {
                    $errors[] = 'ISO code must be numeric';
                }

                if (! isset($row['rate']) || ! is_numeric($row['rate'])) {
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

        app('cache')->store('database')->forget('tenant_'.tenant('id').'_currencies');

        return response()->json([
            'success' => true,
            'rows_imported' => $import->getImportedCount(),
            'rows_skipped_count' => $import->getSkippedCount(),
            'skipped_rows' => $import->getSkippedRows(),
        ]);
    }

    /**
     * Update exchange rate for a currency (manual update).
     */
    public function updateRate(Request $request, $id)
    {
        $request->validate([
            'rate' => 'required|numeric|min:0.0001',
            'notes' => 'nullable|string|max:500',
        ]);

        try {
            $exchangeRateService = new ExchangeRateService;
            $updatedBy = Auth::check() ? Auth::user()->name : 'System';
            $currency = $exchangeRateService->updateRate(
                $id,
                (float) $request->rate,
                'manual',
                $updatedBy,
                $request->notes
            );

            return response()->json([
                'status' => true,
                'message' => 'Exchange rate updated successfully.',
                'data' => $currency->load('exchangeRates'),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to update exchange rate: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get exchange rate history for a currency.
     */
    public function getRateHistory($id)
    {
        try {
            $currency = Currency::findOrFail($id);
            $limit = request()->input('limit', 50);

            $exchangeRateService = new ExchangeRateService;
            $history = $exchangeRateService->getRateHistory($id, $limit);

            return response()->json([
                'status' => true,
                'message' => 'Rate history retrieved successfully.',
                'data' => [
                    'currency' => $currency,
                    'history' => $history,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve rate history: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Convert amount between two currencies.
     */
    public function convert(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0',
            'from_code' => 'required|string|exists:currencies,code',
            'to_code' => 'required|string|exists:currencies,code',
        ]);

        try {
            $exchangeRateService = new ExchangeRateService;
            $convertedAmount = $exchangeRateService->convert(
                (float) $request->amount,
                $request->from_code,
                $request->to_code
            );

            $rate = $exchangeRateService->getRate($request->from_code, $request->to_code);

            return response()->json([
                'status' => true,
                'message' => 'Conversion successful.',
                'data' => [
                    'original_amount' => (float) $request->amount,
                    'from_currency' => $request->from_code,
                    'to_currency' => $request->to_code,
                    'rate' => $rate,
                    'converted_amount' => $convertedAmount,
                ],
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to convert: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get exchange rate for a currency (relative to primary currency).
     * Used for invoice forms to auto-fill exchange_rate field.
     */
    public function getExchangeRate(Request $request): JsonResponse
    {
        $request->validate([
            'currency_id' => 'required|integer|exists:currencies,id',
            'date' => 'nullable|date', // Optional: for historical invoices
        ]);

        try {
            $currency = Currency::findOrFail($request->currency_id);
            $primaryCurrency = Currency::getPrimary();

            if (! $primaryCurrency) {
                return response()->json([
                    'status' => false,
                    'message' => 'Primary currency not set.',
                ], 422);
            }

            // If currency is primary, rate is always 1.0
            if ($currency->isPrimary()) {
                return response()->json([
                    'status' => true,
                    'data' => [
                        'exchange_rate' => 1.0000,
                        'is_primary' => true,
                        'primary_currency' => [
                            'code' => $primaryCurrency->code,
                            'name' => $primaryCurrency->name,
                            'symbol' => $primaryCurrency->symbol,
                        ],
                    ],
                ]);
            }

            // Get rate for the selected currency (relative to primary)
            // If date is provided, use historical rate, otherwise use current rate
            $rate = $currency->rate;

            if ($request->date) {
                $exchangeRateService = new ExchangeRateService;
                $historicalRate = $exchangeRateService->getRateForDate(
                    $currency->id,
                    $request->date
                );
                if ($historicalRate) {
                    $rate = $historicalRate->rate;
                }
            }

            return response()->json([
                'status' => true,
                'data' => [
                    'exchange_rate' => (float) $rate,
                    'is_primary' => false,
                    'primary_currency' => [
                        'code' => $primaryCurrency->code,
                        'name' => $primaryCurrency->name,
                        'symbol' => $primaryCurrency->symbol,
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve exchange rate: '.$e->getMessage(),
            ], 500);
        }
    }
}
