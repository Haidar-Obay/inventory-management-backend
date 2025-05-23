<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;

class TenantSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $tenant = Tenant::create([
            'id' => 'hadishokor',
            'name' => 'hadishokor',
            'email' => 'hadishokor@gmail.com',
        ]);

        // Create domain for the tenant
        $tenant->domains()->create([
            'domain' => "hadishokor." . env('CENTRAL_DOMAIN'),
        ]);

        // Initialize tenant context
        tenancy()->initialize($tenant);

        // Create owner user
        $user = User::create([
            'name' => "hadishokor_owner",
            'email' => 'hadishokor@gmail.com',
            'password' => Hash::make('12345678'),
            'role' => 'owner',
        ]);

        // Clear cache
        tenancy()->central(fn () => Cache::store('database')->forget('central_tenants_all'));

        // Store the tenant ID in the config for the location seeder to use
        config(['tenant.id' => $tenant->id]);
    }
}
