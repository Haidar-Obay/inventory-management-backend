<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\TaxService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerTaxController extends Controller
{
    protected $taxService;

    public function __construct(TaxService $taxService)
    {
        $this->taxService = $taxService;
    }

    /**
     * Get tax information for a customer
     */
    public function getTaxInfo(Customer $customer)
    {
        $taxInfo = $this->taxService->getTaxInfo($customer);

        return response()->json([
            'status' => true,
            'message' => 'Tax information fetched successfully.',
            'data' => $taxInfo,
        ]);
    }

    /**
     * Set tax exemption for a customer
     */
    public function setTaxExemption(Request $request, Customer $customer)
    {
        $request->validate([
            'exemption_from' => 'required|string|max:255',
            'exemption_reference' => 'required|string|max:255',
            'exempted_from_date' => 'nullable|date',
            'exempted_till_date' => 'nullable|date|after_or_equal:exempted_from_date',
        ]);

        $success = $this->taxService->setTaxExemption(
            $customer,
            $request->exemption_from,
            $request->exemption_reference,
            $request->exempted_from_date,
            $request->exempted_till_date
        );

        if ($success) {
            return response()->json([
                'status' => true,
                'message' => 'Tax exemption set successfully.',
                'data' => $this->taxService->getTaxInfo($customer),
            ]);
        }

        return response()->json([
            'status' => false,
            'message' => 'Failed to set tax exemption.',
        ], 500);
    }

    /**
     * Remove tax exemption from a customer
     */
    public function removeTaxExemption(Customer $customer)
    {
        $success = $this->taxService->removeTaxExemption($customer);

        if ($success) {
            return response()->json([
                'status' => true,
                'message' => 'Tax exemption removed successfully.',
                'data' => $this->taxService->getTaxInfo($customer),
            ]);
        }

        return response()->json([
            'status' => false,
            'message' => 'Failed to remove tax exemption.',
        ], 500);
    }

    /**
     * Update tax number for a customer
     */
    public function updateTaxNumber(Request $request, Customer $customer)
    {
        $request->validate([
            'tax_number' => 'required|string|max:255',
        ]);

        $success = $this->taxService->updateTaxNumber($customer, $request->tax_number);

        if ($success) {
            return response()->json([
                'status' => true,
                'message' => 'Tax number updated successfully.',
                'data' => $this->taxService->getTaxInfo($customer),
            ]);
        }

        return response()->json([
            'status' => false,
            'message' => 'Failed to update tax number.',
        ], 500);
    }

    /**
     * Set taxable status for a customer
     */
    public function setTaxableStatus(Request $request, Customer $customer)
    {
        $request->validate([
            'taxable' => 'required|boolean',
        ]);

        $success = $this->taxService->setTaxableStatus($customer, $request->taxable);

        if ($success) {
            return response()->json([
                'status' => true,
                'message' => 'Taxable status updated successfully.',
                'data' => $this->taxService->getTaxInfo($customer),
            ]);
        }

        return response()->json([
            'status' => false,
            'message' => 'Failed to update taxable status.',
        ], 500);
    }

    /**
     * Calculate tax amount for a transaction
     */
    public function calculateTaxAmount(Request $request, Customer $customer)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
        ]);

        $taxCalculation = $this->taxService->calculateTaxAmount(
            $customer,
            $request->amount,
            $request->tax_rate ?? 0
        );

        return response()->json([
            'status' => true,
            'message' => 'Tax calculation completed successfully.',
            'data' => $taxCalculation,
        ]);
    }

    /**
     * Get customers with expiring tax exemptions
     */
    public function getExpiringExemptions(Request $request)
    {
        $daysThreshold = $request->get('days_threshold', 30);

        $customers = $this->taxService->getCustomersWithExpiringExemptions($daysThreshold);

        return response()->json([
            'status' => true,
            'message' => 'Customers with expiring exemptions fetched successfully.',
            'data' => $customers,
        ]);
    }

    /**
     * Get customers with expired tax exemptions
     */
    public function getExpiredExemptions()
    {
        $customers = $this->taxService->getCustomersWithExpiredExemptions();

        return response()->json([
            'status' => true,
            'message' => 'Customers with expired exemptions fetched successfully.',
            'data' => $customers,
        ]);
    }

    /**
     * Get currently exempted customers
     */
    public function getCurrentlyExemptedCustomers()
    {
        $customers = $this->taxService->getCurrentlyExemptedCustomers();

        return response()->json([
            'status' => true,
            'message' => 'Currently exempted customers fetched successfully.',
            'data' => $customers,
        ]);
    }

    /**
     * Get customers with tax numbers
     */
    public function getCustomersWithTaxNumbers()
    {
        $customers = $this->taxService->getCustomersWithTaxNumbers();

        return response()->json([
            'status' => true,
            'message' => 'Customers with tax numbers fetched successfully.',
            'data' => $customers,
        ]);
    }

    /**
     * Get tax summary for reporting
     */
    public function getTaxSummary()
    {
        $summary = $this->taxService->getTaxSummary();

        return response()->json([
            'status' => true,
            'message' => 'Tax summary fetched successfully.',
            'data' => $summary,
        ]);
    }

    /**
     * Bulk update tax exemptions
     */
    public function bulkUpdateTaxExemptions(Request $request)
    {
        $request->validate([
            'customers' => 'required|array|min:1',
            'customers.*.customer_id' => 'required|exists:customers,id',
            'customers.*.exemption_from' => 'required|string|max:255',
            'customers.*.exemption_reference' => 'required|string|max:255',
            'customers.*.exempted_from_date' => 'nullable|date',
            'customers.*.exempted_till_date' => 'nullable|date|after_or_equal:customers.*.exempted_from_date',
        ]);

        $results = [];
        $errors = [];

        DB::beginTransaction();
        try {
            foreach ($request->customers as $index => $customerData) {
                $customer = Customer::find($customerData['customer_id']);

                $success = $this->taxService->setTaxExemption(
                    $customer,
                    $customerData['exemption_from'],
                    $customerData['exemption_reference'],
                    $customerData['exempted_from_date'] ?? null,
                    $customerData['exempted_till_date'] ?? null
                );

                if ($success) {
                    $results[] = [
                        'customer_id' => $customer->id,
                        'customer_name' => $customer->display_name,
                        'status' => 'success',
                    ];
                } else {
                    $errors[] = [
                        'index' => $index,
                        'customer_id' => $customer->id,
                        'message' => 'Failed to set tax exemption for this customer.',
                    ];
                }
            }

            if (!empty($errors)) {
                DB::rollBack();
                return response()->json([
                    'status' => false,
                    'message' => 'Some tax exemptions could not be set.',
                    'errors' => $errors,
                ], 422);
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Tax exemptions set successfully.',
                'data' => $results,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Failed to set tax exemptions.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
