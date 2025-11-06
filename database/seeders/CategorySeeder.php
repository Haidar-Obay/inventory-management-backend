<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create main categories
        $mainCategories = [
            ['code' => 'CAT001', 'name' => 'Electronics', 'active' => true],
            ['code' => 'CAT002', 'name' => 'Clothing', 'active' => true],
            ['code' => 'CAT003', 'name' => 'Food', 'active' => true],
            ['code' => 'CAT004', 'name' => 'Home', 'active' => true],
        ];

        $categoryIds = [];

        foreach ($mainCategories as $category) {
            $created = Category::firstOrCreate(
                ['code' => $category['code']],
                $category
            );
            $categoryIds[$category['code']] = $created->id;
        }

        // Create subcategories
        $subCategories = [
            ['code' => 'CAT005', 'name' => 'Mobile Phones', 'subcategory_of' => $categoryIds['CAT001'], 'active' => true],
            ['code' => 'CAT006', 'name' => 'Laptops', 'subcategory_of' => $categoryIds['CAT001'], 'active' => true],
            ['code' => 'CAT007', 'name' => 'Men\'s Clothing', 'subcategory_of' => $categoryIds['CAT002'], 'active' => true],
            ['code' => 'CAT008', 'name' => 'Women\'s Clothing', 'subcategory_of' => $categoryIds['CAT002'], 'active' => true],
            ['code' => 'CAT009', 'name' => 'Furniture', 'subcategory_of' => $categoryIds['CAT004'], 'active' => true],
        ];

        foreach ($subCategories as $subCategory) {
            Category::firstOrCreate(
                ['code' => $subCategory['code']],
                $subCategory
            );
        }
    }
}
