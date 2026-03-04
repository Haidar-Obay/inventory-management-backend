<?php

namespace App\Http\Controllers;

use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Http\Requests\Currency\StoreCurrencyRequest;
use App\Http\Requests\Currency\UpdateCurrencyRequest;
use App\Imports\DynamicExcelImport;
use App\Models\Currency;
use App\Models\CurrencyPairRate;
use App\Models\TenantSetting;
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
        // $tenantId = tenant('id');
        // $key = "tenant_{$tenantId}_currencies";

        // $currencies = app('cache')->store('database')->get($key);

        return response()->json(Currency::all()->map(fn (Currency $c) => array_merge($c->toArray(), ['is_primary' => $c->isPrimary()])));
    }

    public function store(StoreCurrencyRequest $request)
    {
        $isPrimary = $request->boolean('is_primary');
        $rate = null;
        if ($isPrimary) {
            $rate = 1.0;
        } elseif ($request->filled('rate') && is_numeric($request->rate) && (float) $request->rate >= 0) {
            $rate = (float) $request->rate;
        }
        // Rate is configured separately via Exchange rates (pair rates). No API fetch when adding currency.

        $nextId = $this->computeNextAvailableId(Currency::class, 'id');
        $currency = new Currency([
            'name' => $request->name,
            'code' => $request->code,
            'iso_code' => $request->iso_code,
            'active' => $request->active ?? true,
            'smallest_unit' => $request->smallest_unit,
            'round_limit' => $request->round_limit,
            'acceptable_amount_overdue' => $request->acceptable_amount_overdue,
            'allowed_difference_in_receipt' => $request->allowed_difference_in_receipt,
            'allowed_difference_in_payment' => $request->allowed_difference_in_payment,
        ]);
        $currency->id = $nextId;
        $currency->save();

        if ($isPrimary) {
            TenantSetting::getSettings()->update(['primary_currency_id' => $currency->id]);
        } else {
            // Store pair rate: from_currency_id → to_currency_id (1 from = rate × to). to defaults to new currency.
            $fromId = $request->filled('from_currency_id') ? (int) $request->from_currency_id : null;
            $toId = $request->filled('to_currency_id') ? (int) $request->to_currency_id : $currency->id;
            if ($rate !== null && $rate > 0 && $fromId && $fromId !== $currency->id) {
                $exchangeRateService = new ExchangeRateService;
                $exchangeRateService->setPairRate($fromId, $toId, (float) $rate, Auth::check() ? Auth::user()->name : null);
            }
        }

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_currencies");

        return response()->json($currency, 201);
    }

    public function show($id)
    {
        $currency = Currency::findOrFail($id);

        return response()->json($currency);
    }

    public function update(UpdateCurrencyRequest $request, $id)
    {
        $currency = Currency::findOrFail($id);

        $update = [];
        foreach (['name', 'code', 'iso_code', 'active', 'smallest_unit', 'round_limit', 'acceptable_amount_overdue', 'allowed_difference_in_receipt', 'allowed_difference_in_payment'] as $key) {
            if ($request->has($key)) {
                $update[$key] = $request->$key;
            }
        }
        if (count($update) > 0) {
            $currency->update($update);
        }

        $shouldUpdateRate = $request->has('rate') || $request->boolean('is_primary') || $currency->isPrimary();
        if ($shouldUpdateRate) {
            $rate = null;
            if ($request->has('rate') && is_numeric($request->rate) && (float) $request->rate >= 0) {
                $rate = (float) $request->rate;
            }
            $rateSource = 'manual';
            if ($rate === null) {
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
                $rateSource = 'api';
            }

            if ($request->boolean('is_primary')) {
                TenantSetting::getSettings()->update(['primary_currency_id' => $currency->id]);
                $rate = 1.0;
                $rateSource = 'manual';
            } elseif ($currency->isPrimary()) {
                $rate = 1.0;
                $rateSource = 'manual';
            }
            $exchangeRateService = new ExchangeRateService;
            $exchangeRateService->updateRate($currency->id, (float) $rate, $rateSource, Auth::check() ? Auth::user()->name : null, 'Updated via currency settings');
        }

        $currency->refresh();

        return response()->json($currency);
    }

    public function destroy($id)
    {
        $currency = Currency::findOrFail($id);

        // Prevent deletion if used in transactions (invoices; receipts when implemented)
        $invoicesCount = \App\Models\Invoice::where('currency_id', $currency->id)->count();
        if ($invoicesCount > 0) {
            $identifier = $currency->name ?? $currency->code ?? $currency->iso_code ?? "ID: {$currency->id}";

            return response()->json([
                'status' => false,
                'message' => "Cannot delete currency \"{$identifier}\". It is used in transactions (invoices).",
                'details' => ['invoices' => ['count' => $invoicesCount]],
            ], 409);
        }

        // Prevent deletion if referenced by customers/suppliers limits or opening balances (per-currency tables)
        $customerCreditLimitsCount = \App\Models\CustomerCreditLimit::where('currency_id', $currency->id)->count();
        $customerChequeLimitsCount = \App\Models\CustomerChequeLimit::where('currency_id', $currency->id)->count();
        $customerOpeningBalancesCount = \App\Models\CustomerOpeningBalance::where('currency_id', $currency->id)->count();
        $supplierCreditLimitsCount = \App\Models\SupplierCreditLimit::where('currency_id', $currency->id)->count();
        $supplierChequeLimitsCount = \App\Models\SupplierChequeLimit::where('currency_id', $currency->id)->count();
        $supplierOpeningBalancesCount = \App\Models\SupplierOpeningBalance::where('currency_id', $currency->id)->count();

        if ($customerCreditLimitsCount > 0 || $customerChequeLimitsCount > 0 ||
            $customerOpeningBalancesCount > 0 || $supplierCreditLimitsCount > 0 ||
            $supplierChequeLimitsCount > 0 || $supplierOpeningBalancesCount > 0) {

            $details = [];
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

            // Check if used in transactions (invoices; receipts when implemented)
            $invoicesCount = \App\Models\Invoice::where('currency_id', $id)->count();
            if ($invoicesCount > 0) {
                $identifier = $currency->name ?? $currency->code ?? $currency->iso_code ?? "ID: {$id}";
                $skipped[] = [
                    'id' => $id,
                    'name' => $identifier,
                    'reason' => 'Cannot delete currency. It is used in transactions (invoices).',
                    'details' => ['invoices' => ['count' => $invoicesCount]],
                ];

                continue;
            }

            // Check if referenced by customers/suppliers limits or opening balances (per-currency tables)
            $customerCreditLimitsCount = \App\Models\CustomerCreditLimit::where('currency_id', $id)->count();
            $customerChequeLimitsCount = \App\Models\CustomerChequeLimit::where('currency_id', $id)->count();
            $customerOpeningBalancesCount = \App\Models\CustomerOpeningBalance::where('currency_id', $id)->count();
            $supplierCreditLimitsCount = \App\Models\SupplierCreditLimit::where('currency_id', $id)->count();
            $supplierChequeLimitsCount = \App\Models\SupplierChequeLimit::where('currency_id', $id)->count();
            $supplierOpeningBalancesCount = \App\Models\SupplierOpeningBalance::where('currency_id', $id)->count();

            if ($customerCreditLimitsCount > 0 || $customerChequeLimitsCount > 0 ||
                $customerOpeningBalancesCount > 0 || $supplierCreditLimitsCount > 0 ||
                $supplierChequeLimitsCount > 0 || $supplierOpeningBalancesCount > 0) {

                $details = [];
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
        $collection = Currency::all();
        if ($collection->isEmpty()) {
            return response()->json(['message' => 'No currencies found.'], 404);
        }
        $primary = Currency::getPrimary();
        if ($primary) {
            $exchangeRateService = new ExchangeRateService;
            foreach ($collection as $c) {
                $c->setAttribute('rate', $c->isPrimary() ? 1.0 : $exchangeRateService->getRate($primary->code, $c->code));
            }
        }
        $columns = ['id', 'name', 'code', 'iso_code', 'rate', 'smallest_unit', 'round_limit', 'acceptable_amount_overdue', 'allowed_difference_in_receipt', 'allowed_difference_in_payment', 'created_at', 'updated_at'];
        $headings = ['ID', 'Name', 'Code', 'ISO Code', 'Rate', 'Smallest Unit', 'Round Limit', 'Acceptable Amount Overdue', 'Allowed Difference (Receipt)', 'Allowed Difference (Payment)', 'Created At', 'Updated At'];

        return Excel::download(new Export($collection, $columns, $headings), 'currencies.xlsx');
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $currencies = Currency::select('id', 'name', 'code', 'iso_code', 'smallest_unit', 'round_limit', 'acceptable_amount_overdue', 'allowed_difference_in_receipt', 'allowed_difference_in_payment', 'created_at', 'updated_at')->get();

        if ($currencies->isEmpty()) {
            return response()->json(['message' => 'No currencies found.'], 404);
        }

        $primary = Currency::getPrimary();
        if ($primary) {
            $exchangeRateService = new ExchangeRateService;
            foreach ($currencies as $c) {
                $c->setAttribute('rate', $c->isPrimary() ? 1.0 : $exchangeRateService->getRate($primary->code, $c->code));
            }
        }

        $title = 'Currency Report';
        $headers = [
            'id' => 'Currency ID',
            'name' => 'Currency Name',
            'code' => 'Currency Code',
            'iso_code' => 'ISO Code',
            'rate' => 'Exchange Rate',
            'smallest_unit' => 'Smallest Unit',
            'round_limit' => 'Round Limit',
            'acceptable_amount_overdue' => 'Acceptable Amount Overdue',
            'allowed_difference_in_receipt' => 'Allowed Difference (Receipt)',
            'allowed_difference_in_payment' => 'Allowed Difference (Payment)',
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
            ['name', 'code', 'iso_code', 'smallest_unit', 'round_limit', 'acceptable_amount_overdue', 'allowed_difference_in_receipt', 'allowed_difference_in_payment'],
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

                return $errors;
            },
            function ($row) {
                return [
                    'name' => $row['name'],
                    'code' => $row['code'],
                    'iso_code' => $row['iso_code'],
                    'smallest_unit' => $row['smallest_unit'] ?? null,
                    'round_limit' => $row['round_limit'] ?? null,
                    'acceptable_amount_overdue' => $row['acceptable_amount_overdue'] ?? null,
                    'allowed_difference_in_receipt' => $row['allowed_difference_in_receipt'] ?? null,
                    'allowed_difference_in_payment' => $row['allowed_difference_in_payment'] ?? null,
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
                'data' => $currency,
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
     * List all pair rates (for settings UI). Returns from/to codes and rate.
     */
    public function indexPairRates(Request $request): JsonResponse
    {
        $pairs = CurrencyPairRate::with(['fromCurrency', 'toCurrency'])->get();
        $data = $pairs->map(function ($row) {
            return [
                'id' => $row->id,
                'from_currency_id' => $row->from_currency_id,
                'to_currency_id' => $row->to_currency_id,
                'from_code' => $row->fromCurrency?->code,
                'to_code' => $row->toCurrency?->code,
                'rate' => (float) $row->rate,
                'effective_from' => $row->effective_from?->toIso8601String(),
            ];
        });

        return response()->json($data);
    }

    /**
     * Set or update a pair rate (1 from_currency = rate × to_currency).
     * Only one direction per pair is stored; the inverse is computed automatically.
     */
    public function storePairRate(Request $request)
    {
        $request->validate([
            'from_currency_id' => 'required|integer|exists:currencies,id',
            'to_currency_id' => 'required|integer|exists:currencies,id',
            'rate' => 'required|numeric|min:0.000001',
        ]);

        if ((int) $request->from_currency_id === (int) $request->to_currency_id) {
            return response()->json([
                'status' => false,
                'message' => 'From and to currency must be different.',
            ], 422);
        }

        try {
            $exchangeRateService = new ExchangeRateService;
            $pair = $exchangeRateService->setPairRate(
                (int) $request->from_currency_id,
                (int) $request->to_currency_id,
                (float) $request->rate,
                Auth::check() ? Auth::user()->name : null
            );

            return response()->json([
                'status' => true,
                'message' => 'Pair rate saved. Inverse (to→from) is computed as 1/rate when needed.',
                'data' => $pair->load(['fromCurrency', 'toCurrency']),
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to save pair rate: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a pair rate (by from_currency_id and to_currency_id).
     */
    public function destroyPairRate(Request $request): JsonResponse
    {
        $request->validate([
            'from_currency_id' => 'required|integer|exists:currencies,id',
            'to_currency_id' => 'required|integer|exists:currencies,id',
        ]);
        $fromId = (int) $request->from_currency_id;
        $toId = (int) $request->to_currency_id;

        $deleted = CurrencyPairRate::where('from_currency_id', $fromId)
            ->where('to_currency_id', $toId)
            ->delete();
        if ($deleted === 0) {
            $reverse = CurrencyPairRate::where('from_currency_id', $toId)
                ->where('to_currency_id', $fromId)
                ->first();
            if ($reverse) {
                $reverse->delete();
                $deleted = 1;
            }
        }

        return response()->json([
            'status' => true,
            'message' => 'Pair rate removed.',
            'deleted' => $deleted > 0,
        ]);
    }

    /**
     * Get exchange rate history for a currency (all pairs where this currency is "from", e.g. USD→LBP, USD→EUR).
     * Query: from, to (date range, optional).
     */
    public function getRateHistory(Request $request, $id)
    {
        $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);

        try {
            $currency = Currency::findOrFail($id);
            $exchangeRateService = new ExchangeRateService;
            $data = $exchangeRateService->getPairRateHistory(
                (int) $id,
                $request->input('from'),
                $request->input('to')
            );

            return response()->json([
                'status' => true,
                'message' => 'Rate history retrieved.',
                'data' => [
                    'currency' => $data['currency']->only(['id', 'code', 'name', 'symbol']),
                    'pairs' => array_map(function ($p) {
                        return [
                            'to_currency' => $p['to_currency']->only(['id', 'code', 'name', 'symbol']),
                            'current_rate' => $p['current_rate'],
                            'history' => $p['history'],
                        ];
                    }, $data['pairs']),
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
                        'rate_source' => 'current',
                        'primary_currency' => [
                            'code' => $primaryCurrency->code,
                            'name' => $primaryCurrency->name,
                            'symbol' => $primaryCurrency->symbol,
                        ],
                    ],
                ]);
            }

            $exchangeRateService = app(ExchangeRateService::class);
            $requestedDate = $request->input('date');

            if ($requestedDate) {
                $result = $exchangeRateService->getRateAsOfDate(
                    $currency->code,
                    $primaryCurrency->code,
                    $requestedDate
                );
                $stored = isset($result['from_code'], $result['to_code'], $result['stored_rate'])
                    ? ['from_code' => $result['from_code'], 'to_code' => $result['to_code'], 'rate' => $result['stored_rate']]
                    : $exchangeRateService->getStoredPair($currency->code, $primaryCurrency->code);
                $data = [
                    'exchange_rate' => (float) $result['rate'],
                    'is_primary' => false,
                    'rate_source' => $result['source'],
                    'effective_from' => $result['effective_from'] ?? null,
                    'effective_to' => $result['effective_to'] ?? null,
                    'primary_currency' => [
                        'code' => $primaryCurrency->code,
                        'name' => $primaryCurrency->name,
                        'symbol' => $primaryCurrency->symbol,
                    ],
                ];
                if ($stored) {
                    $data['from_code'] = $stored['from_code'];
                    $data['to_code'] = $stored['to_code'];
                    $data['rate'] = $stored['rate'];
                }

                return response()->json(['status' => true, 'data' => $data]);
            }

            $rate = $exchangeRateService->getRate($currency->code, $primaryCurrency->code);
            $stored = $exchangeRateService->getStoredPair($currency->code, $primaryCurrency->code);
            $data = [
                'exchange_rate' => (float) $rate,
                'is_primary' => false,
                'rate_source' => 'current',
                'primary_currency' => [
                    'code' => $primaryCurrency->code,
                    'name' => $primaryCurrency->name,
                    'symbol' => $primaryCurrency->symbol,
                ],
            ];
            if ($stored) {
                $data['from_code'] = $stored['from_code'];
                $data['to_code'] = $stored['to_code'];
                $data['rate'] = $stored['rate'];
            }

            return response()->json(['status' => true, 'data' => $data]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve exchange rate: '.$e->getMessage(),
            ], 500);
        }
    }
}
