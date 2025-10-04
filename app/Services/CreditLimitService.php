<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerCreditLimit;
use Exception;
use Illuminate\Support\Facades\DB;

class CreditLimitService
{
    /**
     * Check if a customer can make a purchase with the given amount and currency
     */
    public function canMakePurchase(Customer $customer, float $amount, int $currencyId): array
    {
        if (! $customer->allow_credit) {
            return [
                'can_purchase' => false,
                'reason' => 'Customer does not have credit enabled',
                'available_credit' => 0,
                'required_amount' => $amount,
            ];
        }

        $creditLimit = $customer->getCreditLimitForCurrency($currencyId);

        if (! $creditLimit) {
            return [
                'can_purchase' => false,
                'reason' => 'No credit limit set for this currency',
                'available_credit' => 0,
                'required_amount' => $amount,
            ];
        }

        $canPurchase = $creditLimit->hasAvailableCredit($amount);

        return [
            'can_purchase' => $canPurchase,
            'reason' => $canPurchase ? 'Sufficient credit available' : 'Insufficient credit',
            'available_credit' => $creditLimit->available_credit,
            'required_amount' => $amount,
            'credit_limit' => $creditLimit->credit_limit,
            'used_credit' => $creditLimit->used_credit,
            'utilization_percentage' => $creditLimit->getUtilizationPercentage(),
        ];
    }

    /**
     * Reserve credit for a purchase
     */
    public function reserveCredit(Customer $customer, float $amount, int $currencyId): bool
    {
        $check = $this->canMakePurchase($customer, $amount, $currencyId);

        if (! $check['can_purchase']) {
            throw new Exception($check['reason']);
        }

        DB::transaction(function () use ($customer, $amount, $currencyId) {
            $customer->increaseUsedCredit($currencyId, $amount);
        });

        return true;
    }

    /**
     * Release reserved credit (e.g., when order is cancelled)
     */
    public function releaseCredit(Customer $customer, float $amount, int $currencyId): bool
    {
        DB::transaction(function () use ($customer, $amount, $currencyId) {
            $customer->decreaseUsedCredit($currencyId, $amount);
        });

        return true;
    }

    /**
     * Set credit limit for a customer in a specific currency
     */
    public function setCreditLimit(Customer $customer, int $currencyId, float $amount, ?string $notes = null): CustomerCreditLimit
    {
        // Validate currency exists
        $currency = Currency::findOrFail($currencyId);

        // Validate amount is positive
        if ($amount < 0) {
            throw new Exception('Credit limit cannot be negative');
        }

        return DB::transaction(function () use ($customer, $currencyId, $amount, $notes) {
            $creditLimit = $customer->getCreditLimitForCurrency($currencyId);

            if ($creditLimit) {
                // Check if reducing credit limit would cause issues
                if ($amount < $creditLimit->used_credit) {
                    throw new Exception('Cannot reduce credit limit below used credit amount');
                }

                $creditLimit->update([
                    'credit_limit' => $amount,
                    'notes' => $notes,
                ]);

                return $creditLimit->fresh();
            } else {
                return $customer->creditLimits()->create([
                    'currency_id' => $currencyId,
                    'credit_limit' => $amount,
                    'used_credit' => 0,
                    'available_credit' => $amount,
                    'notes' => $notes,
                    'is_active' => true,
                ]);
            }
        });
    }

    /**
     * Get comprehensive credit summary for a customer
     */
    public function getCreditSummary(Customer $customer): array
    {
        $creditLimits = $customer->creditLimits()
            ->with('currency')
            ->active()
            ->get();

        $summary = [];
        $totalCreditLimit = 0;
        $totalUsedCredit = 0;
        $totalAvailableCredit = 0;

        foreach ($creditLimits as $creditLimit) {
            $summary[] = [
                'currency' => $creditLimit->currency,
                'credit_limit' => $creditLimit->credit_limit,
                'used_credit' => $creditLimit->used_credit,
                'available_credit' => $creditLimit->available_credit,
                'utilization_percentage' => $creditLimit->getUtilizationPercentage(),
                'is_over_limit' => $creditLimit->used_credit > $creditLimit->credit_limit,
                'status' => $this->getCreditStatus($creditLimit),
            ];

            $totalCreditLimit += $creditLimit->credit_limit;
            $totalUsedCredit += $creditLimit->used_credit;
            $totalAvailableCredit += $creditLimit->available_credit;
        }

        return [
            'customer_id' => $customer->id,
            'customer_name' => $customer->display_name,
            'allow_credit' => $customer->allow_credit,
            'credit_limits' => $summary,
            'totals' => [
                'total_credit_limit' => $totalCreditLimit,
                'total_used_credit' => $totalUsedCredit,
                'total_available_credit' => $totalAvailableCredit,
                'overall_utilization_percentage' => $totalCreditLimit > 0 ? ($totalUsedCredit / $totalCreditLimit) * 100 : 0,
            ],
            'has_any_limits' => $customer->hasAnyCreditLimits(),
        ];
    }

    /**
     * Get credit status based on utilization
     */
    private function getCreditStatus(CustomerCreditLimit $creditLimit): string
    {
        $percentage = $creditLimit->getUtilizationPercentage();

        if ($percentage >= 100) {
            return 'over_limit';
        } elseif ($percentage >= 80) {
            return 'high_utilization';
        } elseif ($percentage >= 50) {
            return 'moderate_utilization';
        } else {
            return 'low_utilization';
        }
    }

    /**
     * Validate credit limit data
     */
    public function validateCreditLimitData(array $data): array
    {
        $errors = [];

        if (! isset($data['currency_id']) || ! Currency::find($data['currency_id'])) {
            $errors[] = 'Invalid currency selected';
        }

        if (! isset($data['credit_limit']) || $data['credit_limit'] < 0) {
            $errors[] = 'Credit limit must be a positive number';
        }

        return $errors;
    }

    /**
     * Get customers with credit limit issues
     */
    public function getCustomersWithCreditIssues(): array
    {
        $customers = Customer::where('allow_credit', true)
            ->whereHas('creditLimits', function ($query) {
                $query->where('used_credit', '>', 'credit_limit')
                    ->where('is_active', true);
            })
            ->with(['creditLimits' => function ($query) {
                $query->where('used_credit', '>', 'credit_limit')
                    ->where('is_active', true)
                    ->with('currency');
            }])
            ->get();

        return $customers->map(function ($customer) {
            return [
                'customer' => $customer,
                'over_limit_currencies' => $customer->creditLimits->map(function ($creditLimit) {
                    return [
                        'currency' => $creditLimit->currency,
                        'credit_limit' => $creditLimit->credit_limit,
                        'used_credit' => $creditLimit->used_credit,
                        'excess_amount' => $creditLimit->used_credit - $creditLimit->credit_limit,
                    ];
                }),
            ];
        })->toArray();
    }
}
