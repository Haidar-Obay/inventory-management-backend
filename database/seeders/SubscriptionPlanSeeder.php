<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

class SubscriptionPlanSeeder extends Seeder
{
    public function run(): void
    {
        // Create Default Plan
        SubscriptionPlan::create([
            'name' => 'Default Plan',
            'code' => 'default',
            'description' => 'Basic plan with limited features',
            'price' => 0.00,
            'billing_cycle' => 'monthly',
            'is_active' => true,
            'is_default' => true,
            'max_currencies' => 1,
            'max_users' => 5,
            'max_customers' => 100,
            'features' => [
                'basic_inventory' => true,
                'customer_management' => true,
                'basic_reporting' => true,
                'single_currency' => true,
                'email_support' => true,
                'multi_currency' => false,
                'advanced_reporting' => false,
                'priority_support' => false,
                'api_access' => false,
                'custom_integrations' => false,
            ],
        ]);

        // Create Prime Plan
        SubscriptionPlan::create([
            'name' => 'Prime Plan',
            'code' => 'prime',
            'description' => 'Advanced plan with multiple currencies and premium features',
            'price' => 99.99,
            'billing_cycle' => 'monthly',
            'is_active' => true,
            'is_default' => false,
            'max_currencies' => 10,
            'max_users' => 50,
            'max_customers' => 1000,
            'features' => [
                'basic_inventory' => true,
                'customer_management' => true,
                'basic_reporting' => true,
                'single_currency' => true,
                'email_support' => true,
                'multi_currency' => true,
                'advanced_reporting' => true,
                'priority_support' => true,
                'api_access' => true,
                'custom_integrations' => true,
                'advanced_analytics' => true,
                'custom_branding' => true,
                'data_export' => true,
                'backup_restore' => true,
            ],
        ]);
    }
}
