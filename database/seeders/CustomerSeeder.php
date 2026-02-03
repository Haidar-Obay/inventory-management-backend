<?php

namespace Database\Seeders;

use App\Models\BusinessType;
use App\Models\CompanyCode;
use App\Models\CustomerGroup;
use App\Models\DistributionChannel;
use App\Models\MediaChannel;
use App\Models\MediaType;
use App\Models\PaymentMethod;
use App\Models\PaymentTerm;
use App\Models\Referrer;
use App\Models\SalesChannel;
use App\Models\Salesman;
use App\Models\Trade;
use Faker\Factory as Faker;
use Illuminate\Database\Seeder;

class CustomerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $faker = Faker::create();

        // Create a default customer group if none exists
        $customerGroup = CustomerGroup::firstOrCreate(
            ['name' => 'Default Group'],
            ['code' => 'DEFAULT', 'active' => true]
        );

        // Create a default salesman if none exists
        $salesman = Salesman::firstOrCreate(
            ['code' => 'DEFAULT'],
            [
                'name' => 'Default Salesman',
                'address' => $faker->address(),
                'phone1' => $faker->phoneNumber(),
                'phone2' => $faker->optional()->phoneNumber(),
                'email' => $faker->email(),
                'is_manager' => false,
                'is_supervisor' => false,
                'is_collector' => true,
                'fix_commission' => 0.00,
                'commission_by_item' => 0.00,
                'active' => true,
            ]
        );

        // Create a default payment term if none exists
        $paymentTerm = PaymentTerm::firstOrCreate(
            ['code' => 'NET30'],
            [
                'name' => 'Net 30 Days',
                'nb_days' => 30,
                'active' => true,
            ]
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

        // Create a default payment method if none exists
        $paymentMethod = PaymentMethod::firstOrCreate(
            ['code' => 'CASH'],
            [
                'name' => 'Cash',
                'is_credit_card' => false,
                'is_online_payment' => false,
                'active' => true,
            ]
        );

        // Create default related models if they don't exist
        $trade = Trade::firstOrCreate(
            ['code' => 'DEFAULT'],
            ['name' => 'Default Trade', 'active' => true]
        );

        $companyCode = CompanyCode::firstOrCreate(
            ['code' => 'DEFAULT'],
            ['name' => 'Default Company Code']
        );

        $businessType = BusinessType::firstOrCreate(
            ['code' => 'DEFAULT'],
            ['name' => 'Default Business Type']
        );

        $salesChannel = SalesChannel::firstOrCreate(
            ['code' => 'DEFAULT'],
            ['name' => 'Default Sales Channel']
        );

        $distributionChannel = DistributionChannel::firstOrCreate(
            ['code' => 'DEFAULT'],
            ['name' => 'Default Distribution Channel']
        );

        $mediaChannel = MediaChannel::firstOrCreate(
            ['code' => 'DEFAULT'],
            ['name' => 'Default Media Channel']
        );

        $mediaType = MediaType::firstOrCreate(
            ['name' => 'Default Media Type']
        );

        $referrer = Referrer::firstOrCreate(
            ['name' => 'Default Referrer']
        );
    }
}
