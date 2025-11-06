<?php

namespace Database\Seeders;

use App\Models\Brand;
use Illuminate\Database\Seeder;

class BrandSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create main brands
        $mainBrands = [
            ['code' => 'BR001', 'name' => 'TechCorp', 'active' => true],
            ['code' => 'BR002', 'name' => 'FashionPlus', 'active' => true],
            ['code' => 'BR003', 'name' => 'HomeStyle', 'active' => true],
            ['code' => 'BR004', 'name' => 'FoodMaster', 'active' => true],
        ];

        $brandIds = [];

        foreach ($mainBrands as $brand) {
            $created = Brand::firstOrCreate(
                ['code' => $brand['code']],
                $brand
            );
            $brandIds[$brand['code']] = $created->id;
        }

        // Create sub-brands
        $subBrands = [
            ['code' => 'BR005', 'name' => 'TechCorp Pro', 'sub_brand_of' => $brandIds['BR001'], 'active' => true],
            ['code' => 'BR006', 'name' => 'TechCorp Lite', 'sub_brand_of' => $brandIds['BR001'], 'active' => true],
            ['code' => 'BR007', 'name' => 'FashionPlus Elite', 'sub_brand_of' => $brandIds['BR002'], 'active' => true],
        ];

        foreach ($subBrands as $subBrand) {
            Brand::firstOrCreate(
                ['code' => $subBrand['code']],
                $subBrand
            );
        }
    }
}
