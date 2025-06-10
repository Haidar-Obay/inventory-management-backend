<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Salesman;
use App\Models\PaymentTerm;
use App\Models\PaymentMethod;
use App\Models\Address;
use Faker\Factory as Faker;

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
            ['is_inactive' => false]
        );

        // Create a default salesman if none exists
        $salesman = Salesman::firstOrCreate(
            ['name' => 'Default Salesman'],
            ['is_inactive' => false]
        );

        // Create a default payment term if none exists
        $paymentTerm = PaymentTerm::firstOrCreate(
            ['name' => 'Net 30'],
            [
                'nb_days' => 30,
                'is_inactive' => false
            ]
        );

        // Create a default payment method if none exists
        $paymentMethod = PaymentMethod::firstOrCreate(
            ['name' => 'Bank Transfer'],
            [
                'is_credit_card' => false,
                'is_inactive' => false
            ]
        );

        // Create 10 sample customers
        for ($i = 0; $i < 10; $i++) {
            // Create billing address
            $billingAddress = Address::create([
                'address_line1' => $faker->streetAddress(),
                'city' => $faker->city(),
                'postal_code' => $faker->postcode(),
                'country' => $faker->country(),
            ]);

            // Create shipping address
            $shippingAddress = Address::create([
                'address_line1' => $faker->streetAddress(),
                'city' => $faker->city(),
                'postal_code' => $faker->postcode(),
                'country' => $faker->country(),
            ]);

            Customer::create([
                'name' => $faker->company(),
                'customer_group_id' => $customerGroup->id,
                'salesman_id' => $salesman->id,
                'payment_term_id' => $paymentTerm->id,
                'primary_payment_method_id' => $paymentMethod->id,
                'billing_address_id' => $billingAddress->id,
                'shipping_address_id' => $shippingAddress->id,
                'email' => $faker->companyEmail(),
                'phone' => $faker->phoneNumber(),
                'tax_number' => $faker->numerify('TAX-####'),
                'credit_limit' => $faker->numberBetween(1000, 10000),
                'is_inactive' => false,
            ]);
        }

        $this->command->info('Customers seeded successfully!');
    }
}
