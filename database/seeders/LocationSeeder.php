<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Country;
use App\Models\Province;
use App\Models\City;
use App\Models\District;
use Faker\Factory as Faker;
use Illuminate\Support\Facades\DB;

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
            $country = Country::create($countryData);

            // Create 3-5 provinces for each country
            $provinceCount = rand(3, 5);
            for ($i = 0; $i < $provinceCount; $i++) {
                $province = Province::create([
                    'name' => $faker->unique()->city() . ' Province',
                    'country_id' => $country->id,
                ]);

                // Create 4-6 cities for each province
                $cityCount = rand(4, 6);
                for ($j = 0; $j < $cityCount; $j++) {
                    $city = City::create([
                        'name' => $faker->unique()->city(),
                        'country_id' => $country->id,
                        'province_id' => $province->id,
                    ]);

                    // Create 5-8 districts for each city
                    $districtCount = rand(5, 8);
                    for ($k = 0; $k < $districtCount; $k++) {
                        District::create([
                            'name' => $faker->unique()->streetName(),
                            'country_id' => $country->id,
                            'province_id' => $province->id,
                            'city_id' => $city->id,
                        ]);
                    }
                }
            }
        }
    }
}
