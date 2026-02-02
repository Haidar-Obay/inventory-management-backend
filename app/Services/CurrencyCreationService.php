<?php

namespace App\Services;

use App\Models\AvailableCurrency;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\TenantSetting;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CurrencyCreationService
{
    /**
     * Create currencies in tenant database from selected currency codes.
     * Sets default rate to 1.0 (user can change it later).
     *
     * @param  array  $currencyCodes  Array of currency codes (e.g., ['USD', 'EUR'])
     * @param  string  $primaryCode  Primary currency code
     * @param  array|null  $currencyRates  Optional array of rates: ['EUR' => 0.85, 'LBP' => 1500]
     * @param  array|null  $currencyRateSources  Optional array of rate sources: ['EUR' => 'api', 'LBP' => 'manual']
     * @return array Array of created currency IDs with code mapping
     */
    public function createCurrenciesForTenant(array $currencyCodes, string $primaryCode, ?array $currencyRates = null, ?array $currencyRateSources = null): array
    {
        return DB::transaction(function () use ($currencyCodes, $primaryCode, $currencyRates, $currencyRateSources) {
            $createdCurrencies = [];
            $primaryCurrencyId = null;

            // Get available currencies from central database
            $availableCurrencies = tenancy()->central(function () use ($currencyCodes) {
                return AvailableCurrency::whereIn('code', $currencyCodes)
                    ->where('is_active', true)
                    ->get()
                    ->keyBy('code');
            });

            // Create currencies in tenant database
            foreach ($currencyCodes as $code) {
                $availableCurrency = $availableCurrencies->get($code);

                if (! $availableCurrency) {
                    throw new \InvalidArgumentException("Currency code '{$code}' is not available or inactive.");
                }

                // Check if currency already exists in tenant DB
                $existingCurrency = Currency::where('code', $code)->first();

                if ($existingCurrency) {
                    // Update rate if provided and currency is not primary
                    $isPrimary = $code === $primaryCode;
                    if (! $isPrimary && isset($currencyRates[$code]) && $currencyRates[$code] > 0) {
                        $newRate = (float) $currencyRates[$code];
                        $rateSource = isset($currencyRateSources[$code]) && ! empty($currencyRateSources[$code])
                            ? $currencyRateSources[$code]
                            : 'manual';
                        $updatedBy = Auth::check() ? Auth::user()->name : 'System';
                        // Update rate using the model method (only creates history if rate or source changed)
                        $existingCurrency->updateRate($newRate, $rateSource, $updatedBy, 'Rate updated during setup wizard');
                    }

                    $createdCurrencies[$code] = $existingCurrency->id;
                    if ($code === $primaryCode) {
                        $primaryCurrencyId = $existingCurrency->id;
                    }

                    continue;
                }

                // Get next available ID
                $nextId = $this->computeNextAvailableId(Currency::class, 'id');

                // Determine rate: primary currency = 1.0, others from provided rates or default 1.0
                $isPrimary = $code === $primaryCode;
                if ($isPrimary) {
                    $rate = 1.0000; // Primary always 1.0
                } else {
                    // Use provided rate if available, otherwise default to 1.0
                    $rate = isset($currencyRates[$code]) && $currencyRates[$code] > 0
                        ? (float) $currencyRates[$code]
                        : 1.0000;
                }

                // Determine rate source: use provided source or default to 'manual'
                $rateSource = (isset($currencyRateSources[$code]) && ! empty($currencyRateSources[$code]))
                    ? $currencyRateSources[$code]
                    : 'manual';

                // Get user name for tracking
                $updatedBy = Auth::check() ? Auth::user()->name : 'System';

                // Create currency
                $currency = new Currency([
                    'name' => $availableCurrency->name,
                    'code' => $availableCurrency->code,
                    'iso_code' => $availableCurrency->iso_code,
                    'rate' => $rate,
                    'rate_source' => $rateSource,
                    'rate_updated_at' => now(),
                    'rate_updated_by' => $updatedBy,
                    'auto_update_enabled' => false,
                    'symbol' => $availableCurrency->symbol,
                ]);
                $currency->id = $nextId;
                $currency->save();

                // Create initial exchange rate history record
                $notes = $isPrimary
                    ? 'Initial primary currency rate'
                    : (isset($currencyRates[$code])
                        ? 'Initial currency rate set during setup'
                        : 'Initial currency rate (to be updated)');

                ExchangeRate::create([
                    'currency_id' => $currency->id,
                    'rate' => $rate,
                    'rate_source' => $rateSource,
                    'effective_from' => now(),
                    'effective_to' => null,
                    'updated_by' => $updatedBy,
                    'notes' => $notes,
                ]);

                $createdCurrencies[$code] = $currency->id;

                if ($code === $primaryCode) {
                    $primaryCurrencyId = $currency->id;
                }
            }

            // Set primary currency in tenant settings
            if ($primaryCurrencyId) {
                $settings = TenantSetting::getSettings();
                $settings->update([
                    'primary_currency_id' => $primaryCurrencyId,
                ]);
            }

            // Clear currency cache
            $tenantId = tenant('id');
            app('cache')->store('database')->forget("tenant_{$tenantId}_currencies");

            return [
                'currency_ids' => $createdCurrencies,
                'primary_currency_id' => $primaryCurrencyId,
            ];
        });
    }

    /**
     * Compute next available ID for a model.
     */
    private function computeNextAvailableId(string $modelClass, string $idColumn = 'id'): int
    {
        $maxId = $modelClass::max($idColumn) ?? 0;

        return max(1, (int) $maxId + 1);
    }
}
