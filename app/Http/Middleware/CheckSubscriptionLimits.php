<?php

namespace App\Http\Middleware;

use App\Models\Currency;
use App\Models\Customer;
use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckSubscriptionLimits
{
    public function handle(Request $request, Closure $next, ?string $resource = null): JsonResponse|Closure
    {
        $tenant = tenant();

        if (! $tenant) {
            return response()->json(['message' => 'Tenant not found'], 404);
        }

        // Check if tenant has active subscription
        if (! $tenant->hasActiveSubscription()) {
            return response()->json([
                'message' => 'Your subscription has expired. Please renew to continue using the service.',
                'subscription_status' => $tenant->subscription_status,
            ], 403);
        }

        // If no specific resource check is requested, continue
        if (! $resource) {
            return $next($request);
        }

        $currentCount = 0;
        $canAdd = false;

        switch ($resource) {
            case 'currency':
                // Only enforce currency limit on POST (create) requests
                if (! $request->isMethod('post')) {
                    return $next($request);
                }
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

            case 'opening_balance':
                // Check if user can create multiple opening balances
                if ($request->isMethod('post') || $request->isMethod('put')) {
                    if ($request->has('opening_balances')) {
                        $currencyCount = count($request->input('opening_balances'));
                        if ($currencyCount > 1) {
                            // For now, allow multiple currencies (you can add specific logic here later)
                            $canAdd = true;
                        } else {
                            $canAdd = true;
                        }
                    } else {
                        $canAdd = true;
                    }
                } else {
                    $canAdd = true;
                }

                break;

            default:
                return $next($request);
        }

        if (! $canAdd) {
            $planName = $tenant->subscriptionPlan?->name ?? 'Unknown Plan';
            $maxLimit = $tenant->subscriptionPlan?->{"max_{$resource}s"} ?? 0;

            return response()->json([
                'message' => "You have reached the maximum limit of {$resource}s for your current plan ({$planName}).",
                'current_count' => $currentCount,
                'max_limit' => $maxLimit,
                'plan_name' => $planName,
                'upgrade_required' => true,
            ], 403);
        }

        return $next($request);
    }
}
