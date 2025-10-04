<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerOpeningBalance;
use App\Models\Supplier;
use App\Models\SupplierOpeningBalance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OpeningBalanceService
{
    /**
     * Set opening balance for a model (customer or supplier) in a specific currency
     */
    public function setOpeningBalance(Model $model, int $currencyId, float $amount, ?string $openingDate = null, ?string $notes = null)
    {
        if ($model instanceof Customer) {
            return $this->setCustomerOpeningBalance($model, $currencyId, $amount, $openingDate, $notes);
        } elseif ($model instanceof Supplier) {
            return $this->setSupplierOpeningBalance($model, $currencyId, $amount, $openingDate, $notes);
        }

        throw new \InvalidArgumentException('Model must be either Customer or Supplier');
    }

    /**
     * Set opening balance for a customer in a specific currency
     */
    public function setCustomerOpeningBalance(Customer $customer, int $currencyId, float $amount, ?string $openingDate = null, ?string $notes = null): CustomerOpeningBalance
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
     * Set opening balance for a supplier in a specific currency
     */
    public function setSupplierOpeningBalance(Supplier $supplier, int $currencyId, float $amount, ?string $openingDate = null, ?string $notes = null): SupplierOpeningBalance
    {
        $openingBalance = $supplier->getOpeningBalanceForCurrency($currencyId);

        if ($openingBalance) {
            $openingBalance->update([
                'opening_amount' => $amount,
                'opening_date' => $openingDate ?? now()->toDateString(),
                'notes' => $notes,
            ]);

            return $openingBalance;
        }

        return $supplier->openingBalances()->create([
            'currency_id' => $currencyId,
            'opening_amount' => $amount,
            'opening_date' => $openingDate ?? now()->toDateString(),
            'notes' => $notes,
        ]);
    }

    /**
     * Remove opening balance for a model in a specific currency
     */
    public function removeOpeningBalance(Model $model, int $currencyId): bool
    {
        if ($model instanceof Customer) {
            return $this->removeCustomerOpeningBalance($model, $currencyId);
        } elseif ($model instanceof Supplier) {
            return $this->removeSupplierOpeningBalance($model, $currencyId);
        }

        throw new \InvalidArgumentException('Model must be either Customer or Supplier');
    }

    /**
     * Remove opening balance for a customer in a specific currency
     */
    public function removeCustomerOpeningBalance(Customer $customer, int $currencyId): bool
    {
        $openingBalance = $customer->getOpeningBalanceForCurrency($currencyId);

        if ($openingBalance) {
            $openingBalance->update(['is_active' => false]);

            return true;
        }

        return false;
    }

    /**
     * Remove opening balance for a supplier in a specific currency
     */
    public function removeSupplierOpeningBalance(Supplier $supplier, int $currencyId): bool
    {
        $openingBalance = $supplier->getOpeningBalanceForCurrency($currencyId);

        if ($openingBalance) {
            $openingBalance->update(['is_active' => false]);

            return true;
        }

        return false;
    }

    /**
     * Get opening balance summary for a model
     */
    public function getOpeningBalanceSummary(Model $model): Collection|array
    {
        if ($model instanceof Customer) {
            return $this->getCustomerOpeningBalanceSummary($model);
        } elseif ($model instanceof Supplier) {
            return $this->getSupplierOpeningBalanceSummary($model);
        }

        throw new \InvalidArgumentException('Model must be either Customer or Supplier');
    }

    /**
     * Get opening balance summary for a customer
     */
    public function getCustomerOpeningBalanceSummary(Customer $customer): Collection
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
     * Get opening balance summary for a supplier
     */
    public function getSupplierOpeningBalanceSummary(Supplier $supplier): array
    {
        $openingBalances = $supplier->openingBalances()
            ->with('currency')
            ->active()
            ->get();

        $summary = [
            'total_currencies' => $openingBalances->count(),
            'currencies' => [],
            'total_amount' => 0,
        ];

        foreach ($openingBalances as $openingBalance) {
            $summary['currencies'][] = [
                'currency_id' => $openingBalance->currency_id,
                'currency_code' => $openingBalance->currency->code,
                'currency_name' => $openingBalance->currency->name,
                'opening_amount' => $openingBalance->opening_amount,
                'opening_date' => $openingBalance->opening_date,
                'notes' => $openingBalance->notes,
            ];

            $summary['total_amount'] += $openingBalance->opening_amount;
        }

        return $summary;
    }

    /**
     * Get available currencies for opening balances
     */
    public function getAvailableCurrencies(Model $model): array
    {
        if ($model instanceof Customer) {
            return $this->getCustomerAvailableCurrencies($model);
        } elseif ($model instanceof Supplier) {
            return $this->getSupplierAvailableCurrencies($model);
        }

        throw new \InvalidArgumentException('Model must be either Customer or Supplier');
    }

    /**
     * Get available currencies for a customer
     */
    public function getCustomerAvailableCurrencies(Customer $customer): array
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
     * Get available currencies for a supplier
     */
    public function getSupplierAvailableCurrencies(Supplier $supplier): array
    {
        $allCurrencies = Currency::active()->get();
        $usedCurrencyIds = $supplier->getOpeningCurrencyIds();

        return [
            'available_currencies' => $allCurrencies->whereNotIn('id', $usedCurrencyIds),
            'used_currencies' => $supplier->openingBalances()->with('currency')->active()->get(),
            'used_currency_ids' => $usedCurrencyIds,
        ];
    }

    /**
     * Bulk update opening balances
     */
    public function bulkUpdateOpeningBalances(Model $model, array $openingBalances): Collection|array
    {
        if ($model instanceof Customer) {
            return $this->bulkUpdateCustomerOpeningBalances($model, $openingBalances);
        } elseif ($model instanceof Supplier) {
            return $this->bulkUpdateSupplierOpeningBalances($model, $openingBalances);
        }

        throw new \InvalidArgumentException('Model must be either Customer or Supplier');
    }

    /**
     * Bulk update opening balances for a customer
     */
    public function bulkUpdateCustomerOpeningBalances(Customer $customer, array $openingBalances): Collection
    {
        return DB::transaction(function () use ($customer, $openingBalances) {
            foreach ($openingBalances as $balanceData) {
                $this->setCustomerOpeningBalance(
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
     * Bulk update opening balances for a supplier
     */
    public function bulkUpdateSupplierOpeningBalances(Supplier $supplier, array $openingBalances): array
    {
        DB::beginTransaction();

        try {
            $updatedBalances = [];

            foreach ($openingBalances as $openingBalanceData) {
                $openingBalance = $this->setSupplierOpeningBalance(
                    $supplier,
                    $openingBalanceData['currency_id'],
                    $openingBalanceData['opening_amount'],
                    $openingBalanceData['opening_date'] ?? null,
                    $openingBalanceData['notes'] ?? null
                );

                $openingBalance->load('currency');
                $updatedBalances[] = $openingBalance;
            }

            DB::commit();

            return $updatedBalances;

        } catch (\Exception $e) {
            DB::rollBack();

            throw $e;
        }
    }

    /**
     * Get opening balance statistics
     */
    public function getOpeningBalanceStatistics(Model $model): array
    {
        if ($model instanceof Customer) {
            return $this->getCustomerOpeningBalanceStatistics($model);
        } elseif ($model instanceof Supplier) {
            return $this->getSupplierOpeningBalanceStatistics($model);
        }

        throw new \InvalidArgumentException('Model must be either Customer or Supplier');
    }

    /**
     * Get opening balance statistics for a customer
     */
    public function getCustomerOpeningBalanceStatistics(Customer $customer): array
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
     * Get opening balance statistics for a supplier
     */
    public function getSupplierOpeningBalanceStatistics(Supplier $supplier): array
    {
        $openingBalances = $supplier->openingBalances()
            ->with('currency')
            ->active()
            ->get();

        return [
            'total_currencies' => $openingBalances->count(),
            'total_opening_amount' => $openingBalances->sum('opening_amount'),
            'positive_balances' => $openingBalances->where('opening_amount', '>', 0)->count(),
            'zero_balances' => $openingBalances->where('opening_amount', 0)->count(),
            'negative_balances' => $openingBalances->where('opening_amount', '<', 0)->count(),
            'by_currency' => $openingBalances->map(function ($balance) {
                return [
                    'currency' => $balance->currency,
                    'opening_amount' => $balance->opening_amount,
                    'opening_date' => $balance->opening_date,
                    'notes' => $balance->notes,
                ];
            }),
        ];
    }

    /**
     * Validate if a currency can be used for credit/cheque limits
     */
    public function validateCurrencyForLimits(Model $model, int $currencyId): bool
    {
        if ($model instanceof Customer) {
            return $model->hasOpeningBalanceForCurrency($currencyId);
        } elseif ($model instanceof Supplier) {
            return $model->hasOpeningBalanceForCurrency($currencyId);
        }

        throw new \InvalidArgumentException('Model must be either Customer or Supplier');
    }

    /**
     * Get currencies available for credit limits
     */
    public function getCurrenciesForCreditLimits(Model $model): Collection
    {
        if ($model instanceof Customer) {
            return $model->getAvailableCurrenciesForCreditLimits();
        } elseif ($model instanceof Supplier) {
            // Suppliers don't have credit limits in the current implementation
            return collect([]);
        }

        throw new \InvalidArgumentException('Model must be either Customer or Supplier');
    }

    /**
     * Get currencies available for cheque limits
     */
    public function getCurrenciesForChequeLimits(Model $model): Collection
    {
        if ($model instanceof Customer) {
            return $model->getAvailableCurrenciesForChequeLimits();
        } elseif ($model instanceof Supplier) {
            // Suppliers don't have cheque limits in the current implementation
            return collect([]);
        }

        throw new \InvalidArgumentException('Model must be either Customer or Supplier');
    }

    /**
     * Check if model has any opening balances
     */
    public function hasOpeningBalances(Model $model): bool
    {
        if ($model instanceof Customer) {
            return $model->hasAnyOpeningBalances();
        } elseif ($model instanceof Supplier) {
            return $model->openingBalances()->active()->exists();
        }

        throw new \InvalidArgumentException('Model must be either Customer or Supplier');
    }

    /**
     * Get total opening balance for a specific currency
     */
    public function getTotalOpeningBalance(Model $model, ?int $currencyId = null): float
    {
        if ($model instanceof Customer) {
            return $model->getTotalOpeningBalance($currencyId);
        } elseif ($model instanceof Supplier) {
            return $model->getTotalOpeningBalance($currencyId);
        }

        throw new \InvalidArgumentException('Model must be either Customer or Supplier');
    }

    /**
     * Get opening currencies for a model
     */
    public function getOpeningCurrencies(Model $model): Collection
    {
        if ($model instanceof Customer) {
            return $model->getOpeningCurrencies();
        } elseif ($model instanceof Supplier) {
            return $model->openingBalances()->with('currency')->active()->get();
        }

        throw new \InvalidArgumentException('Model must be either Customer or Supplier');
    }

    /**
     * Get opening currency IDs for a model
     */
    public function getOpeningCurrencyIds(Model $model): array
    {
        if ($model instanceof Customer) {
            return $model->getOpeningCurrencyIds();
        } elseif ($model instanceof Supplier) {
            return $model->getOpeningCurrencyIds();
        }

        throw new \InvalidArgumentException('Model must be either Customer or Supplier');
    }

    /**
     * Check if opening balance exists for a currency
     */
    public function hasOpeningBalanceForCurrency(Model $model, int $currencyId): bool
    {
        if ($model instanceof Customer) {
            return $model->hasOpeningBalanceForCurrency($currencyId);
        } elseif ($model instanceof Supplier) {
            return $model->hasOpeningBalanceForCurrency($currencyId);
        }

        throw new \InvalidArgumentException('Model must be either Customer or Supplier');
    }

    /**
     * Get opening balance for a specific currency
     */
    public function getOpeningBalanceForCurrency(Model $model, int $currencyId)
    {
        if ($model instanceof Customer) {
            return $model->getOpeningBalanceForCurrency($currencyId);
        } elseif ($model instanceof Supplier) {
            return $model->getOpeningBalanceForCurrency($currencyId);
        }

        throw new \InvalidArgumentException('Model must be either Customer or Supplier');
    }

    /**
     * Validate opening balance data
     */
    public function validateOpeningBalanceData(array $data): array
    {
        $errors = [];

        foreach ($data as $index => $openingBalance) {
            if (! isset($openingBalance['currency_id'])) {
                $errors["opening_balances.{$index}.currency_id"] = ['Currency ID is required'];
            }

            if (! isset($openingBalance['opening_amount'])) {
                $errors["opening_balances.{$index}.opening_amount"] = ['Opening amount is required'];
            } elseif (! is_numeric($openingBalance['opening_amount']) || $openingBalance['opening_amount'] < 0) {
                $errors["opening_balances.{$index}.opening_amount"] = ['Opening amount must be a positive number'];
            }

            if (isset($openingBalance['opening_date']) && ! strtotime($openingBalance['opening_date'])) {
                $errors["opening_balances.{$index}.opening_date"] = ['Opening date must be a valid date'];
            }
        }

        return $errors;
    }
}
