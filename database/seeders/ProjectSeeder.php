<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Project;
use App\Models\Customer;
use Carbon\Carbon;
use Faker\Factory as Faker;

class ProjectSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $faker = Faker::create();

        // Get all customer IDs
        $customerIds = Customer::pluck('id')->toArray();

        if (empty($customerIds)) {
            $this->command->info('No customers found. Please run CustomerSeeder first.');
            return;
        }

        // Create 20 sample projects
        for ($i = 0; $i < 20; $i++) {
            $startDate = Carbon::now()->subDays(rand(0, 30));
            $expectedDate = $startDate->copy()->addDays(rand(30, 90));
            $endDate = rand(0, 1) ? $expectedDate->copy()->addDays(rand(-10, 10)) : null;

            Project::create([
                'name' => $faker->words(3, true) . ' Project',
                'customer_id' => $faker->randomElement($customerIds),
                'start_date' => $startDate,
                'expected_date' => $expectedDate,
                'end_date' => $endDate,
            ]);
        }

        $this->command->info('Projects seeded successfully!');
    }
}
