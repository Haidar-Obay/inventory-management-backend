<?php

namespace App\Services;

use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Exception;

class TaxService
{
    /**
     * Check if tax should be applied to a customer
     */
    public function shouldApplyTax(Customer $customer): bool
    {
        return $customer->shouldApplyTax();
    }

    /**
     * Get comprehensive tax information for a customer
     */
    public function getTaxInfo(Customer $customer): array
    {
        return $customer->getTaxInfo();
    }

    /**
     * Set tax exemption for a customer
     */
    public function setTaxExemption(Customer $customer, string $exemptionFrom, string $exemptionReference, ?string $fromDate = null, ?string $tillDate = null): bool
    {
        try {
            DB::transaction(function () use ($customer, $exemptionFrom, $exemptionReference, $fromDate, $tillDate) {
                $customer->setTaxExemption($exemptionFrom, $exemptionReference, $fromDate, $tillDate);
            });

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Remove tax exemption from a customer
     */
    public function removeTaxExemption(Customer $customer): bool
    {
        try {
            DB::transaction(function () use ($customer) {
                $customer->removeTaxExemption();
            });

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Update tax number for a customer
     */
    public function updateTaxNumber(Customer $customer, string $taxNumber): bool
    {
        try {
            DB::transaction(function () use ($customer, $taxNumber) {
                $customer->updateTaxNumber($taxNumber);
            });

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Set taxable status for a customer
     */
    public function setTaxableStatus(Customer $customer, bool $taxable): bool
    {
        try {
            DB::transaction(function () use ($customer, $taxable) {
                $customer->setTaxable($taxable);
            });

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Get customers with expiring tax exemptions
     */
    public function getCustomersWithExpiringExemptions(int $daysThreshold = 30): array
    {
        $customers = Customer::where('is_exempted', true)
            ->whereNotNull('exempted_till_date')
            ->where('exempted_till_date', '>=', now()->toDateString())
            ->where('exempted_till_date', '<=', now()->addDays($daysThreshold)->toDateString())
            ->get();

        return $customers->map(function ($customer) {
            return [
                'customer' => $customer,
                'exemption_info' => [
                    'exemption_from' => $customer->exemption_from,
                    'exemption_reference' => $customer->exemption_reference,
                    'exempted_from_date' => $customer->exempted_from_date,
                    'exempted_till_date' => $customer->exempted_till_date,
                    'days_remaining' => $customer->getExemptionDaysRemaining(),
                    'exemption_status' => $customer->getExemptionStatus(),
                ],
            ];
        })->toArray();
    }

    /**
     * Get customers with expired tax exemptions
     */
    public function getCustomersWithExpiredExemptions(): array
    {
        $customers = Customer::where('is_exempted', true)
            ->whereNotNull('exempted_till_date')
            ->where('exempted_till_date', '<', now()->toDateString())
            ->get();

        return $customers->map(function ($customer) {
            return [
                'customer' => $customer,
                'exemption_info' => [
                    'exemption_from' => $customer->exemption_from,
                    'exemption_reference' => $customer->exemption_reference,
                    'exempted_from_date' => $customer->exempted_from_date,
                    'exempted_till_date' => $customer->exempted_till_date,
                    'days_expired' => abs($customer->getExemptionDaysRemaining()),
                    'exemption_status' => $customer->getExemptionStatus(),
                ],
            ];
        })->toArray();
    }

    /**
     * Get customers currently exempted from tax
     */
    public function getCurrentlyExemptedCustomers(): array
    {
        $customers = Customer::where('is_exempted', true)
            ->get()
            ->filter(function ($customer) {
                return $customer->isCurrentlyExempted();
            });

        return $customers->map(function ($customer) {
            return [
                'customer' => $customer,
                'exemption_info' => [
                    'exemption_from' => $customer->exemption_from,
                    'exemption_reference' => $customer->exemption_reference,
                    'exempted_from_date' => $customer->exempted_from_date,
                    'exempted_till_date' => $customer->exempted_till_date,
                    'days_remaining' => $customer->getExemptionDaysRemaining(),
                    'exemption_status' => $customer->getExemptionStatus(),
                ],
            ];
        })->toArray();
    }

    /**
     * Get customers with tax numbers
     */
    public function getCustomersWithTaxNumbers(): array
    {
        $customers = Customer::whereNotNull('tax_number')
            ->where('tax_number', '!=', '')
            ->get();

        return $customers->map(function ($customer) {
            return [
                'customer' => $customer,
                'tax_info' => [
                    'tax_number' => $customer->tax_number,
                    'taxable' => $customer->taxable,
                    'is_exempted' => $customer->is_exempted,
                    'should_apply_tax' => $customer->shouldApplyTax(),
                ],
            ];
        })->toArray();
    }

    /**
     * Validate tax exemption data
     */
    public function validateTaxExemptionData(array $data): array
    {
        $errors = [];

        if (empty($data['exemption_from'])) {
            $errors[] = 'Exemption reason is required';
        }

        if (empty($data['exemption_reference'])) {
            $errors[] = 'Exemption reference is required';
        }

        if (isset($data['exempted_from_date']) && isset($data['exempted_till_date'])) {
            if ($data['exempted_from_date'] > $data['exempted_till_date']) {
                $errors[] = 'Exemption start date cannot be after end date';
            }
        }

        return $errors;
    }

    /**
     * Calculate tax amount for a transaction
     */
    public function calculateTaxAmount(Customer $customer, float $amount, float $taxRate = 0.0): array
    {
        $shouldApplyTax = $this->shouldApplyTax($customer);

        if (!$shouldApplyTax) {
            return [
                'should_apply_tax' => false,
                'tax_amount' => 0,
                'total_amount' => $amount,
                'reason' => $customer->isCurrentlyExempted() ? 'Customer is tax exempted' : 'Customer is not taxable',
            ];
        }

        $taxAmount = $amount * ($taxRate / 100);
        $totalAmount = $amount + $taxAmount;

        return [
            'should_apply_tax' => true,
            'tax_amount' => $taxAmount,
            'total_amount' => $totalAmount,
            'tax_rate' => $taxRate,
            'base_amount' => $amount,
        ];
    }

    /**
     * Get tax summary for reporting
     */
    public function getTaxSummary(): array
    {
        $totalCustomers = Customer::count();
        $taxableCustomers = Customer::where('taxable', true)->count();
        $exemptedCustomers = Customer::where('is_exempted', true)->count();
        $currentlyExemptedCustomers = Customer::where('is_exempted', true)
            ->get()
            ->filter(function ($customer) {
                return $customer->isCurrentlyExempted();
            })
            ->count();
        $customersWithTaxNumbers = Customer::whereNotNull('tax_number')
            ->where('tax_number', '!=', '')
            ->count();

        return [
            'total_customers' => $totalCustomers,
            'taxable_customers' => $taxableCustomers,
            'exempted_customers' => $exemptedCustomers,
            'currently_exempted_customers' => $currentlyExemptedCustomers,
            'customers_with_tax_numbers' => $customersWithTaxNumbers,
            'taxable_percentage' => $totalCustomers > 0 ? ($taxableCustomers / $totalCustomers) * 100 : 0,
            'exempted_percentage' => $totalCustomers > 0 ? ($exemptedCustomers / $totalCustomers) * 100 : 0,
        ];
    }
}
