<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Currency;
use App\Models\ExchangeRate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExchangeRateService
{
    /**
     * Get the exchange rate between two currencies.
     * Returns the rate to convert from $fromCode to $toCode.
     *
     * @param  string  $fromCode  Source currency code
     * @param  string  $toCode  Target currency code
     * @return float Exchange rate
     *
     * @throws \InvalidArgumentException If currencies not found
     */
    public function getRate(string $fromCode, string $toCode): float
    {
        // If same currency, rate is 1.0
        if ($fromCode === $toCode) {
            return 1.0;
        }

        $fromCurrency = Currency::where('code', $fromCode)->first();
        $toCurrency = Currency::where('code', $toCode)->first();

        if (! $fromCurrency || ! $toCurrency) {
            throw new \InvalidArgumentException("One or both currencies not found: {$fromCode} -> {$toCode}");
        }

        // Get primary currency
        $primaryCurrency = Currency::getPrimary();
        if (! $primaryCurrency) {
            throw new \InvalidArgumentException('Primary currency not set');
        }

        // If converting from primary to another currency
        if ($fromCurrency->isPrimary()) {
            return $toCurrency->rate;
        }

        // If converting to primary from another currency
        if ($toCurrency->isPrimary()) {
            return 1.0 / $fromCurrency->rate;
        }

        // Converting between two non-primary currencies
        // Rate = (from_rate / to_rate)
        // Example: EUR to LBP = (EUR_rate / LBP_rate)
        return $fromCurrency->rate / $toCurrency->rate;
    }

    /**
     * Convert an amount from one currency to another.
     *
     * @param  float  $amount  Amount to convert
     * @param  string  $fromCode  Source currency code
     * @param  string  $toCode  Target currency code
     * @return float Converted amount
     */
    public function convert(float $amount, string $fromCode, string $toCode): float
    {
        if ($fromCode === $toCode) {
            return $amount;
        }

        $rate = $this->getRate($fromCode, $toCode);

        return $amount * $rate;
    }

    /**
     * Update exchange rate for a currency.
     * Creates a history record and updates the currency.
     *
     * @param  int  $currencyId  Currency ID
     * @param  float  $rate  New rate (relative to primary currency)
     * @param  string  $source  Rate source: 'manual', 'api', 'scheduled'
     * @param  string|null  $updatedBy  User who updated (optional)
     * @param  string|null  $notes  Optional notes
     * @return Currency Updated currency
     *
     * @throws \InvalidArgumentException If currency not found or invalid rate
     */
    public function updateRate(int $currencyId, float $rate, string $source = 'manual', ?string $updatedBy = null, ?string $notes = null): Currency
    {
        return DB::transaction(function () use ($currencyId, $rate, $source, $updatedBy, $notes) {
            $currency = Currency::findOrFail($currencyId);

            // Primary currency must always have rate 1.0
            if ($currency->isPrimary()) {
                if ($rate != 1.0000) {
                    throw new \InvalidArgumentException('Primary currency rate must always be 1.0000');
                }
            }

            // Validate rate is positive
            if ($rate <= 0) {
                throw new \InvalidArgumentException('Exchange rate must be greater than 0');
            }

            // Update rate using the model method (creates history)
            $currency->updateRate($rate, $source, $updatedBy, $notes);

            return $currency->fresh();
        });
    }

    /**
     * Get rate history for a currency.
     *
     * @param  int  $currencyId  Currency ID
     * @param  int|null  $limit  Limit number of records
     * @param  string|null  $fromDate  Filter from date (Y-m-d)
     * @param  string|null  $toDate  Filter to date (Y-m-d)
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getRateHistory(int $currencyId, ?int $limit = null, ?string $fromDate = null, ?string $toDate = null)
    {
        $query = ExchangeRate::where('currency_id', $currencyId)
            ->orderBy('effective_from', 'desc');

        if ($fromDate) {
            $query->whereDate('effective_from', '>=', $fromDate);
        }
        if ($toDate) {
            $query->whereDate('effective_from', '<=', $toDate);
        }

        if ($limit) {
            $query->limit($limit);
        }

        return $query->get();
    }

    /**
     * Get rate for a specific date.
     *
     * @param  int  $currencyId  Currency ID
     * @param  \DateTime|string  $date  Date to get rate for
     * @return ExchangeRate|null
     */
    public function getRateForDate(int $currencyId, $date)
    {
        if (is_string($date)) {
            $date = new \DateTime($date);
        }

        return ExchangeRate::where('currency_id', $currencyId)
            ->forDate($date)
            ->orderBy('effective_from', 'desc')
            ->first();
    }

    /**
     * Bulk update rates (useful for API imports).
     *
     * @param  array  $rates  Array of ['currency_id' => rate, ...]
     * @param  string  $source  Rate source
     * @param  string|null  $updatedBy  User who updated
     * @return array Array of updated currencies
     */
    public function bulkUpdateRates(array $rates, string $source = 'api', ?string $updatedBy = null): array
    {
        $updated = [];

        DB::transaction(function () use ($rates, $source, $updatedBy, &$updated) {
            foreach ($rates as $currencyId => $rate) {
                try {
                    $currency = $this->updateRate($currencyId, $rate, $source, $updatedBy);
                    $updated[] = $currency;
                } catch (\Exception $e) {
                    // Log error but continue with other currencies
                    Log::error("Failed to update rate for currency {$currencyId}: ".$e->getMessage());
                }
            }
        });

        return $updated;
    }
}
