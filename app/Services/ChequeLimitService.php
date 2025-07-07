<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerChequeLimit;
use App\Models\Currency;
use Illuminate\Support\Facades\DB;
use Exception;

class ChequeLimitService
{
    /**
     * Check if a customer can accept cheques for the given currency and count
     */
    public function canAcceptCheques(Customer $customer, int $count, int $currencyId): array
    {
        if (!$customer->accept_cheque) {
            return [
                'can_accept' => false,
                'reason' => 'Customer does not accept cheques',
                'available_cheques' => 0,
                'requested_count' => $count,
            ];
        }

        $chequeLimit = $customer->getChequeLimitForCurrency($currencyId);

        if (!$chequeLimit) {
            return [
                'can_accept' => false,
                'reason' => 'No cheque limit set for this currency',
                'available_cheques' => 0,
                'requested_count' => $count,
            ];
        }

        $canAccept = $chequeLimit->hasAvailableCheques($count);

        return [
            'can_accept' => $canAccept,
            'reason' => $canAccept ? 'Sufficient cheque limit available' : 'Insufficient cheque limit',
            'available_cheques' => $chequeLimit->available_cheques,
            'requested_count' => $count,
            'max_cheques' => $chequeLimit->max_cheques,
            'used_cheques' => $chequeLimit->used_cheques,
            'utilization_percentage' => $chequeLimit->getUtilizationPercentage(),
        ];
    }

    /**
     * Reserve cheques for a transaction
     */
    public function reserveCheques(Customer $customer, int $count, int $currencyId): bool
    {
        $check = $this->canAcceptCheques($customer, $count, $currencyId);

        if (!$check['can_accept']) {
            throw new Exception($check['reason']);
        }

        DB::transaction(function () use ($customer, $count, $currencyId) {
            $customer->increaseUsedCheques($currencyId, $count);
        });

        return true;
    }

    /**
     * Release reserved cheques (e.g., when transaction is cancelled)
     */
    public function releaseCheques(Customer $customer, int $count, int $currencyId): bool
    {
        DB::transaction(function () use ($customer, $count, $currencyId) {
            $customer->decreaseUsedCheques($currencyId, $count);
        });

        return true;
    }

    /**
     * Set cheque limit for a customer in a specific currency
     */
    public function setChequeLimit(Customer $customer, int $currencyId, int $maxCheques, string $notes = null): CustomerChequeLimit
    {
        // Validate currency exists
        $currency = Currency::findOrFail($currencyId);

        // Validate max cheques is non-negative
        if ($maxCheques < 0) {
            throw new Exception('Max cheques cannot be negative');
        }

        return DB::transaction(function () use ($customer, $currencyId, $maxCheques, $notes) {
            $chequeLimit = $customer->getChequeLimitForCurrency($currencyId);

            if ($chequeLimit) {
                // Check if reducing max cheques would cause issues
                if ($maxCheques < $chequeLimit->used_cheques) {
                    throw new Exception('Cannot reduce max cheques below used cheques count');
                }

                $chequeLimit->update([
                    'max_cheques' => $maxCheques,
                    'notes' => $notes,
                ]);

                return $chequeLimit->fresh();
            } else {
                return $customer->chequeLimits()->create([
                    'currency_id' => $currencyId,
                    'max_cheques' => $maxCheques,
                    'used_cheques' => 0,
                    'available_cheques' => $maxCheques,
                    'notes' => $notes,
                    'is_active' => true,
                ]);
            }
        });
    }

    /**
     * Get comprehensive cheque summary for a customer
     */
    public function getChequeSummary(Customer $customer): array
    {
        $chequeLimits = $customer->chequeLimits()
            ->with('currency')
            ->active()
            ->get();

        $summary = [];
        $totalMaxCheques = 0;
        $totalUsedCheques = 0;
        $totalAvailableCheques = 0;

        foreach ($chequeLimits as $chequeLimit) {
            $summary[] = [
                'currency' => $chequeLimit->currency,
                'max_cheques' => $chequeLimit->max_cheques,
                'used_cheques' => $chequeLimit->used_cheques,
                'available_cheques' => $chequeLimit->available_cheques,
                'utilization_percentage' => $chequeLimit->getUtilizationPercentage(),
                'is_over_limit' => $chequeLimit->isOverLimit(),
                'status' => $this->getChequeStatus($chequeLimit),
                'remaining_cheques' => $chequeLimit->getRemainingCheques(),
            ];

            $totalMaxCheques += $chequeLimit->max_cheques;
            $totalUsedCheques += $chequeLimit->used_cheques;
            $totalAvailableCheques += $chequeLimit->available_cheques;
        }

        return [
            'customer_id' => $customer->id,
            'customer_name' => $customer->display_name,
            'accept_cheque' => $customer->accept_cheque,
            'cheque_limits' => $summary,
            'totals' => [
                'total_max_cheques' => $totalMaxCheques,
                'total_used_cheques' => $totalUsedCheques,
                'total_available_cheques' => $totalAvailableCheques,
                'overall_utilization_percentage' => $totalMaxCheques > 0 ? ($totalUsedCheques / $totalMaxCheques) * 100 : 0,
            ],
            'has_any_limits' => $customer->hasAnyChequeLimits(),
        ];
    }

