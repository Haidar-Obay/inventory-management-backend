<?php

namespace App\Services;

use App\Models\AvailableCurrency;
use App\Models\Currency;
use App\Models\CurrencyPairRate;
use App\Models\TenantSetting;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CurrencyCreationService
{
    /**
     * Create currencies in tenant database from selected currency codes.
     * Exchange rates are only created for entries in $currencyPairs (from_code, to_code, rate).
     * If user did not provide a rate for a currency, no pair is created.
     *
     * @param  array  $currencyCodes  Array of currency codes (e.g., ['USD', 'EUR', 'LBP'])
     * @param  string  $primaryCode  Primary currency code
     * @param  array|null  $currencyPairs  Optional array of ['from_code' => string, 'to_code' => string, 'rate' => float]
     * @return array Array of created currency IDs with code mapping
     */
    public function createCurrenciesForTenant(array $currencyCodes, string $primaryCode, ?array $currencyPairs = null): array
    {
        return DB::transaction(function () use ($currencyCodes, $primaryCode, $currencyPairs) {
            $createdCurrencies = [];
            $primaryCurrencyId = null;

            // Get available currencies from central database
            $availableCurrencies = tenancy()->central(function () use ($currencyCodes) {
                return AvailableCurrency::whereIn('code', $currencyCodes)
                    ->where('is_active', true)
                    ->get()
                    ->keyBy('code');
            });

            // Create currencies in tenant database (no pair rates yet)
            foreach ($currencyCodes as $code) {
                $availableCurrency = $availableCurrencies->get($code);

                if (! $availableCurrency) {
                    throw new \InvalidArgumentException("Currency code '{$code}' is not available or inactive.");
                }

                $existingCurrency = Currency::where('code', $code)->first();

                if ($existingCurrency) {
                    $createdCurrencies[$code] = $existingCurrency->id;
                    if ($code === $primaryCode) {
                        $primaryCurrencyId = $existingCurrency->id;
                    }
                    continue;
                }

                $nextId = $this->computeNextAvailableId(Currency::class, 'id');
                $isPrimary = $code === $primaryCode;

                $currency = new Currency([
                    'name' => $availableCurrency->name,
                    'code' => $availableCurrency->code,
                    'iso_code' => $availableCurrency->iso_code,
                    'symbol' => $availableCurrency->symbol,
                ]);
                $currency->id = $nextId;
                $currency->save();

                $createdCurrencies[$code] = $currency->id;
                if ($code === $primaryCode) {
                    $primaryCurrencyId = $currency->id;
                }
            }

            if ($primaryCurrencyId) {
                $settings = TenantSetting::getSettings();
                $settings->update(['primary_currency_id' => $primaryCurrencyId]);

                // Only create pair rates that the user provided (from_code, to_code, rate)
                if (! empty($currencyPairs)) {
                    $exchangeRateService = new \App\Services\ExchangeRateService;
                    $selectedSet = array_flip($currencyCodes);
                    foreach ($currencyPairs as $pair) {
                        $fromCode = $pair['from_code'] ?? null;
                        $toCode = $pair['to_code'] ?? null;
                        $rate = isset($pair['rate']) && (float) $pair['rate'] > 0 ? (float) $pair['rate'] : null;
                        if (! $fromCode || ! $toCode || $rate === null || $fromCode === $toCode) {
                            continue;
                        }
                        if (! isset($selectedSet[$fromCode], $selectedSet[$toCode])) {
                            continue;
                        }
                        $fromId = $createdCurrencies[$fromCode] ?? null;
                        $toId = $createdCurrencies[$toCode] ?? null;
                        if ($fromId && $toId) {
                            try {
                                $exchangeRateService->setPairRate($fromId, $toId, $rate, Auth::check() ? Auth::user()->name : null);
                            } catch (\Throwable) {
                                // Skip duplicate or invalid pair
                            }
                        }
                    }
                }
            }

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
