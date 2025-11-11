<?php

namespace Database\Seeders;

use App\Enums\ItemType;
use App\Models\Brand;
use App\Models\Category;
use App\Models\CompanyCode;
use App\Models\Item;
use App\Models\ProductLine;
use App\Models\Supplier;
use App\Models\Trade;
use App\Models\UnitOfMeasurement;
use Illuminate\Database\Seeder;

class ItemSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get or create required related models
        $trade = Trade::firstOrCreate(
            ['code' => 'DEFAULT'],
            ['name' => 'Default Trade', 'active' => true]
        );

        $companyCode = CompanyCode::firstOrCreate(
            ['code' => 'DEFAULT'],
            ['name' => 'Default Company Code']
        );

        $productLine = ProductLine::firstOrCreate(
            ['code' => 'PL001'],
            ['name' => 'Electronics', 'active' => true]
        );

        $category = Category::firstOrCreate(
            ['code' => 'CAT001'],
            ['name' => 'Electronics', 'active' => true]
        );

        $brand = Brand::firstOrCreate(
            ['code' => 'BR001'],
            ['name' => 'TechCorp', 'active' => true]
        );

        // Get unit of measurements
        $baseUom = UnitOfMeasurement::where('name', 'Piece')->first();
        $purchaseUom = UnitOfMeasurement::where('name', 'Box')->first();
        $salesUom = UnitOfMeasurement::where('name', 'Piece')->first();

        if (! $baseUom) {
            $baseUom = UnitOfMeasurement::first();
        }
        if (! $purchaseUom) {
            $purchaseUom = $baseUom;
        }
        if (! $salesUom) {
            $salesUom = $baseUom;
        }

        // Get suppliers
        $suppliers = Supplier::take(2)->get();

        // Create sample items with different types
        $items = [
            [
                'code' => 'ITEM001',
                'name' => 'Sample Inventory Item',
                'type' => ItemType::INVENTORY->value,
                'trade_id' => $trade->id,
                'company_code_id' => $companyCode->id,
                'product_line_id' => $productLine->id,
                'category_id' => $category->id,
                'brand_id' => $brand->id,
                'base_uom_id' => $baseUom->id,
                'purchase_uom_id' => $purchaseUom->id,
                'sales_uom_id' => $salesUom->id,
                'discount_percent' => 5.00,
                'max_discount' => 100.00,
                'purchase_parameters' => ['min_order' => 10, 'lead_time' => 7],
                'purchase_description' => 'Purchase description for sample inventory item',
                'sales_parameters' => ['min_quantity' => 1, 'max_quantity' => 100],
                'sales_description' => 'Sales description for sample inventory item',
                'pos_description' => 'POS description for sample inventory item',
            ],
            [
                'code' => 'ITEM002',
                'name' => 'Sample Non-Inventory Item',
                'type' => ItemType::NON_INVENTORY->value,
                'trade_id' => $trade->id,
                'company_code_id' => $companyCode->id,
                'product_line_id' => $productLine->id,
                'category_id' => $category->id,
                'brand_id' => $brand->id,
                'base_uom_id' => $baseUom->id,
                'purchase_uom_id' => $purchaseUom->id,
                'sales_uom_id' => $salesUom->id,
                'discount_percent' => 0.00,
                'purchase_parameters' => null,
                'sales_parameters' => null,
            ],
            [
                'code' => 'ITEM003',
                'name' => 'Sample Service Item',
                'type' => ItemType::SERVICE->value,
                'trade_id' => $trade->id,
                'company_code_id' => $companyCode->id,
                'base_uom_id' => $baseUom->id,
                'sales_uom_id' => $salesUom->id,
                'discount_percent' => 10.00,
                'sales_parameters' => ['duration' => 60, 'price_per_hour' => 50.00],
                'sales_description' => 'Service item description',
            ],
            [
                'code' => 'ITEM004',
                'name' => 'Sample Bundle Item',
                'type' => ItemType::BUNDLE->value,
                'trade_id' => $trade->id,
                'company_code_id' => $companyCode->id,
                'product_line_id' => $productLine->id,
                'category_id' => $category->id,
                'brand_id' => $brand->id,
                'base_uom_id' => $baseUom->id,
                'sales_uom_id' => $salesUom->id,
                'discount_percent' => 15.00,
                'max_discount' => 200.00,
                'sales_parameters' => ['bundle_items' => ['ITEM001', 'ITEM002']],
                'sales_description' => 'Bundle item description',
            ],
            [
                'code' => 'ITEM005',
                'name' => 'Sample Medical Service',
                'type' => ItemType::MEDICAL_SERVICE->value,
                'trade_id' => $trade->id,
                'company_code_id' => $companyCode->id,
                'base_uom_id' => $baseUom->id,
                'sales_uom_id' => $salesUom->id,
                'discount_percent' => 0.00,
                'sales_parameters' => ['consultation_fee' => 100.00],
                'sales_description' => 'Medical service description',
            ],
        ];

        $createdItems = [];

        foreach ($items as $itemData) {
            $item = Item::firstOrCreate(
                ['code' => $itemData['code']],
                $itemData
            );
            $createdItems[] = $item;
        }

        // Create a parent-child relationship
        if (count($createdItems) >= 2) {
            $parentItem = $createdItems[0];
            $childItem = $createdItems[1];
            $childItem->update(['parent_id' => $parentItem->id]);
        }

        // Attach suppliers to items (many-to-many)
        if ($suppliers->count() > 0 && count($createdItems) > 0) {
            foreach ($createdItems as $index => $item) {
                if ($index < $suppliers->count()) {
                    $supplier = $suppliers[$index];
                    $item->suppliers()->syncWithoutDetaching([
                        $supplier->id => [
                            'original_code' => 'SUP-'.$item->code,
                            'currency' => 'USD',
                            'cost' => 50.00 + ($index * 10),
                            'is_primary' => $index === 0,
                        ],
                    ]);
                }
            }
        }

        // Attach unit of measurements to items (many-to-many)
        if ($baseUom && count($createdItems) > 0) {
            foreach ($createdItems as $index => $item) {
                $item->unitOfMeasurements()->syncWithoutDetaching([
                    $baseUom->id => [
                        'operation' => 'multiply',
                        'conversion' => 1,
                        'barcodes' => ['BC-'.$item->code.'-001', 'BC-'.$item->code.'-002'],
                        'price_1' => 100.00 + ($index * 10),
                        'price_2' => 90.00 + ($index * 10),
                        'price_3' => 80.00 + ($index * 10),
                        'price_4' => 70.00 + ($index * 10),
                        'price_5' => 60.00 + ($index * 10),
                        'price_6' => 50.00 + ($index * 10),
                        'gross_volume' => 1.5 + ($index * 0.1),
                        'gross_weight' => 2.0 + ($index * 0.1),
                        'net_volume' => 1.2 + ($index * 0.1),
                        'net_weight' => 1.8 + ($index * 0.1),
                    ],
                ]);
                // Optionally attach an additional UOM (e.g., Box) with a sample conversion
                if ($purchaseUom && $purchaseUom->id !== $baseUom->id) {
                    $item->unitOfMeasurements()->syncWithoutDetaching([
                        $purchaseUom->id => [
                            'operation' => 'multiply',
                            'conversion' => 10, // example: 1 box = 10 pieces
                            'barcodes' => ['BC-'.$item->code.'-BOX-001'],
                            'price_1' => 950.00 + ($index * 50),
                            'gross_volume' => 12.0 + ($index * 0.5),
                            'gross_weight' => 20.0 + ($index * 0.5),
                            'net_volume' => 10.0 + ($index * 0.5),
                            'net_weight' => 18.0 + ($index * 0.5),
                        ],
                    ]);
                }
            }
        }
    }
}
