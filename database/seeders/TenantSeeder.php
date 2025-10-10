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
        $defaultPlan = SubscriptionPlan::where('code', 'default')->first();
        $startDate = now();
        $endDate = $startDate->copy()->addMonth();

        // Only seed the hadishokor tenant with the default plan
        $tenant = Tenant::create([
            'id' => 'hadishokor',
            'name' => 'hadishokor',
            'email' => 'hadishokor@gmail.com',
            'subscription_plan_id' => $defaultPlan->id,
            'subscription_start_date' => $startDate,
            'subscription_end_date' => $endDate,
            'subscription_status' => 'active',
            'auto_renew' => true,
            'last_billing_date' => $startDate,
            'next_billing_date' => $endDate,
        ]);
        $tenant->domains()->create([
            'domain' => 'hadishokor.'.env('CENTRAL_DOMAIN'),
        ]);

        // Assign Beauty Center and Stock Management modules to the tenant in CENTRAL context
        tenancy()->central(function () use ($tenant, $defaultPlan) {
            $beauty = Module::where('code', 'beauty_center')->first();
            $stock = Module::where('code', 'stock_management')->first();
            $syncData = [];
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
            if (!empty($syncData)) {
                $tenant->modules()->syncWithoutDetaching($syncData);
            }
        });

        // Initialize tenant context (for tenant DB setup and user/RBAC)
        tenancy()->initialize($tenant);
        \App\Jobs\CreateDefaultTableTemplates::dispatchSync();
        // Create the original owner user
        $owner = User::create([
            'name' => 'hadishokor_owner',
            'email' => 'hadishokor@gmail.com',
            'password' => Hash::make('12345678'),
            'active' => true,
        ]);

        // Bootstrap RBAC system automatically
        \App\Jobs\BootstrapTenantRbac::dispatchSync($owner->id);

        // Clear cache
        tenancy()->central(fn () => Cache::store('database')->forget('central_tenants_all'));
    }
}
