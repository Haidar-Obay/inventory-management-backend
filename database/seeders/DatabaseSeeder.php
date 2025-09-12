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
            'name' => 'Admin User',
            'email' => 'binbotadmin@brain.com',
            'password' => bcrypt('br@in_binbot'),
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
