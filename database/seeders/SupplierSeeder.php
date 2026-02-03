<?php

namespace Database\Seeders;

use App\Models\BusinessType;
use App\Models\PaymentMethod;
use App\Models\PaymentTerm;
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

        // Create payment term with 0 days (for immediate payment)
        PaymentTerm::firstOrCreate(
            ['code' => 'IMMEDIATE'],
            [
                'name' => 'Immediate Payment',
                'nb_days' => 0,
                'active' => true,
            ]
        );

        $paymentMethod = PaymentMethod::firstOrCreate(
            ['code' => 'DEFAULT'],
            ['name' => 'Default Payment Method']
        );

        // $supplierGroup = SupplierGroup::firstOrCreate(
        //     ['code' => 'DEFAULT'],
        //     ['name' => 'Default Supplier Group', 'active' => true]
        // );
    }
}
