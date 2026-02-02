<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\SetupWizard\StoreSetupWizardRequest;
use App\Models\Currency;
use App\Models\TenantSetting;
use App\Services\CurrencyCreationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class SetupWizardController extends Controller
{
    /**
     * Get current setup wizard status and settings.
     */
    public function index(): JsonResponse
    {
        try {
            $settings = TenantSetting::getSettings();
            $tenant = tenant();
            $plan = $tenant?->subscriptionPlan;
            $maxCurrencies = $plan?->max_currencies ?? 1;
            $supportsMultiCurrency = $maxCurrencies > 1;

            $settings->load(['primaryCurrency', 'secondaryCurrency']);

            return response()->json([
                'status' => true,
                'message' => 'Setup wizard status retrieved successfully.',
                'data' => [
                    'settings' => $settings,
                    'setup_completed' => $settings->isSetupCompleted(),
                    'subscription_info' => [
                        'supports_multi_currency' => $supportsMultiCurrency,
                        'max_currencies' => $maxCurrencies,
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve setup wizard status: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Store or update setup wizard settings.
     */
    public function store(StoreSetupWizardRequest $request): JsonResponse
    {
        try {
            return DB::transaction(function () use ($request) {
                $validated = $request->validated();

                // Create currencies in tenant database
                $currencyService = new CurrencyCreationService;
                $currencyRates = $validated['currency_rates'] ?? null;
                $currencyRateSources = $validated['currency_rate_sources'] ?? null;
                $currencyResult = $currencyService->createCurrenciesForTenant(
                    $validated['selected_currencies'],
                    $validated['primary_currency_code'],
                    $currencyRates,
                    $currencyRateSources
                );

                // Get or create settings
                $settings = TenantSetting::getSettings();

                // Update settings
                $settings->update([
                    'company_name' => $validated['company_name'],
                    'location' => $validated['location'],
                    'main_language' => $validated['main_language'],
                    'preferred_mode' => $validated['preferred_mode'],
                    'time_format' => $validated['time_format'],
                    'primary_currency_id' => $currencyResult['primary_currency_id'],
                    'secondary_currency_id' => null, // Not used in new format
                    'working_time_from' => $validated['working_time_from'],
                    'working_time_to' => $validated['working_time_to'],
                    'days_off' => $validated['days_off'] ?? [],
                ]);

                // Mark as completed if not already
                if (! $settings->isSetupCompleted()) {
                    $settings->markAsCompleted();
                }

                $settings->load(['primaryCurrency', 'secondaryCurrency']);

                return response()->json([
                    'status' => true,
                    'message' => 'Setup wizard completed successfully.',
                    'data' => $settings,
                ], 201);
            });
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to save setup wizard settings: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Check if setup is completed.
     */
    public function checkStatus(): JsonResponse
    {
        try {
            $settings = TenantSetting::getSettings();

            return response()->json([
                'status' => true,
                'setup_completed' => $settings->isSetupCompleted(),
                'completed_at' => $settings->completed_at?->toISOString(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to check setup status: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get available currencies for selection from central reference.
     */
    public function getAvailableCurrencies(): JsonResponse
    {
        try {
            $currencies = tenancy()->central(function () {
                return \App\Models\AvailableCurrency::where('is_active', true)
                    ->orderBy('name')
                    ->get(['id', 'code', 'name', 'iso_code', 'symbol']);
            });

            return response()->json([
                'status' => true,
                'message' => 'Available currencies retrieved successfully.',
                'data' => $currencies,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve available currencies: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get available currencies for selection (legacy method - kept for backward compatibility).
     *
     * @deprecated Use getAvailableCurrencies() instead
     */
    public function getCurrencies(): JsonResponse
    {
        try {
            $currencies = Currency::select('id', 'name', 'code', 'iso_code')
                ->orderBy('name')
                ->get();

            return response()->json([
                'status' => true,
                'message' => 'Currencies retrieved successfully.',
                'data' => $currencies,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve currencies: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get subscription info for multi-currency support.
     */
    public function getSubscriptionInfo(): JsonResponse
    {
        try {
            $tenant = tenant();
            $plan = $tenant?->subscriptionPlan;
            $maxCurrencies = $plan?->max_currencies ?? 1;
            $supportsMultiCurrency = $maxCurrencies > 1;

            return response()->json([
                'status' => true,
                'data' => [
                    'supports_multi_currency' => $supportsMultiCurrency,
                    'max_currencies' => $maxCurrencies,
                    'plan_name' => $plan?->name ?? 'No Plan',
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve subscription info: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Reset setup wizard (mark as incomplete).
     */
    public function reset(): JsonResponse
    {
        try {
            $settings = TenantSetting::getSettings();
            $settings->update([
                'setup_completed' => false,
                'completed_at' => null,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Setup wizard reset successfully.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to reset setup wizard: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Fetch exchange rates from API for selected currencies.
     */
    public function fetchExchangeRates(): JsonResponse
    {
        try {
            $request = request();
            $request->validate([
                'currencies' => 'required|array',
                'currencies.*' => 'required|string|max:3',
                'primary_currency_code' => 'required|string|max:3',
            ]);

            $currencyCodes = $request->input('currencies');
            $primaryCode = $request->input('primary_currency_code');
            $apiKey = config('services.exchange_rate.key');

            if (! $apiKey) {
                return response()->json([
                    'status' => false,
                    'message' => 'Exchange rate API key not configured.',
                ], 500);
            }

            // Use primary currency as base for API
            $baseCurrency = $primaryCode;
            $url = "https://v6.exchangerate-api.com/v6/{$apiKey}/latest/{$baseCurrency}";

            // Disable SSL verification for development/local environments (Windows SSL certificate issues)
            $httpClient = \Illuminate\Support\Facades\Http::timeout(10);
            if (app()->environment(['local', 'development', 'testing'])) {
                $httpClient = $httpClient->withoutVerifying();
            }
            $response = $httpClient->get($url);

            if (! $response->ok()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Failed to fetch exchange rates from API.',
                ], 500);
            }

            $conversionRates = $response->json()['conversion_rates'] ?? [];
            $rates = [];

            foreach ($currencyCodes as $code) {
                if ($code === $primaryCode) {
                    $rates[$code] = 1.0000; // Primary always 1.0
                } elseif (isset($conversionRates[$code])) {
                    // API returns rate relative to base (primary), so we use it directly
                    $rates[$code] = (float) $conversionRates[$code];
                } else {
                    $rates[$code] = null; // Currency not found in API
                }
            }

            return response()->json([
                'status' => true,
                'message' => 'Exchange rates fetched successfully.',
                'data' => [
                    'rates' => $rates,
                    'base_currency' => $baseCurrency,
                    'fetched_at' => now()->toISOString(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch exchange rates: '.$e->getMessage(),
            ], 500);
        }
    }
}
