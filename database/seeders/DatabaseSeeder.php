<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $this->call([
            SubscriptionPlanSeeder::class,
            TenantSeeder::class,
        ]);

        // Run location seeder in tenant context
        $tenant = \App\Models\Tenant::first();
        if ($tenant) {
            $tenant->run(function () {
                $this->call([
                    LocationSeeder::class,
                    CustomerSeeder::class,
                    ProjectSeeder::class,
                    RoomSeeder::class,
                ]);
            });
        }
    }
}
