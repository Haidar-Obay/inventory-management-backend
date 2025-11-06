<?php

namespace Database\Seeders;

use App\Models\ProductLine;
use Illuminate\Database\Seeder;

class ProductLineSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $productLines = [
            ['code' => 'PL001', 'name' => 'Electronics', 'active' => true],
            ['code' => 'PL002', 'name' => 'Clothing', 'active' => true],
            ['code' => 'PL003', 'name' => 'Food & Beverages', 'active' => true],
            ['code' => 'PL004', 'name' => 'Home & Garden', 'active' => true],
            ['code' => 'PL005', 'name' => 'Health & Beauty', 'active' => true],
            ['code' => 'PL006', 'name' => 'Sports & Outdoors', 'active' => true],
            ['code' => 'PL007', 'name' => 'Automotive', 'active' => true],
        ];

        foreach ($productLines as $line) {
            ProductLine::firstOrCreate(
                ['code' => $line['code']],
                $line
            );
        }
    }
}
