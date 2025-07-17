<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Tenant;
use App\Models\User;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;

class TenantSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $primePlan = SubscriptionPlan::where('code', 'prime')->first();
        $startDate = now();
        $endDate = $startDate->copy()->addMonth();

        // Only seed the hadishokor tenant with the prime plan
        $tenant = Tenant::create([
            'id' => 'hadishokor',
            'name' => 'hadishokor',
            'email' => 'hadishokor@gmail.com',
            'subscription_plan_id' => $primePlan->id,
            'subscription_start_date' => $startDate,
            'subscription_end_date' => $endDate,
            'subscription_status' => 'active',
            'auto_renew' => true,
            'last_billing_date' => $startDate,
            'next_billing_date' => $endDate,
        ]);
        $tenant->domains()->create([
            'domain' => 'hadishokor.' . env('CENTRAL_DOMAIN'),
        ]);
        tenancy()->initialize($tenant);
        User::create([
            'name' => 'hadishokor_owner',
            'email' => 'hadishokor@gmail.com',
            'password' => Hash::make('12345678'),
            'role' => 'owner',
        ]);

        // Clear cache
        tenancy()->central(fn () => Cache::store('database')->forget('central_tenants_all'));
    }
}
