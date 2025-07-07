<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerChequeLimit;
use App\Models\Currency;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CustomerChequeLimitController extends Controller
{
    public function index(Customer $customer)
    {
        $chequeLimits = $customer->chequeLimits()
            ->with('currency')
            ->orderBy('currency_id')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Customer cheque limits fetched successfully.',
            'data' => $chequeLimits,
        ]);
    }

    public function store(Request $request, Customer $customer)
    {
        $request->validate([
            'currency_id' => [
                'required',
                'exists:currencies,id',
                Rule::unique('customer_cheque_limits')
                    ->where('customer_id', $customer->id)
                    ->where('currency_id', $request->currency_id)
            ],
            'max_cheques' => 'required|integer|min:0',
            'notes' => 'nullable|string',
        ]);

        $chequeLimit = $customer->chequeLimits()->create([
            'currency_id' => $request->currency_id,
            'max_cheques' => $request->max_cheques,
            'used_cheques' => 0,
            'available_cheques' => $request->max_cheques,
            'notes' => $request->notes,
            'is_active' => true,
        ]);

        $chequeLimit->load('currency');

        return response()->json([
            'status' => true,
            'message' => 'Cheque limit created successfully.',
            'data' => $chequeLimit,
        ], 201);
    }

    public function show(Customer $customer, CustomerChequeLimit $chequeLimit)
    {
        if ($chequeLimit->customer_id !== $customer->id) {
            return response()->json([
                'status' => false,
                'message' => 'Cheque limit not found for this customer.',
            ], 404);
        }

        $chequeLimit->load('currency');

        return response()->json([
            'status' => true,
            'message' => 'Cheque limit details fetched successfully.',
            'data' => $chequeLimit,
        ]);
    }

    public function update(Request $request, Customer $customer, CustomerChequeLimit $chequeLimit)
    {
        if ($chequeLimit->customer_id !== $customer->id) {
            return response()->json([
                'status' => false,
                'message' => 'Cheque limit not found for this customer.',
            ], 404);
        }

        $request->validate([
            'max_cheques' => 'required|integer|min:0',
            'notes' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        // Check if reducing max cheques would cause issues
        if ($request->max_cheques < $chequeLimit->used_cheques) {
            return response()->json([
                'status' => false,
                'message' => 'Cannot reduce max cheques below used cheques count.',
            ], 422);
        }

        $chequeLimit->update([
            'max_cheques' => $request->max_cheques,
            'notes' => $request->notes,
            'is_active' => $request->is_active ?? $chequeLimit->is_active,
        ]);

        $chequeLimit->load('currency');

        return response()->json([
            'status' => true,
            'message' => 'Cheque limit updated successfully.',
            'data' => $chequeLimit,
        ]);
    }

    public function destroy(Customer $customer, CustomerChequeLimit $chequeLimit)
    {
        if ($chequeLimit->customer_id !== $customer->id) {
            return response()->json([
                'status' => false,
                'message' => 'Cheque limit not found for this customer.',
            ], 404);
        }

        // Check if there are used cheques
        if ($chequeLimit->used_cheques > 0) {
            return response()->json([
                'status' => false,
                'message' => 'Cannot delete cheque limit with used cheques. Consider deactivating instead.',
            ], 422);
        }

        $chequeLimit->delete();

        return response()->json([
            'status' => true,
            'message' => 'Cheque limit deleted successfully.',
        ]);
    }

    public function bulkStore(Request $request, Customer $customer)
    {
        $request->validate([
            'cheque_limits' => 'required|array|min:1',
            'cheque_limits.*.currency_id' => [
                'required',
                'exists:currencies,id',
            ],
            'cheque_limits.*.max_cheques' => 'required|integer|min:0',
            'cheque_limits.*.notes' => 'nullable|string',
        ]);

        $results = [];
        $errors = [];

        DB::beginTransaction();
        try {
            foreach ($request->cheque_limits as $index => $chequeLimitData) {
                // Check if cheque limit already exists for this currency
                $existing = $customer->chequeLimits()
                    ->where('currency_id', $chequeLimitData['currency_id'])
                    ->first();

                if ($existing) {
                    $errors[] = [
                        'index' => $index,
                        'currency_id' => $chequeLimitData['currency_id'],
                        'message' => 'Cheque limit already exists for this currency.'
                    ];
                    continue;
                }

                $chequeLimit = $customer->chequeLimits()->create([
                    'currency_id' => $chequeLimitData['currency_id'],
                    'max_cheques' => $chequeLimitData['max_cheques'],
                    'used_cheques' => 0,
                    'available_cheques' => $chequeLimitData['max_cheques'],
                    'notes' => $chequeLimitData['notes'] ?? null,
                    'is_active' => true,
                ]);

                $chequeLimit->load('currency');
                $results[] = $chequeLimit;
            }

            if (!empty($errors)) {
                DB::rollBack();
                return response()->json([
                    'status' => false,
                    'message' => 'Some cheque limits could not be created.',
                    'errors' => $errors,
                ], 422);
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Cheque limits created successfully.',
                'data' => $results,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Failed to create cheque limits.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getAvailableCurrencies(Customer $customer)
    {
        $usedCurrencyIds = $customer->chequeLimits()
            ->pluck('currency_id')
            ->toArray();

        $availableCurrencies = Currency::whereNotIn('id', $usedCurrencyIds)->get();

        return response()->json([
            'status' => true,
            'message' => 'Available currencies fetched successfully.',
            'data' => $availableCurrencies,
        ]);
    }

    public function getChequeSummary(Customer $customer)
    {
        $summary = $customer->chequeLimits()
            ->with('currency')
            ->active()
            ->get()
            ->map(function ($chequeLimit) {
                return [
                    'currency' => $chequeLimit->currency,
                    'max_cheques' => $chequeLimit->max_cheques,
                    'used_cheques' => $chequeLimit->used_cheques,
                    'available_cheques' => $chequeLimit->available_cheques,
                    'utilization_percentage' => $chequeLimit->getUtilizationPercentage(),
                    'remaining_cheques' => $chequeLimit->getRemainingCheques(),
                    'is_over_limit' => $chequeLimit->isOverLimit(),
                ];
            });

        $totalSummary = [
            'total_max_cheques' => $customer->getTotalMaxCheques(),
            'total_used_cheques' => $customer->getTotalUsedCheques(),
            'total_available_cheques' => $customer->getTotalAvailableCheques(),
            'accept_cheque' => $customer->accept_cheque,
        ];

        return response()->json([
            'status' => true,
            'message' => 'Cheque summary fetched successfully.',
            'data' => [
                'summary' => $summary,
                'total_summary' => $totalSummary,
            ],
        ]);
    }

    public function checkChequeAvailability(Request $request, Customer $customer)
    {
        $request->validate([
            'currency_id' => 'required|exists:currencies,id',
            'count' => 'required|integer|min:1',
        ]);

        $canAccept = $customer->canAcceptCheque($request->currency_id, $request->count);
        $chequeLimit = $customer->getChequeLimitForCurrency($request->currency_id);

        return response()->json([
            'status' => true,
            'message' => 'Cheque availability checked successfully.',
            'data' => [
                'can_accept_cheque' => $canAccept,
                'accept_cheque_enabled' => $customer->accept_cheque,
                'currency_id' => $request->currency_id,
                'requested_count' => $request->count,
                'cheque_limit' => $chequeLimit ? [
                    'max_cheques' => $chequeLimit->max_cheques,
                    'used_cheques' => $chequeLimit->used_cheques,
                    'available_cheques' => $chequeLimit->available_cheques,
                    'utilization_percentage' => $chequeLimit->getUtilizationPercentage(),
                ] : null,
            ],
        ]);
    }
}
