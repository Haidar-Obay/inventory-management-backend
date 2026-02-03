<?php

namespace Database\Seeders;

use App\Models\Module;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

class TenantSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get both plans
        $primePlan = SubscriptionPlan::where('code', 'prime')->first();
        $defaultPlan = SubscriptionPlan::where('code', 'default')->first();

        if (! $primePlan || ! $defaultPlan) {
            throw new \Exception('Subscription plans not found. Please run SubscriptionPlanSeeder first.');
        }

        $startDate = now();
        $endDate = $startDate->copy()->addMonth();

        // Create tenant with Prime Plan (multi-currency support)
        $primeTenant = Tenant::create([
            'id' => 'brain',
            'name' => 'brain',
            'email' => 'brain@gmail.com',
            'subscription_plan_id' => $primePlan->id,
            'subscription_start_date' => $startDate,
            'subscription_end_date' => $endDate,
            'subscription_status' => 'active',
            'auto_renew' => true,
            'last_billing_date' => $startDate,
            'next_billing_date' => $endDate,
        ]);
        $primeTenant->domains()->create([
            'domain' => 'brain.'.env('CENTRAL_DOMAIN'),
        ]);

        // Assign modules to Prime tenant
        tenancy()->central(function () use ($primeTenant, $primePlan) {
            $general = Module::where('code', 'general_module')->first();
            $beauty = Module::where('code', 'beauty_center')->first();
            $stock = Module::where('code', 'stock_management')->first();
            $syncData = [];

            if ($general) {
                $syncData[$general->id] = [
                    'assigned_price' => 0.0,
                    'is_included' => true,
                    'subscription_plan_id' => $primePlan->id,
                ];
            }

            if ($beauty) {
                $syncData[$beauty->id] = [
                    'assigned_price' => 0.0,
                    'is_included' => true,
                    'subscription_plan_id' => $primePlan->id,
                ];
            }
            if ($stock) {
                $syncData[$stock->id] = [
                    'assigned_price' => 0.0,
                    'is_included' => true,
                    'subscription_plan_id' => $primePlan->id,
                ];
            }
            if (! empty($syncData)) {
                $primeTenant->modules()->syncWithoutDetaching($syncData);
            }
        });

        // Initialize Prime tenant context
        tenancy()->initialize($primeTenant);
        \App\Jobs\CreateDefaultTableTemplates::dispatchSync();

        // Create owner user for Prime tenant
        $primeOwner = User::create([
            'name' => 'brain_owner',
            'email' => 'brain@gmail.com',
            'password' => Hash::make('12345678'),
            'active' => true,
        ]);

        // Bootstrap RBAC for Prime tenant
        \App\Jobs\BootstrapTenantRbac::dispatchSync($primeOwner->id);

        // Create tenant with Default Plan (single currency)
        $defaultTenant = Tenant::create([
            'id' => 'default',
            'name' => 'default',
            'email' => 'default@gmail.com',
            'subscription_plan_id' => $defaultPlan->id,
            'subscription_start_date' => $startDate,
            'subscription_end_date' => $endDate,
            'subscription_status' => 'active',
            'auto_renew' => true,
            'last_billing_date' => $startDate,
            'next_billing_date' => $endDate,
        ]);
        $defaultTenant->domains()->create([
            'domain' => 'default.'.env('CENTRAL_DOMAIN'),
        ]);

        // Assign modules to Default tenant
        tenancy()->central(function () use ($defaultTenant, $defaultPlan) {
            $general = Module::where('code', 'general_module')->first();
            $beauty = Module::where('code', 'beauty_center')->first();
            $stock = Module::where('code', 'stock_management')->first();
            $syncData = [];

            if ($general) {
                $syncData[$general->id] = [
                    'assigned_price' => 0.0,
                    'is_included' => true,
                    'subscription_plan_id' => $defaultPlan->id,
                ];
            }

            if ($beauty) {
                $syncData[$beauty->id] = [
                    'assigned_price' => 0.0,
                    'is_included' => true,
                    'subscription_plan_id' => $defaultPlan->id,
                ];
            }
            if ($stock) {
                $syncData[$stock->id] = [
                    'assigned_price' => 0.0,
                    'is_included' => true,
                    'subscription_plan_id' => $defaultPlan->id,
                ];
            }
            if (! empty($syncData)) {
                $defaultTenant->modules()->syncWithoutDetaching($syncData);
            }
        });

        // Initialize Default tenant context
        tenancy()->initialize($defaultTenant);
        \App\Jobs\CreateDefaultTableTemplates::dispatchSync();

        // Create owner user for Default tenant
        $defaultOwner = User::create([
            'name' => 'default_owner',
            'email' => 'default@gmail.com',
            'password' => Hash::make('12345678'),
            'active' => true,
        ]);

        // Bootstrap RBAC for Default tenant
        \App\Jobs\BootstrapTenantRbac::dispatchSync($defaultOwner->id);

        // Clear cache
        tenancy()->central(fn () => Cache::store('database')->forget('central_tenants_all'));
    }
}
