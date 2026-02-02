<?php

namespace Database\Seeders;

use App\Models\AvailableCurrency;
use Illuminate\Database\Seeder;

class AvailableCurrencySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $currencies = [
            [
                'code' => 'USD',
                'name' => 'US Dollar',
                'iso_code' => 'USD',
                'symbol' => '$',
                'is_active' => true,
            ],
            [
                'code' => 'EUR',
                'name' => 'Euro',
                'iso_code' => 'EUR',
                'symbol' => '€',
                'is_active' => true,
            ],
            [
                'code' => 'LBP',
                'name' => 'Lebanese Pound',
                'iso_code' => 'LBP',
                'symbol' => 'ل.ل',
                'is_active' => true,
            ],
        ];

        foreach ($currencies as $currency) {
            AvailableCurrency::firstOrCreate(
                ['code' => $currency['code']],
                $currency
            );
        }
    }
}
