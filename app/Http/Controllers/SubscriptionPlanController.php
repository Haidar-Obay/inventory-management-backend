<?php

namespace App\Http\Controllers;

use App\Models\Currency;
use App\Models\SubscriptionPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class SubscriptionPlanController extends Controller
{
    public function index(): JsonResponse
    {
        $cacheKey = 'subscription_plans_all';
        $plans = Cache::remember($cacheKey, 3600, function () {
            return SubscriptionPlan::where('is_active', true)->get();
        });

        return response()->json([
            'plans' => $plans,
        ]);
    }

    public function show($id): JsonResponse
    {
        $plan = SubscriptionPlan::findOrFail($id);

        return response()->json([
            'plan' => $plan,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:subscription_plans,code',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'billing_cycle' => 'required|in:monthly,yearly',
            'max_currencies' => 'required|integer|min:1',
            'max_users' => 'nullable|integer|min:1',
            'max_customers' => 'nullable|integer|min:1',
            'features' => 'nullable|array',
            'is_default' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors(),
            ], 422);
        }

        $plan = SubscriptionPlan::create($request->all());

        Cache::forget('subscription_plans_all');

        return response()->json([
            'message' => 'Subscription plan created successfully',
            'plan' => $plan,
        ], 201);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $plan = SubscriptionPlan::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'code' => 'sometimes|required|string|max:50|unique:subscription_plans,code,'.$id,
            'description' => 'nullable|string',
            'price' => 'sometimes|required|numeric|min:0',
            'billing_cycle' => 'sometimes|required|in:monthly,yearly',
            'max_currencies' => 'sometimes|required|integer|min:1',
            'max_users' => 'nullable|integer|min:1',
            'max_customers' => 'nullable|integer|min:1',
            'features' => 'nullable|array',
            'is_default' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors(),
            ], 422);
        }

        $plan->update($request->all());

        Cache::forget('subscription_plans_all');

        return response()->json([
            'message' => 'Subscription plan updated successfully',
            'plan' => $plan,
        ]);
    }

    public function destroy($id): JsonResponse
    {
        $plan = SubscriptionPlan::findOrFail($id);

        // Check if plan is being used by any tenants
        if ($plan->tenants()->exists()) {
            return response()->json([
                'message' => 'Cannot delete plan. It is currently being used by tenants.',
            ], 422);
        }

        $plan->delete();

        Cache::forget('subscription_plans_all');

        return response()->json([
            'message' => 'Subscription plan deleted successfully',
        ]);
    }

    /**
     * Check current user's subscription status and currency limits
     */
    public function checkCurrentUserSubscription(): JsonResponse
    {
        try {
            $tenant = tenant();

            if (! $tenant) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant not found',
                ], 404);
            }

            // Check if tenant has active subscription
            if (! $tenant->hasActiveSubscription()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Your subscription has expired. Please renew to continue using the service.',
                    'subscription_status' => $tenant->subscription_status,
                    'can_add_multiple_currencies' => false,
                ], 403);
            }

            $currentCurrencyCount = Currency::count();
            $canAddCurrency = $tenant->canAddCurrency($currentCurrencyCount);
            $plan = $tenant->subscriptionPlan;

            return response()->json([
                'success' => true,
                'data' => [
                    'can_add_multiple_currencies' => $canAddCurrency,
                    'current_currency_count' => $currentCurrencyCount,
                    'max_currencies_allowed' => $plan?->max_currencies ?? 1,
                    'plan_name' => $plan?->name ?? 'No Plan',
                    'plan_code' => $plan?->code ?? 'none',
                    'subscription_status' => $tenant->subscription_status,
                    'is_active_subscription' => $tenant->hasActiveSubscription(),
                    'features' => $tenant->getSubscriptionFeatures(),
                    'can_add_another_currency' => $currentCurrencyCount < ($plan?->max_currencies ?? 1),
                    'remaining_currency_slots' => max(0, ($plan?->max_currencies ?? 1) - $currentCurrencyCount),
                    'subscription_info' => [
                        'start_date' => $tenant->subscription_start_date?->format('Y-m-d'),
                        'end_date' => $tenant->subscription_end_date?->format('Y-m-d'),
                        'auto_renew' => $tenant->auto_renew,
                        'is_expired' => $tenant->isSubscriptionExpired(),
                    ],
                ],
                'message' => 'Subscription status checked successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to check subscription status: '.$e->getMessage(),
            ], 500);
        }
    }
}
