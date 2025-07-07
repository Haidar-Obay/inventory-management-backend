<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionPlan;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
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
            'plans' => $plans
        ]);
    }

    public function show($id): JsonResponse
    {
        $plan = SubscriptionPlan::findOrFail($id);

        return response()->json([
            'plan' => $plan
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
            'is_default' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        $plan = SubscriptionPlan::create($request->all());

        Cache::forget('subscription_plans_all');

        return response()->json([
            'message' => 'Subscription plan created successfully',
            'plan' => $plan
        ], 201);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $plan = SubscriptionPlan::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'code' => 'sometimes|required|string|max:50|unique:subscription_plans,code,' . $id,
            'description' => 'nullable|string',
            'price' => 'sometimes|required|numeric|min:0',
            'billing_cycle' => 'sometimes|required|in:monthly,yearly',
            'max_currencies' => 'sometimes|required|integer|min:1',
            'max_users' => 'nullable|integer|min:1',
            'max_customers' => 'nullable|integer|min:1',
            'features' => 'nullable|array',
            'is_active' => 'boolean',
            'is_default' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        $plan->update($request->all());

        Cache::forget('subscription_plans_all');

        return response()->json([
            'message' => 'Subscription plan updated successfully',
            'plan' => $plan
        ]);
    }

    public function destroy($id): JsonResponse
    {
        $plan = SubscriptionPlan::findOrFail($id);

        // Check if any tenants are using this plan
        if ($plan->tenants()->count() > 0) {
            return response()->json([
                'message' => 'Cannot delete plan. It is currently being used by tenants.'
            ], 422);
        }

        $plan->delete();

        Cache::forget('subscription_plans_all');

        return response()->json([
            'message' => 'Subscription plan deleted successfully'
        ]);
    }

    public function getDefaultPlan(): JsonResponse
    {
        $plan = SubscriptionPlan::where('is_default', true)
            ->where('is_active', true)
            ->first();

        if (!$plan) {
            return response()->json([
                'message' => 'No default plan found'
            ], 404);
        }

        return response()->json([
            'plan' => $plan
        ]);
    }
}
