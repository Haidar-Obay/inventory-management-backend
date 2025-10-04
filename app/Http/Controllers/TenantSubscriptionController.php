<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TenantSubscriptionController extends Controller
{
    protected $subscriptionService;

    public function __construct(SubscriptionService $subscriptionService)
    {
        $this->subscriptionService = $subscriptionService;
    }

    public function getTenantSubscription($tenantId): JsonResponse
    {
        $tenant = Tenant::findOrFail($tenantId);

        $subscriptionInfo = $this->subscriptionService->getTenantSubscriptionInfo($tenant);

        return response()->json([
            'tenant_id' => $tenant->id,
            'tenant_name' => $tenant->name,
            'subscription' => $subscriptionInfo,
        ]);
    }

    public function upgradeTenantPlan(Request $request, $tenantId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'subscription_plan_id' => 'required|exists:subscription_plans,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors(),
            ], 422);
        }

        $tenant = Tenant::findOrFail($tenantId);
        $newPlan = SubscriptionPlan::findOrFail($request->subscription_plan_id);

        try {
            $this->subscriptionService->upgradeTenantPlan($tenant, $newPlan);

            return response()->json([
                'message' => 'Tenant plan upgraded successfully',
                'tenant_id' => $tenant->id,
                'new_plan' => $newPlan->name,
                'subscription_info' => $this->subscriptionService->getTenantSubscriptionInfo($tenant),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to upgrade tenant plan: '.$e->getMessage(),
            ], 500);
        }
    }

    public function cancelTenantSubscription($tenantId): JsonResponse
    {
        $tenant = Tenant::findOrFail($tenantId);

        try {
            $this->subscriptionService->cancelTenantSubscription($tenant);

            return response()->json([
                'message' => 'Tenant subscription cancelled successfully',
                'tenant_id' => $tenant->id,
                'subscription_status' => 'cancelled',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to cancel tenant subscription: '.$e->getMessage(),
            ], 500);
        }
    }

    public function reactivateTenantSubscription($tenantId): JsonResponse
    {
        $tenant = Tenant::findOrFail($tenantId);

        if ($tenant->subscription_status !== 'cancelled') {
            return response()->json([
                'error' => 'Subscription is not cancelled',
            ], 400);
        }

        try {
            $plan = $tenant->subscriptionPlan;
            if (! $plan) {
                return response()->json([
                    'error' => 'No subscription plan found for tenant',
                ], 400);
            }

            $this->subscriptionService->upgradeTenantPlan($tenant, $plan);

            return response()->json([
                'message' => 'Tenant subscription reactivated successfully',
                'tenant_id' => $tenant->id,
                'subscription_info' => $this->subscriptionService->getTenantSubscriptionInfo($tenant),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to reactivate tenant subscription: '.$e->getMessage(),
            ], 500);
        }
    }

    public function checkExpiredSubscriptions(): JsonResponse
    {
        try {
            $result = $this->subscriptionService->checkAndUpdateExpiredSubscriptions();

            return response()->json([
                'message' => 'Expired subscriptions check completed',
                'updated_count' => $result['updated_count'],
                'errors' => $result['errors'],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to check expired subscriptions: '.$e->getMessage(),
            ], 500);
        }
    }

    public function getAllTenantsSubscriptions(): JsonResponse
    {
        $tenants = Tenant::with('subscriptionPlan')->get();

        $subscriptions = $tenants->map(function ($tenant) {
            return [
                'tenant_id' => $tenant->id,
                'tenant_name' => $tenant->name,
                'tenant_email' => $tenant->email,
                'subscription' => $this->subscriptionService->getTenantSubscriptionInfo($tenant),
            ];
        });

        return response()->json([
            'tenants_subscriptions' => $subscriptions,
        ]);
    }
}