    /**
     * Get cheque status based on utilization
     */
    private function getChequeStatus(CustomerChequeLimit $chequeLimit): string
    {
        $percentage = $chequeLimit->getUtilizationPercentage();

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
     * Validate cheque limit data
     */
    public function validateChequeLimitData(array $data): array
    {
        $errors = [];

        if (!isset($data['currency_id']) || !Currency::find($data['currency_id'])) {
            $errors[] = 'Invalid currency selected';
        }

        if (!isset($data['max_cheques']) || $data['max_cheques'] < 0) {
            $errors[] = 'Max cheques must be a non-negative number';
        }

        return $errors;
    }

    /**
     * Get customers with cheque limit issues
     */
    public function getCustomersWithChequeIssues(): array
    {
        $customers = Customer::where('accept_cheque', true)
            ->whereHas('chequeLimits', function ($query) {
                $query->where('used_cheques', '>', 'max_cheques')
                      ->where('is_active', true);
            })
            ->with(['chequeLimits' => function ($query) {
                $query->where('used_cheques', '>', 'max_cheques')
                      ->where('is_active', true)
                      ->with('currency');
            }])
            ->get();

        return $customers->map(function ($customer) {
            return [
                'customer' => $customer,
                'over_limit_currencies' => $customer->chequeLimits->map(function ($chequeLimit) {
                    return [
                        'currency' => $chequeLimit->currency,
                        'max_cheques' => $chequeLimit->max_cheques,
                        'used_cheques' => $chequeLimit->used_cheques,
                        'excess_cheques' => $chequeLimit->used_cheques - $chequeLimit->max_cheques,
                    ];
                }),
            ];
        })->toArray();
    }

    /**
     * Process cheque payment and update limits
     */
    public function processChequePayment(Customer $customer, int $currencyId, int $chequeCount = 1): array
    {
        $check = $this->canAcceptCheques($customer, $chequeCount, $currencyId);

        if (!$check['can_accept']) {
            return [
                'success' => false,
                'message' => $check['reason'],
                'data' => $check,
            ];
        }

        try {
            DB::transaction(function () use ($customer, $currencyId, $chequeCount) {
                $customer->increaseUsedCheques($currencyId, $chequeCount);
            });

            return [
                'success' => true,
                'message' => 'Cheque payment processed successfully',
                'data' => [
                    'processed_cheques' => $chequeCount,
                    'currency_id' => $currencyId,
                    'remaining_available' => $check['available_cheques'] - $chequeCount,
                ],
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to process cheque payment: ' . $e->getMessage(),
                'data' => null,
            ];
        }
    }

    /**
     * Get customers approaching cheque limits (80%+ utilization)
     */
    public function getCustomersApproachingChequeLimits(): array
    {
        $customers = Customer::where('accept_cheque', true)
            ->whereHas('chequeLimits', function ($query) {
                $query->whereRaw('(used_cheques * 100.0 / max_cheques) >= 80')
                      ->where('is_active', true)
                      ->where('max_cheques', '>', 0);
            })
            ->with(['chequeLimits' => function ($query) {
                $query->whereRaw('(used_cheques * 100.0 / max_cheques) >= 80')
                      ->where('is_active', true)
                      ->where('max_cheques', '>', 0)
                      ->with('currency');
            }])
            ->get();

        return $customers->map(function ($customer) {
            return [
                'customer' => $customer,
                'high_utilization_currencies' => $customer->chequeLimits->map(function ($chequeLimit) {
                    return [
                        'currency' => $chequeLimit->currency,
                        'max_cheques' => $chequeLimit->max_cheques,
                        'used_cheques' => $chequeLimit->used_cheques,
                        'utilization_percentage' => $chequeLimit->getUtilizationPercentage(),
                        'remaining_cheques' => $chequeLimit->getRemainingCheques(),
                    ];
                }),
            ];
        })->toArray();
    }
}
