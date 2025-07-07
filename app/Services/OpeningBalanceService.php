<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerOpeningBalance;
use App\Models\Currency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

class OpeningBalanceService
{
    /**
     * Set opening balance for a customer in a specific currency
     */
    public function setOpeningBalance(Customer $customer, int $currencyId, float $amount, string $openingDate = null, string $notes = null): CustomerOpeningBalance
    {
        return DB::transaction(function () use ($customer, $currencyId, $amount, $openingDate, $notes) {
            $openingBalance = $customer->getOpeningBalanceForCurrency($currencyId);

            if ($openingBalance) {
                $openingBalance->update([
                    'opening_amount' => $amount,
                    'opening_date' => $openingDate ?? now()->toDateString(),
                    'notes' => $notes,
                ]);
                return $openingBalance;
            } else {
                return $customer->openingBalances()->create([
                    'currency_id' => $currencyId,
                    'opening_amount' => $amount,
                    'opening_date' => $openingDate ?? now()->toDateString(),
                    'notes' => $notes,
                    'is_active' => true,
                ]);
            }
        });
    }

    /**
     * Remove opening balance for a customer in a specific currency
     */
    public function removeOpeningBalance(Customer $customer, int $currencyId): bool
    {
        $openingBalance = $customer->getOpeningBalanceForCurrency($currencyId);

        if ($openingBalance) {
            $openingBalance->update(['is_active' => false]);
            return true;
        }

        return false;
    }

    /**
     * Get opening balance summary for a customer
     */
    public function getOpeningBalanceSummary(Customer $customer): Collection
    {
        return $customer->openingBalances()
            ->with('currency')
            ->active()
            ->get()
            ->map(function ($openingBalance) {
                return [
                    'currency' => $openingBalance->currency,
                    'opening_amount' => $openingBalance->opening_amount,
                    'opening_date' => $openingBalance->opening_date,
                    'notes' => $openingBalance->notes,
                    'balance_type' => $openingBalance->getBalanceType(),
                    'is_positive' => $openingBalance->isPositive(),
                    'is_negative' => $openingBalance->isNegative(),
                    'is_zero' => $openingBalance->isZero(),
                ];
            });
    }

    /**
     * Get available currencies for opening balances
     */
    public function getAvailableCurrencies(Customer $customer): array
    {
        $allCurrencies = Currency::active()->get();
        $usedCurrencyIds = $customer->getOpeningCurrencyIds();

        return [
            'available_currencies' => $allCurrencies->whereNotIn('id', $usedCurrencyIds),
            'used_currencies' => $customer->getOpeningCurrencies(),
            'used_currency_ids' => $usedCurrencyIds,
        ];
    }

    /**
     * Bulk update opening balances
     */
    public function bulkUpdateOpeningBalances(Customer $customer, array $openingBalances): Collection
    {
        return DB::transaction(function () use ($customer, $openingBalances) {
            foreach ($openingBalances as $balanceData) {
                $this->setOpeningBalance(
                    $customer,
                    $balanceData['currency_id'],
                    $balanceData['opening_amount'],
                    $balanceData['opening_date'] ?? null,
                    $balanceData['notes'] ?? null
                );
            }

            return $customer->openingBalances()
                ->with('currency')
                ->active()
                ->get();
        });
    }

    /**
     * Get opening balance statistics
     */
    public function getOpeningBalanceStatistics(Customer $customer): array
    {
        $openingBalances = $customer->openingBalances()
            ->with('currency')
            ->active()
            ->get();

        return [
            'total_currencies' => $openingBalances->count(),
            'total_opening_amount' => $openingBalances->sum('opening_amount'),
            'positive_balances' => $openingBalances->where('opening_amount', '>', 0)->count(),
            'zero_balances' => $openingBalances->where('opening_amount', 0)->count(),
            'negative_balances' => $openingBalances->where('opening_amount', '<', 0)->count(),
            'currencies_with_credit_limits' => $customer->creditLimits()->active()->count(),
            'currencies_with_cheque_limits' => $customer->chequeLimits()->active()->count(),
            'by_currency' => $openingBalances->map(function ($balance) use ($customer) {
                return [
                    'currency' => $balance->currency,
                    'opening_amount' => $balance->opening_amount,
                    'opening_date' => $balance->opening_date,
                    'has_credit_limit' => $customer->hasCreditLimitForCurrency($balance->currency_id),
                    'has_cheque_limit' => $customer->hasChequeLimitForCurrency($balance->currency_id),
                    'balance_type' => $balance->getBalanceType(),
                ];
            }),
        ];
    }

    /**
     * Validate if a currency can be used for credit/cheque limits
     */
    public function validateCurrencyForLimits(Customer $customer, int $currencyId): bool
    {
        return $customer->hasOpeningBalanceForCurrency($currencyId);
    }

    /**
     * Get currencies available for credit limits
     */
    public function getCurrenciesForCreditLimits(Customer $customer): Collection
    {
        return $customer->getAvailableCurrenciesForCreditLimits();
    }

    /**
     * Get currencies available for cheque limits
     */
    public function getCurrenciesForChequeLimits(Customer $customer): Collection
    {
        return $customer->getAvailableCurrenciesForChequeLimits();
    }

    /**
     * Check if customer has any opening balances
     */
    public function hasOpeningBalances(Customer $customer): bool
    {
        return $customer->hasAnyOpeningBalances();
    }

    /**
     * Get total opening balance for a specific currency
     */
    public function getTotalOpeningBalance(Customer $customer, int $currencyId = null): float
    {
        return $customer->getTotalOpeningBalance($currencyId);
    }

    /**
     * Get opening currencies for a customer
     */
    public function getOpeningCurrencies(Customer $customer): Collection
    {
        return $customer->getOpeningCurrencies();
    }

    /**
     * Get opening currency IDs for a customer
     */
    public function getOpeningCurrencyIds(Customer $customer): array
    {
        return $customer->getOpeningCurrencyIds();
    }

    /**
     * Check if opening balance exists for a currency
     */
    public function hasOpeningBalanceForCurrency(Customer $customer, int $currencyId): bool
    {
        return $customer->hasOpeningBalanceForCurrency($currencyId);
    }

    /**
     * Get opening balance for a specific currency
     */
    public function getOpeningBalanceForCurrency(Customer $customer, int $currencyId): ?CustomerOpeningBalance
    {
        return $customer->getOpeningBalanceForCurrency($currencyId);
    }
}
