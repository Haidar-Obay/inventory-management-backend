<?php

namespace App\Http\Controllers;

use App\Models\Currency;
use App\Models\Customer;
use App\Services\OpeningBalanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CustomerOpeningBalanceController extends Controller
{
    public function __construct(
        protected OpeningBalanceService $openingBalanceService
    ) {}
    /**
     * Get all opening balances for a customer
     */
    public function index(Request $request, $customerId): JsonResponse
    {
        try {
            $customer = Customer::findOrFail($customerId);

            $openingBalances = $customer->openingBalances()
                ->with(['currency'])
                ->active()
                ->orderBy('opening_date', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $openingBalances,
                'message' => 'Opening balances retrieved successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve opening balances: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get a specific opening balance
     */
    public function show($customerId, $openingBalanceId): JsonResponse
    {
        try {
            $customer = Customer::findOrFail($customerId);
            $openingBalance = $customer->openingBalances()
                ->with(['currency'])
                ->findOrFail($openingBalanceId);

            return response()->json([
                'success' => true,
                'data' => $openingBalance,
                'message' => 'Opening balance retrieved successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve opening balance: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Store a new opening balance
     */
    public function store(Request $request, $customerId): JsonResponse
    {
        try {
            $customer = Customer::findOrFail($customerId);

            $validator = Validator::make($request->all(), [
                'currency_id' => 'required|exists:currencies,id',
                'opening_amount' => 'required|numeric|min:0',
                'opening_date' => 'required|date',
                'notes' => 'nullable|string|max:1000',
                'payment_term_id' => 'nullable|exists:payment_terms,id',
                'payment_method_id' => 'nullable|exists:payment_methods,id',
                'allow_credit' => 'nullable|boolean',
                'payment_day' => 'nullable|string|in:1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30',
                'track_payment' => 'nullable|string|in:yes,no',
                'settlement_method' => 'nullable|string|in:FIFO,Manual',
                'accept_cheques' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            if ($this->openingBalanceService->hasOpeningBalanceForCurrency($customer, $request->currency_id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Opening balance already exists for this currency',
                ], 409);
            }

            $openingBalance = $this->openingBalanceService->setCustomerOpeningBalance(
                $customer,
                $request->currency_id,
                $request->opening_amount,
                $request->opening_date,
                $request->notes,
                $request->payment_term_id,
                $request->payment_method_id,
                (bool) ($request->allow_credit ?? false),
                $request->payment_day,
                $request->track_payment ?? 'no',
                $request->settlement_method,
                (bool) ($request->accept_cheques ?? false)
            );

            $openingBalance->load('currency');

            return response()->json([
                'success' => true,
                'data' => $openingBalance,
                'message' => 'Opening balance created successfully',
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create opening balance: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update an opening balance
     */
    public function update(Request $request, $customerId, $openingBalanceId): JsonResponse
    {
        try {
            $customer = Customer::findOrFail($customerId);
            $customer->openingBalances()->findOrFail($openingBalanceId);

            $validator = Validator::make($request->all(), [
                'opening_amount' => 'sometimes|required|numeric|min:0',
                'opening_date' => 'sometimes|required|date',
                'notes' => 'nullable|string|max:1000',
                'payment_term_id' => 'nullable|exists:payment_terms,id',
                'payment_method_id' => 'nullable|exists:payment_methods,id',
                'allow_credit' => 'sometimes|boolean',
                'payment_day' => 'nullable|string|in:1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30',
                'track_payment' => 'nullable|string|in:yes,no',
                'settlement_method' => 'nullable|string|in:FIFO,Manual',
                'accept_cheques' => 'sometimes|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $openingBalance = $customer->openingBalances()->findOrFail($openingBalanceId);
            $openingBalance = $this->openingBalanceService->setCustomerOpeningBalance(
                $customer,
                $openingBalance->currency_id,
                $request->opening_amount ?? $openingBalance->opening_amount,
                $request->opening_date ?? $openingBalance->opening_date?->toDateString(),
                $request->notes ?? $openingBalance->notes,
                $request->payment_term_id ?? $openingBalance->payment_term_id,
                $request->payment_method_id ?? $openingBalance->payment_method_id,
                (bool) ($request->has('allow_credit') ? $request->allow_credit : $openingBalance->allow_credit),
                $request->payment_day ?? $openingBalance->payment_day,
                $request->track_payment ?? $openingBalance->track_payment ?? 'no',
                $request->settlement_method ?? $openingBalance->settlement_method,
                (bool) ($request->has('accept_cheques') ? $request->accept_cheques : $openingBalance->accept_cheques),
                (int) $openingBalanceId
            );

            $openingBalance->load('currency');

            return response()->json([
                'success' => true,
                'data' => $openingBalance,
                'message' => 'Opening balance updated successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update opening balance: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete an opening balance (soft delete)
     */
    public function destroy($customerId, $openingBalanceId): JsonResponse
    {
        try {
            $customer = Customer::findOrFail($customerId);
            $openingBalance = $customer->openingBalances()->findOrFail($openingBalanceId);

            $openingBalance->update(['is_active' => false]);

            return response()->json([
                'success' => true,
                'message' => 'Opening balance deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete opening balance: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get opening balance summary for a customer
     */
    public function summary($customerId): JsonResponse
    {
        try {
            $customer = Customer::findOrFail($customerId);

            $summary = $customer->getOpeningBalanceSummary();

            return response()->json([
                'success' => true,
                'data' => $summary,
                'message' => 'Opening balance summary retrieved successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve opening balance summary: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get available currencies for opening balances
     */
    public function availableCurrencies($customerId): JsonResponse
    {
        try {
            $customer = Customer::findOrFail($customerId);

            // Get all currencies
            $allCurrencies = Currency::active()->get();

            // Get currencies already used in opening balances
            $usedCurrencyIds = $customer->getOpeningCurrencyIds();

            // Filter out used currencies
            $availableCurrencies = $allCurrencies->whereNotIn('id', $usedCurrencyIds);

            return response()->json([
                'success' => true,
                'data' => [
                    'available_currencies' => $availableCurrencies,
                    'used_currencies' => $customer->getOpeningCurrencies(),
                    'used_currency_ids' => $usedCurrencyIds,
                ],
                'message' => 'Available currencies retrieved successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve available currencies: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Bulk update opening balances
     */
    public function bulkUpdate(Request $request, $customerId): JsonResponse
    {
        try {
            $customer = Customer::findOrFail($customerId);

            $validator = Validator::make($request->all(), [
                'opening_balances' => 'required|array',
                'opening_balances.*.currency_id' => 'required|exists:currencies,id',
                'opening_balances.*.opening_amount' => 'required|numeric|min:0',
                'opening_balances.*.opening_date' => 'required|date',
                'opening_balances.*.notes' => 'nullable|string|max:1000',
                'opening_balances.*.payment_term_id' => 'nullable|exists:payment_terms,id',
                'opening_balances.*.payment_method_id' => 'nullable|exists:payment_methods,id',
                'opening_balances.*.allow_credit' => 'nullable|boolean',
                'opening_balances.*.payment_day' => 'nullable|string|in:1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30',
                'opening_balances.*.track_payment' => 'nullable|string|in:yes,no',
                'opening_balances.*.settlement_method' => 'nullable|string|in:FIFO,Manual',
                'opening_balances.*.accept_cheques' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $updatedBalances = $this->openingBalanceService->bulkUpdateCustomerOpeningBalances(
                $customer,
                $request->opening_balances
            );

            return response()->json([
                'success' => true,
                'data' => $updatedBalances,
                'message' => 'Opening balances updated successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update opening balances: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get opening balance statistics
     */
    public function statistics($customerId): JsonResponse
    {
        try {
            $customer = Customer::findOrFail($customerId);

            $openingBalances = $customer->openingBalances()
                ->with('currency')
                ->active()
                ->get();

            $statistics = [
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

            return response()->json([
                'success' => true,
                'data' => $statistics,
                'message' => 'Opening balance statistics retrieved successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve opening balance statistics: '.$e->getMessage(),
            ], 500);
        }
    }
}
