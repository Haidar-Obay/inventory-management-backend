<?php

namespace App\Http\Controllers;

use App\Models\Currency;
use App\Models\Supplier;
use App\Services\OpeningBalanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SupplierOpeningBalanceController extends Controller
{
    protected $openingBalanceService;

    public function __construct(OpeningBalanceService $openingBalanceService)
    {
        $this->openingBalanceService = $openingBalanceService;
    }

    /**
     * Get all opening balances for a supplier
     */
    public function index(Request $request, $supplierId): JsonResponse
    {
        try {
            $supplier = Supplier::findOrFail($supplierId);

            $openingBalances = $supplier->openingBalances()
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
    public function show($supplierId, $openingBalanceId): JsonResponse
    {
        try {
            $supplier = Supplier::findOrFail($supplierId);
            $openingBalance = $supplier->openingBalances()
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
    public function store(Request $request, $supplierId): JsonResponse
    {
        try {
            $supplier = Supplier::findOrFail($supplierId);

            $validator = Validator::make($request->all(), [
                'currency_id' => 'required|exists:currencies,id',
                'opening_amount' => 'required|numeric|min:0',
                'opening_date' => 'required|date',
                'notes' => 'nullable|string|max:1000',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            // Check if opening balance already exists for this currency
            if ($this->openingBalanceService->hasOpeningBalanceForCurrency($supplier, $request->currency_id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Opening balance already exists for this currency',
                ], 409);
            }

            $openingBalance = $this->openingBalanceService->setSupplierOpeningBalance(
                $supplier,
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
     * Update an existing opening balance
     */
    public function update(Request $request, $supplierId, $openingBalanceId): JsonResponse
    {
        try {
            $supplier = Supplier::findOrFail($supplierId);
            $openingBalance = $supplier->openingBalances()->findOrFail($openingBalanceId);

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
                'is_active' => 'sometimes|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $openingBalance->update($request->only([
                'opening_amount',
                'opening_date',
                'notes',
                'payment_term_id',
                'payment_method_id',
                'allow_credit',
                'payment_day',
                'track_payment',
                'settlement_method',
                'accept_cheques',
                'is_active',
            ]));

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
     * Remove an opening balance (soft delete by setting is_active to false)
     */
    public function destroy($supplierId, $openingBalanceId): JsonResponse
    {
        try {
            $supplier = Supplier::findOrFail($supplierId);
            $openingBalance = $supplier->openingBalances()->findOrFail($openingBalanceId);

            $openingBalance->update(['is_active' => false]);

            return response()->json([
                'success' => true,
                'message' => 'Opening balance removed successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove opening balance: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get available currencies for opening balances
     */
    public function getAvailableCurrencies($supplierId): JsonResponse
    {
        try {
            $supplier = Supplier::findOrFail($supplierId);
            $usedCurrencyIds = $this->openingBalanceService->getOpeningCurrencyIds($supplier);

            $availableCurrencies = Currency::where('active', true)
                ->whereNotIn('id', $usedCurrencyIds)
                ->select('id', 'code', 'name', 'iso_code', 'symbol')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $availableCurrencies,
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
     * Bulk create opening balances
     */
    public function bulkStore(Request $request, $supplierId): JsonResponse
    {
        try {
            $supplier = Supplier::findOrFail($supplierId);

            $validator = Validator::make($request->all(), [
                'opening_balances' => 'required|array|min:1',
                'opening_balances.*.currency_id' => 'required|exists:currencies,id',
                'opening_balances.*.opening_amount' => 'required|numeric|min:0',
                'opening_balances.*.opening_date' => 'nullable|date',
                'opening_balances.*.notes' => 'nullable|string|max:1000',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            DB::beginTransaction();

            $createdBalances = [];
            foreach ($request->input('opening_balances') as $openingBalanceData) {
                // Check if opening balance already exists for this currency
                if ($this->openingBalanceService->hasOpeningBalanceForCurrency($supplier, $openingBalanceData['currency_id'])) {
                    DB::rollBack();

                    return response()->json([
                        'success' => false,
                        'message' => 'Opening balance already exists for currency ID: '.$openingBalanceData['currency_id'],
                    ], 409);
                }

                $openingBalance = $this->openingBalanceService->setSupplierOpeningBalance(
                    $supplier,
                    $openingBalanceData['currency_id'],
                    $openingBalanceData['opening_amount'],
                    $openingBalanceData['opening_date'] ?? null,
                    $openingBalanceData['notes'] ?? null,
                    $openingBalanceData['payment_term_id'] ?? null,
                    $openingBalanceData['payment_method_id'] ?? null,
                    (bool) ($openingBalanceData['allow_credit'] ?? false),
                    $openingBalanceData['payment_day'] ?? null,
                    $openingBalanceData['track_payment'] ?? 'no',
                    $openingBalanceData['settlement_method'] ?? null,
                    (bool) ($openingBalanceData['accept_cheques'] ?? false)
                );

                $openingBalance->load(['currency', 'paymentTerm', 'paymentMethod']);
                $createdBalances[] = $openingBalance;
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => $createdBalances,
                'message' => 'Opening balances created successfully',
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to create opening balances: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Check if opening balance exists for a currency
     */
    public function checkCurrencyExists($supplierId, $currencyId): JsonResponse
    {
        try {
            $supplier = Supplier::findOrFail($supplierId);
            $exists = $this->openingBalanceService->hasOpeningBalanceForCurrency($supplier, $currencyId);

            return response()->json([
                'success' => true,
                'data' => [
                    'currency_id' => $currencyId,
                    'exists' => $exists,
                ],
                'message' => $exists ? 'Opening balance exists for this currency' : 'No opening balance for this currency',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to check currency: '.$e->getMessage(),
            ], 500);
        }
    }
}
