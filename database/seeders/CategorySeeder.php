<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\SubCategory;
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

        // Create child categories (no subcategory_of; links go in sub_categories)
        $childCategories = [
            ['code' => 'CAT005', 'name' => 'Mobile Phones', 'active' => true],
            ['code' => 'CAT006', 'name' => 'Laptops', 'active' => true],
            ['code' => 'CAT007', 'name' => 'Men\'s Clothing', 'active' => true],
            ['code' => 'CAT008', 'name' => 'Women\'s Clothing', 'active' => true],
            ['code' => 'CAT009', 'name' => 'Furniture', 'active' => true],
        ];

        foreach ($childCategories as $child) {
            $created = Category::firstOrCreate(
                ['code' => $child['code']],
                $child
            );
            $categoryIds[$child['code']] = $created->id;
        }

        // Create sub_category rows: name + category_id (parent)
        $links = [
            ['CAT001', 'CAT005'], ['CAT001', 'CAT006'],
            ['CAT002', 'CAT007'], ['CAT002', 'CAT008'],
            ['CAT004', 'CAT009'],
        ];
        foreach ($links as [$parentCode, $childCode]) {
            $childName = collect($childCategories)->firstWhere('code', $childCode)['name']
                ?? collect($mainCategories)->firstWhere('code', $childCode)['name']
                ?? 'Sub';
            SubCategory::firstOrCreate(
                [
                    'category_id' => $categoryIds[$parentCode],
                    'name' => $childName,
                ]
            );
        }
    }
}
