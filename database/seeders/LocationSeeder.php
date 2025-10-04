<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\Country;
use App\Models\District;
use App\Models\Zone;
use Faker\Factory as Faker;
use Illuminate\Database\Seeder;

class LocationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $faker = Faker::create();

        // Create countries
        $countries = [
            ['name' => 'Saudi Arabia'],
            ['name' => 'United Arab Emirates'],
            ['name' => 'Qatar'],
            ['name' => 'Kuwait'],
            ['name' => 'Bahrain'],
            ['name' => 'Oman'],
        ];

        foreach ($countries as $countryData) {
            Country::create($countryData);
        }

        // Create provinces
        $zoneCount = 20;
        for ($i = 0; $i < $zoneCount; $i++) {
            Zone::create([
                'name' => $faker->unique()->city().' Zone',
            ]);
        }

        // Create cities
        $cityCount = 30;
        for ($i = 0; $i < $cityCount; $i++) {
            City::create([
                'name' => $faker->unique()->city(),
            ]);
        }

        // Create districts
        $districtCount = 40;
        for ($i = 0; $i < $districtCount; $i++) {
            District::create([
                'name' => $faker->unique()->streetName(),
            ]);
        }
    }
}
