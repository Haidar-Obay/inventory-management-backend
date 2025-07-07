<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\Currency;
use App\Models\User;
use App\Models\Customer;

class CheckSubscriptionLimits
{
    public function handle(Request $request, Closure $next, string $resource = null): JsonResponse|Closure
    {
        $tenant = tenant();

        if (!$tenant) {
            return response()->json(['message' => 'Tenant not found'], 404);
        }

        // Check if tenant has active subscription
        if (!$tenant->hasActiveSubscription()) {
            return response()->json([
                'message' => 'Your subscription has expired. Please renew to continue using the service.',
                'subscription_status' => $tenant->subscription_status
            ], 403);
        }

        // If no specific resource check is requested, continue
        if (!$resource) {
            return $next($request);
        }

        $currentCount = 0;
        $canAdd = false;

        switch ($resource) {
            case 'currency':
                $currentCount = Currency::count();
                $canAdd = $tenant->canAddCurrency($currentCount);
                break;

            case 'user':
                $currentCount = User::count();
                $canAdd = $tenant->canAddUser($currentCount);
                break;

            case 'customer':
                $currentCount = Customer::count();
                $canAdd = $tenant->canAddCustomer($currentCount);
                break;

            default:
                return $next($request);
        }

        if (!$canAdd) {
            $planName = $tenant->subscriptionPlan?->name ?? 'Unknown Plan';
            $maxLimit = $tenant->subscriptionPlan?->{"max_{$resource}s"} ?? 0;

            return response()->json([
                'message' => "You have reached the maximum limit of {$resource}s for your current plan ({$planName}).",
                'current_count' => $currentCount,
                'max_limit' => $maxLimit,
                'plan_name' => $planName,
                'upgrade_required' => true
            ], 403);
        }

        return $next($request);
    }
}
