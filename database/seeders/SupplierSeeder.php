<?php

namespace Database\Seeders;

use App\Models\BusinessType;
use App\Models\Currency;
use App\Models\PaymentMethod;
use App\Models\PaymentTerm;
use App\Models\Supplier;
use App\Models\SupplierGroup;
use App\Models\Trade;
use Illuminate\Database\Seeder;

class SupplierSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create default related models if they don't exist
        $trade = Trade::firstOrCreate(
            ['code' => 'DEFAULT'],
            ['name' => 'Default Trade', 'active' => true]
        );

        $businessType = BusinessType::firstOrCreate(
            ['code' => 'DEFAULT'],
            ['name' => 'Default Business Type']
        );

        $paymentTerm = PaymentTerm::firstOrCreate(
            ['code' => 'DEFAULT'],
            ['name' => 'Default Payment Term', 'nb_days' => 30, 'active' => true]
        );

        $paymentMethod = PaymentMethod::firstOrCreate(
            ['code' => 'DEFAULT'],
            ['name' => 'Default Payment Method']
        );

        $currency = Currency::firstOrCreate(
            ['code' => 'USD'],
            [
                'name' => 'US Dollar',
                'iso_code' => 'USD',
                'rate' => 1.0000,
            ]
        );

        $supplierGroup = SupplierGroup::firstOrCreate(
            ['code' => 'DEFAULT'],
            ['name' => 'Default Supplier Group', 'active' => true]
        );

        // Create sample suppliers
        $suppliers = [
            [
                'first_name' => 'Global',
                'last_name' => 'Supplies Inc.',
                'company_name' => 'Global Supplies Inc.',
                'display_name' => 'Global Supplies Inc.',
                'phone1' => '+1-555-0101',
                'phone2' => '+1-555-0102',
                'file_number' => 'SUP001',
                'bar_code' => 'BAR001',
                'supplier_group_id' => $supplierGroup->id,
                'trade_id' => $trade->id,
                'business_type_id' => $businessType->id,
                'payment_term_id' => $paymentTerm->id,
                'payment_method_id' => $paymentMethod->id,
                'active' => true,
                'taxable' => false,
                'subjected_to_tax' => false,
                'allow_credit' => false,
                'accept_cheques' => false,
                'is_foreign' => false,
                'add_message' => false,
            ],
            [
                'first_name' => 'Tech',
                'last_name' => 'Distributors Ltd.',
                'company_name' => 'Tech Distributors Ltd.',
                'display_name' => 'Tech Distributors Ltd.',
                'phone1' => '+1-555-0201',
                'phone2' => '+1-555-0202',
                'file_number' => 'SUP002',
                'bar_code' => 'BAR002',
                'supplier_group_id' => $supplierGroup->id,
                'trade_id' => $trade->id,
                'business_type_id' => $businessType->id,
                'payment_term_id' => $paymentTerm->id,
                'payment_method_id' => $paymentMethod->id,
                'active' => true,
                'taxable' => true,
                'subjected_to_tax' => true,
                'added_tax' => 15.00,
                'allow_credit' => true,
                'accept_cheques' => true,
                'is_foreign' => false,
                'add_message' => false,
            ],
            [
                'first_name' => 'Fashion',
                'last_name' => 'Wholesale Co.',
                'company_name' => 'Fashion Wholesale Co.',
                'display_name' => 'Fashion Wholesale Co.',
                'phone1' => '+1-555-0301',
                'phone2' => '+1-555-0302',
                'file_number' => 'SUP003',
                'bar_code' => 'BAR003',
                'supplier_group_id' => $supplierGroup->id,
                'trade_id' => $trade->id,
                'business_type_id' => $businessType->id,
                'payment_term_id' => $paymentTerm->id,
                'payment_method_id' => $paymentMethod->id,
                'active' => true,
                'taxable' => false,
                'subjected_to_tax' => false,
                'allow_credit' => false,
                'accept_cheques' => false,
                'is_foreign' => false,
                'add_message' => false,
            ],
        ];

        foreach ($suppliers as $supplier) {
            Supplier::firstOrCreate(
                [
                    'first_name' => $supplier['first_name'],
                    'last_name' => $supplier['last_name'],
                    'phone1' => $supplier['phone1'],
                ],
                $supplier
            );
        }
    }
}
