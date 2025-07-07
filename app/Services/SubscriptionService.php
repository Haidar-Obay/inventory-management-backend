<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\SubscriptionPlan;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SubscriptionService
{
    public function assignPlanToTenant(Tenant $tenant, SubscriptionPlan $plan, bool $isTrial = true): bool
    {
        try {
            DB::beginTransaction();

            $startDate = now();
            $endDate = $isTrial ? $startDate->copy()->addDays(30) : $startDate->copy()->addMonth();

            $tenant->update([
                'subscription_plan_id' => $plan->id,
                'subscription_start_date' => $startDate,
                'subscription_end_date' => $endDate,
                'subscription_status' => $isTrial ? 'trial' : 'active',
                'auto_renew' => !$isTrial,
                'last_billing_date' => $startDate,
                'next_billing_date' => $endDate,
            ]);

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function upgradeTenantPlan(Tenant $tenant, SubscriptionPlan $newPlan): bool
    {
        try {
            DB::beginTransaction();

            $startDate = now();
            $endDate = $startDate->copy()->addMonth();

            $tenant->update([
                'subscription_plan_id' => $newPlan->id,
                'subscription_start_date' => $startDate,
                'subscription_end_date' => $endDate,
                'subscription_status' => 'active',
                'auto_renew' => true,
                'last_billing_date' => $startDate,
                'next_billing_date' => $endDate,
            ]);

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function cancelTenantSubscription(Tenant $tenant): bool
    {
        try {
            $tenant->update([
                'subscription_status' => 'cancelled',
                'auto_renew' => false,
            ]);

            return true;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function checkAndUpdateExpiredSubscriptions(): array
    {
        $expiredTenants = Tenant::where('subscription_status', '!=', 'cancelled')
            ->where('subscription_end_date', '<', now())
            ->get();

        $updated = 0;
        $errors = [];

        foreach ($expiredTenants as $tenant) {
            try {
                $tenant->update([
                    'subscription_status' => 'expired',
                    'auto_renew' => false,
                ]);
                $updated++;
            } catch (\Exception $e) {
                $errors[] = "Failed to update tenant {$tenant->id}: " . $e->getMessage();
            }
        }

        return [
            'updated_count' => $updated,
            'errors' => $errors
        ];
    }

    public function getTenantSubscriptionInfo(Tenant $tenant): array
    {
        $plan = $tenant->subscriptionPlan;

        return [
            'plan_name' => $plan?->name ?? 'No Plan',
            'plan_code' => $plan?->code ?? 'none',
            'status' => $tenant->subscription_status,
            'start_date' => $tenant->subscription_start_date?->format('Y-m-d'),
            'end_date' => $tenant->subscription_end_date?->format('Y-m-d'),
            'auto_renew' => $tenant->auto_renew,
            'is_active' => $tenant->hasActiveSubscription(),
            'is_expired' => $tenant->isSubscriptionExpired(),
            'features' => $tenant->getSubscriptionFeatures(),
            'limits' => [
                'max_currencies' => $plan?->max_currencies ?? 0,
                'max_users' => $plan?->max_users ?? 0,
                'max_customers' => $plan?->max_customers ?? 0,
            ],
            'current_usage' => [
                'currencies' => $this->getCurrentCurrencyCount($tenant),
                'users' => $this->getCurrentUserCount($tenant),
                'customers' => $this->getCurrentCustomerCount($tenant),
            ]
        ];
    }

    private function getCurrentCurrencyCount(Tenant $tenant): int
    {
        tenancy()->initialize($tenant);
        return \App\Models\Currency::count();
    }

    private function getCurrentUserCount(Tenant $tenant): int
    {
        tenancy()->initialize($tenant);
        return \App\Models\User::count();
    }

    private function getCurrentCustomerCount(Tenant $tenant): int
    {
        tenancy()->initialize($tenant);
        return \App\Models\Customer::count();
    }

    public function canTenantAddResource(Tenant $tenant, string $resource, int $currentCount = 0): bool
    {
        switch ($resource) {
            case 'currency':
                return $tenant->canAddCurrency($currentCount);
            case 'user':
                return $tenant->canAddUser($currentCount);
            case 'customer':
                return $tenant->canAddCustomer($currentCount);
            default:
                return false;
        }
    }
}
