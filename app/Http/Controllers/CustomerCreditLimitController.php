<?php

namespace App\Http\Controllers;

use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerCreditLimit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CustomerCreditLimitController extends Controller
{
    public function index(Customer $customer)
    {
        $creditLimits = $customer->creditLimits()
            ->with('currency')
            ->orderBy('currency_id')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Customer credit limits fetched successfully.',
            'data' => $creditLimits,
        ]);
    }

    public function store(Request $request, Customer $customer)
    {
        $request->validate([
            'currency_id' => [
                'required',
                'exists:currencies,id',
                Rule::unique('customer_credit_limits')
                    ->where('customer_id', $customer->id)
                    ->where('currency_id', $request->currency_id),
            ],
            'credit_limit' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $creditLimit = CustomerCreditLimit::create([
            'customer_id' => $customer->id,
            'currency_id' => $request->currency_id,
            'credit_limit' => $request->credit_limit,
            'used_credit' => 0,
            'available_credit' => $request->credit_limit,
            'notes' => $request->notes,
            'is_active' => true,
        ]);

        $creditLimit->load('currency');

        return response()->json([
            'status' => true,
            'message' => 'Credit limit created successfully.',
            'data' => $creditLimit,
        ], 201);
    }

    public function show(Customer $customer, CustomerCreditLimit $creditLimit)
    {
        if ($creditLimit->customer_id !== $customer->id) {
            return response()->json([
                'status' => false,
                'message' => 'Credit limit not found for this customer.',
            ], 404);
        }

        $creditLimit->load('currency');

        return response()->json([
            'status' => true,
            'message' => 'Credit limit details fetched successfully.',
            'data' => $creditLimit,
        ]);
    }

    public function update(Request $request, Customer $customer, CustomerCreditLimit $creditLimit)
    {
        if ($creditLimit->customer_id !== $customer->id) {
            return response()->json([
                'status' => false,
                'message' => 'Credit limit not found for this customer.',
            ], 404);
        }

        $request->validate([
            'credit_limit' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $creditLimit->update([
            'credit_limit' => $request->credit_limit,
            'notes' => $request->notes,
            'is_active' => $request->is_active ?? $creditLimit->is_active,
        ]);

        $creditLimit->load('currency');

        return response()->json([
            'status' => true,
            'message' => 'Credit limit updated successfully.',
            'data' => $creditLimit,
        ]);
    }

    public function destroy(Customer $customer, CustomerCreditLimit $creditLimit)
    {
        if ($creditLimit->customer_id !== $customer->id) {
            return response()->json([
                'status' => false,
                'message' => 'Credit limit not found for this customer.',
            ], 404);
        }

        // Check if there's used credit
        if ($creditLimit->used_credit > 0) {
            return response()->json([
                'status' => false,
                'message' => 'Cannot delete credit limit with used credit. Consider deactivating instead.',
            ], 422);
        }

        $creditLimit->delete();

        return response()->json([
            'status' => true,
            'message' => 'Credit limit deleted successfully.',
        ]);
    }

    public function bulkStore(Request $request, Customer $customer)
    {
        $request->validate([
            'credit_limits' => 'required|array|min:1',
            'credit_limits.*.currency_id' => [
                'required',
                'exists:currencies,id',
            ],
            'credit_limits.*.credit_limit' => 'required|numeric|min:0',
            'credit_limits.*.notes' => 'nullable|string',
        ]);

        $results = [];
        $errors = [];

        DB::beginTransaction();

        try {
            foreach ($request->credit_limits as $index => $creditLimitData) {
                // Check if credit limit already exists for this currency
                $existing = $customer->creditLimits()
                    ->where('currency_id', $creditLimitData['currency_id'])
                    ->first();

                if ($existing) {
                    $errors[] = [
                        'index' => $index,
                        'currency_id' => $creditLimitData['currency_id'],
                        'message' => 'Credit limit already exists for this currency.',
                    ];

                    continue;
                }

                $creditLimit = CustomerCreditLimit::create([
                    'customer_id' => $customer->id,
                    'currency_id' => $creditLimitData['currency_id'],
                    'credit_limit' => $creditLimitData['credit_limit'],
                    'used_credit' => 0,
                    'available_credit' => $creditLimitData['credit_limit'],
                    'notes' => $creditLimitData['notes'] ?? null,
                    'is_active' => true,
                ]);

                $creditLimit->load('currency');
                $results[] = $creditLimit;
            }

            if (! empty($errors)) {
                DB::rollBack();

                return response()->json([
                    'status' => false,
                    'message' => 'Some credit limits could not be created.',
                    'errors' => $errors,
                ], 422);
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Credit limits created successfully.',
                'data' => $results,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Failed to create credit limits.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getAvailableCurrencies(Customer $customer)
    {
        $usedCurrencyIds = $customer->creditLimits()
            ->pluck('currency_id')
            ->toArray();

        $availableCurrencies = Currency::whereNotIn('id', $usedCurrencyIds)->get();

        return response()->json([
            'status' => true,
            'message' => 'Available currencies fetched successfully.',
            'data' => $availableCurrencies,
        ]);
    }

    public function getCreditSummary(Customer $customer)
    {
        $summary = $customer->creditLimits()
            ->with('currency')
            ->active()
            ->get()
            ->map(function ($creditLimit) {
                return [
                    'currency' => $creditLimit->currency,
                    'credit_limit' => $creditLimit->credit_limit,
                    'used_credit' => $creditLimit->used_credit,
                    'available_credit' => $creditLimit->available_credit,
                    'utilization_percentage' => $creditLimit->getUtilizationPercentage(),
                ];
            });

        $totalSummary = [
            'total_credit_limit' => $customer->getTotalCreditLimit(),
            'total_used_credit' => $customer->getTotalUsedCredit(),
            'total_available_credit' => $customer->getTotalAvailableCredit(),
            'allow_credit' => $customer->openingBalances()->active()->where('allow_credit', true)->exists(),
        ];

        return response()->json([
            'status' => true,
            'message' => 'Credit summary fetched successfully.',
            'data' => [
                'summary' => $summary,
                'total_summary' => $totalSummary,
            ],
        ]);
    }
}
