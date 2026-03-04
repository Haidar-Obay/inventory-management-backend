<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Currency;
use App\Models\CurrencyPairRate;
use App\Models\CurrencyPairRateHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExchangeRateService
{
    /**
     * Get the exchange rate between two currencies (from pair table).
     * Returns the rate to convert from $fromCode to $toCode (amount_to = amount_from * rate).
     * Lookup (from, to) or (to, from); inverse = 1/rate when reversed.
     *
     * @throws \InvalidArgumentException If currencies or pair not found
     */
    public function getRate(string $fromCode, string $toCode): float
    {
        if ($fromCode === $toCode) {
            return 1.0;
        }

        $fromCurrency = Currency::where('code', $fromCode)->first();
        $toCurrency = Currency::where('code', $toCode)->first();

        if (! $fromCurrency || ! $toCurrency) {
            throw new \InvalidArgumentException("One or both currencies not found: {$fromCode} -> {$toCode}");
        }

        $pair = CurrencyPairRate::where('from_currency_id', $fromCurrency->id)
            ->where('to_currency_id', $toCurrency->id)
            ->first();

        if ($pair) {
            return (float) $pair->rate;
        }

        $pairReversed = CurrencyPairRate::where('from_currency_id', $toCurrency->id)
            ->where('to_currency_id', $fromCurrency->id)
            ->first();

        if ($pairReversed) {
            $r = (float) $pairReversed->rate;
            if ($r <= 0) {
                throw new \InvalidArgumentException('Exchange rate must be greater than 0');
            }

            return 1.0 / $r;
        }

        throw new \InvalidArgumentException("No exchange rate defined for pair: {$fromCode} -> {$toCode}");
    }

    /**
     * Get exchange rate as of a given date (from history if available, else current pair rate).
     * Uses currency_pair_rate_history when the date falls within an effective_from/effective_to range.
     *
     * @param  string  $fromCode  Source currency code
     * @param  string  $toCode  Target currency code
     * @param  string  $date  Date (Y-m-d)
     * @return array{rate: float, source: 'current'|'history', effective_from?: string, effective_to?: string|null}
     */
    public function getRateAsOfDate(string $fromCode, string $toCode, string $date): array
    {
        if ($fromCode === $toCode) {
            return ['rate' => 1.0, 'source' => 'current'];
        }

        $fromCurrency = Currency::where('code', $fromCode)->first();
        $toCurrency = Currency::where('code', $toCode)->first();
        if (! $fromCurrency || ! $toCurrency) {
            throw new \InvalidArgumentException("One or both currencies not found: {$fromCode} -> {$toCode}");
        }

        $fromId = (int) $fromCurrency->id;
        $toId = (int) $toCurrency->id;
        $dateStart = $date.' 00:00:00';
        $dateEnd = $date.' 23:59:59';

        $historyRow = CurrencyPairRateHistory::with(['fromCurrency', 'toCurrency'])
            ->where('from_currency_id', $fromId)
            ->where('to_currency_id', $toId)
            ->where('effective_from', '<=', $dateEnd)
            ->where(function ($q) use ($dateStart) {
                $q->where('effective_to', '>=', $dateStart)
                    ->orWhereNull('effective_to');
            })
            ->orderByDesc('effective_from')
            ->first();

        if ($historyRow) {
            return [
                'rate' => (float) $historyRow->rate,
                'source' => 'history',
                'effective_from' => $historyRow->effective_from?->toIso8601String(),
                'effective_to' => $historyRow->effective_to?->toIso8601String(),
                'from_code' => $historyRow->fromCurrency->code ?? (string) $historyRow->from_currency_id,
                'to_code' => $historyRow->toCurrency->code ?? (string) $historyRow->to_currency_id,
                'stored_rate' => (float) $historyRow->rate,
            ];
        }

        $historyReversed = CurrencyPairRateHistory::with(['fromCurrency', 'toCurrency'])
            ->where('from_currency_id', $toId)
            ->where('to_currency_id', $fromId)
            ->where('effective_from', '<=', $dateEnd)
            ->where(function ($q) use ($dateStart) {
                $q->where('effective_to', '>=', $dateStart)
                    ->orWhereNull('effective_to');
            })
            ->orderByDesc('effective_from')
            ->first();

        if ($historyReversed) {
            $r = (float) $historyReversed->rate;
            if ($r <= 0) {
                throw new \InvalidArgumentException('Exchange rate must be greater than 0');
            }

            return [
                'rate' => 1.0 / $r,
                'source' => 'history',
                'effective_from' => $historyReversed->effective_from?->toIso8601String(),
                'effective_to' => $historyReversed->effective_to?->toIso8601String(),
                'from_code' => $historyReversed->fromCurrency->code ?? (string) $historyReversed->from_currency_id,
                'to_code' => $historyReversed->toCurrency->code ?? (string) $historyReversed->to_currency_id,
                'stored_rate' => (float) $historyReversed->rate,
            ];
        }

        $rate = $this->getRate($fromCode, $toCode);

        return ['rate' => $rate, 'source' => 'current'];
    }

    /**
     * Get the stored pair (from DB) for display. Returns the actual from_code, to_code, rate as stored.
     * Used so the frontend can show "from → to: rate" without reversing.
     *
     * @return array{from_code: string, to_code: string, rate: float}|null
     */
    public function getStoredPair(string $fromCode, string $toCode): ?array
    {
        if ($fromCode === $toCode) {
            return null;
        }

        $fromCurrency = Currency::where('code', $fromCode)->first();
        $toCurrency = Currency::where('code', $toCode)->first();
        if (! $fromCurrency || ! $toCurrency) {
            return null;
        }

        $pair = CurrencyPairRate::with(['fromCurrency', 'toCurrency'])
            ->where('from_currency_id', $fromCurrency->id)
            ->where('to_currency_id', $toCurrency->id)
            ->first();

        if ($pair) {
            return [
                'from_code' => $pair->fromCurrency->code ?? (string) $pair->from_currency_id,
                'to_code' => $pair->toCurrency->code ?? (string) $pair->to_currency_id,
                'rate' => (float) $pair->rate,
            ];
        }

        $pairReversed = CurrencyPairRate::with(['fromCurrency', 'toCurrency'])
            ->where('from_currency_id', $toCurrency->id)
            ->where('to_currency_id', $fromCurrency->id)
            ->first();

        if ($pairReversed) {
            return [
                'from_code' => $pairReversed->fromCurrency->code ?? (string) $pairReversed->from_currency_id,
                'to_code' => $pairReversed->toCurrency->code ?? (string) $pairReversed->to_currency_id,
                'rate' => (float) $pairReversed->rate,
            ];
        }

        return null;
    }

    /**
     * Get rate by currency IDs (for use when codes are not handy).
     */
    public function getRateById(int $fromCurrencyId, int $toCurrencyId): float
    {
        if ($fromCurrencyId === $toCurrencyId) {
            return 1.0;
        }

        $pair = CurrencyPairRate::where('from_currency_id', $fromCurrencyId)
            ->where('to_currency_id', $toCurrencyId)
            ->first();

        if ($pair) {
            return (float) $pair->rate;
        }

        $pairReversed = CurrencyPairRate::where('from_currency_id', $toCurrencyId)
            ->where('to_currency_id', $fromCurrencyId)
            ->first();

        if ($pairReversed) {
            $r = (float) $pairReversed->rate;
            if ($r <= 0) {
                throw new \InvalidArgumentException('Exchange rate must be greater than 0');
            }

            return 1.0 / $r;
        }

        $from = Currency::find($fromCurrencyId);
        $to = Currency::find($toCurrencyId);
        $fromCode = $from ? $from->code : (string) $fromCurrencyId;
        $toCode = $to ? $to->code : (string) $toCurrencyId;

        throw new \InvalidArgumentException("No exchange rate defined for pair: {$fromCode} -> {$toCode}");
    }

    /**
     * Convert an amount from one currency to another (always multiply by rate).
     */
    public function convert(float $amount, string $fromCode, string $toCode): float
    {
        if ($fromCode === $toCode) {
            return $amount;
        }

        return $amount * $this->getRate($fromCode, $toCode);
    }

    /**
     * Set or update a pair rate (1 from = rate × to). Only one direction stored; inverse is computed.
     * If the reverse pair exists, we update that row to the requested direction and rate (so both directions are "usable").
     */
    public function setPairRate(int $fromCurrencyId, int $toCurrencyId, float $rate, ?string $updatedBy = null): CurrencyPairRate
    {
        if ($fromCurrencyId === $toCurrencyId) {
            throw new \InvalidArgumentException('From and to currency must be different');
        }
        if ($rate <= 0) {
            throw new \InvalidArgumentException('Exchange rate must be greater than 0');
        }

        $existing = CurrencyPairRate::where('from_currency_id', $fromCurrencyId)
            ->where('to_currency_id', $toCurrencyId)
            ->first();

        $reverse = CurrencyPairRate::where('from_currency_id', $toCurrencyId)
            ->where('to_currency_id', $fromCurrencyId)
            ->first();

        if ($reverse && ! $existing) {
            // User wants (from→to) but DB has (to→from). Archive the reverse row and update it to (from→to) with the new rate.
            $effectiveFrom = $reverse->effective_from ?? $reverse->created_at ?? now();
            CurrencyPairRateHistory::create([
                'from_currency_id' => $reverse->from_currency_id,
                'to_currency_id' => $reverse->to_currency_id,
                'rate' => $reverse->rate,
                'effective_from' => $effectiveFrom,
                'effective_to' => now(),
                'updated_by' => $updatedBy,
            ]);
            $reverse->update([
                'from_currency_id' => $fromCurrencyId,
                'to_currency_id' => $toCurrencyId,
                'rate' => $rate,
                'effective_from' => now(),
            ]);

            return $reverse->fresh();
        }

        if ($existing) {
            $effectiveFrom = $existing->effective_from ?? $existing->created_at ?? now();
            CurrencyPairRateHistory::create([
                'from_currency_id' => $fromCurrencyId,
                'to_currency_id' => $toCurrencyId,
                'rate' => $existing->rate,
                'effective_from' => $effectiveFrom,
                'effective_to' => now(),
                'updated_by' => $updatedBy,
            ]);
        }

        return CurrencyPairRate::updateOrCreate(
            [
                'from_currency_id' => $fromCurrencyId,
                'to_currency_id' => $toCurrencyId,
            ],
            [
                'rate' => $rate,
                'effective_from' => now(),
            ]
        )->fresh();
    }

    /**
     * Update exchange rate for a currency (primary → currency). Pair table only.
     *
     * @param  int  $currencyId  Currency ID (the non-primary currency)
     * @param  float  $rate  New rate (1 primary = rate × currency)
     */
    public function updateRate(int $currencyId, float $rate, string $source = 'manual', ?string $updatedBy = null, ?string $notes = null): Currency
    {
        return DB::transaction(function () use ($currencyId, $rate, $updatedBy) {
            $currency = Currency::findOrFail($currencyId);

            if ($currency->isPrimary()) {
                if ($rate != 1.0000) {
                    throw new \InvalidArgumentException('Primary currency rate must always be 1.0000');
                }

                return $currency->fresh();
            }

            if ($rate <= 0) {
                throw new \InvalidArgumentException('Exchange rate must be greater than 0');
            }

            $primary = Currency::getPrimary();
            if (! $primary) {
                throw new \InvalidArgumentException('Primary currency not set');
            }

            $this->setPairRate($primary->id, $currencyId, $rate, $updatedBy);

            return $currency->fresh();
        });
    }

    /**
     * Get rate history for a currency: all pairs where this currency is "from" (e.g. USD→LBP, USD→EUR)
     * with history rows in the given date range.
     *
     * @return array{currency: Currency, pairs: array<int, array{to_currency: Currency, current_rate: float, history: \Illuminate\Support\Collection}>}
     */
    public function getPairRateHistory(int $currencyId, ?string $fromDate = null, ?string $toDate = null): array
    {
        $currency = Currency::findOrFail($currencyId);
        $from = $fromDate ? \Carbon\Carbon::parse($fromDate)->startOfDay() : null;
        $to = $toDate ? \Carbon\Carbon::parse($toDate)->endOfDay() : null;

        $pairs = CurrencyPairRate::where('from_currency_id', $currencyId)
            ->with('toCurrency')
            ->get();

        $result = [];
        foreach ($pairs as $pair) {
            $historyQuery = CurrencyPairRateHistory::select([
                'id', 'from_currency_id', 'to_currency_id', 'rate',
                'effective_from', 'effective_to', 'updated_by',
            ])
                ->where('from_currency_id', $pair->from_currency_id)
                ->where('to_currency_id', $pair->to_currency_id)
                ->orderBy('effective_from', 'asc');

            if ($from) {
                $historyQuery->where('effective_to', '>=', $from);
            }
            if ($to) {
                $historyQuery->where('effective_from', '<=', $to);
            }

            $history = $historyQuery->get()->map(fn ($row) => [
                'rate' => (float) $row->rate,
                'effective_from' => $row->effective_from->toIso8601String(),
                'effective_to' => $row->effective_to->toIso8601String(),
                'updated_by' => $row->getAttribute('updated_by'),
            ]);

            // Include current rate as latest period (effective_to = null)
            $pairEffectiveFrom = $pair->effective_from ?? $pair->created_at;
            if ($pairEffectiveFrom) {
                $effectiveFrom = $pairEffectiveFrom instanceof \Carbon\Carbon ? $pairEffectiveFrom : \Carbon\Carbon::parse($pairEffectiveFrom);
                $history->push([
                    'rate' => (float) $pair->rate,
                    'effective_from' => $effectiveFrom->toIso8601String(),
                    'effective_to' => null,
                    'updated_by' => null,
                ]);
            }

            $result[] = [
                'to_currency' => $pair->toCurrency,
                'current_rate' => (float) $pair->rate,
                'history' => $history->values(),
            ];
        }

        return [
            'currency' => $currency,
            'pairs' => $result,
        ];
    }

    /**
     * Bulk update rates (primary → each currency).
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
                    Log::error("Failed to update rate for currency {$currencyId}: ".$e->getMessage());
                }
            }
        });

        return $updated;
    }
}
