<?php

namespace Database\Seeders;

use App\Enums\PageCode;
use App\Models\Module;
use App\Models\ModulePage;
use Illuminate\Database\Seeder;

class ModuleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $modules = [
            [
                'name' => 'Beauty Center',
                'code' => 'beauty_center',
                'description' => 'Complete beauty center management including appointments, services, and customer management',
                'icon' => 'spa',
                'sort_order' => 1,
                'active' => true,
                'pages' => [
                    ['name' => 'Sche tics', 'code' => PageCode::DASHBOARD_ANALYTICS_PAGE->value, 'path' => '/main/dashboard/analytics', 'order' => 3, 'is_public' => false],
                    ['name' => 'Reports', 'code' => PageCode::DASHBOARD_REPORTS_PAGE->value, 'path' => '/main/dashboard/reports', 'order' => 4, 'is_public' => false],
                    ['name' => 'Service Categories', 'code' => PageCode::SERVICES_CATEGORIES->value, 'path' => '/main/mainfiles/services?tab=0', 'order' => 10, 'is_public' => false],
                    ['name' => 'Services', 'code' => PageCode::SERVICES_SERVICES->value, 'path' => '/main/mainfiles/services?tab=1', 'order' => 11, 'is_public' => false],
                    ['name' => 'Customer Groups', 'code' => PageCode::CUSTOMERS_GROUPS->value, 'path' => '/main/mainfiles/customer?tab=0', 'order' => 12, 'is_public' => false],
                    ['name' => 'Salesmen', 'code' => PageCode::CUSTOMERS_SALESMEN->value, 'path' => '/main/mainfiles/customer?tab=1', 'order' => 13, 'is_public' => false],
                    ['name' => 'Customers', 'code' => PageCode::CUSTOMERS_CUSTOMERS->value, 'path' => '/main/mainfiles/customer?tab=2', 'order' => 14, 'is_public' => false],
                    ['name' => 'Customer Master Lists', 'code' => PageCode::CUSTOMERS_MASTER_LISTS->value, 'path' => '/main/mainfiles/customer?tab=3', 'order' => 15, 'is_public' => false],
                    // Settings pages
                    ['name' => 'User Management', 'code' => PageCode::USER_MANAGEMENT_PAGE->value, 'path' => '/main/settings/userManagement?tab=0', 'order' => 90, 'is_public' => false],
                    ['name' => 'Permission', 'code' => PageCode::PERMISSIONS_PAGE->value, 'path' => '/main/settings/userManagement?tab=1', 'order' => 91, 'is_public' => false],
                    ['name' => 'Role Management', 'code' => PageCode::ROLES_PAGE->value, 'path' => '/main/settings/userManagement?tab=2', 'order' => 92, 'is_public' => false],
                    ['name' => 'System Settings', 'code' => PageCode::SYSTEM_SETTINGS_PAGE->value, 'path' => '/main/settings/systemSettings', 'order' => 93, 'is_public' => false],
                ],
            ],
            [
                'name' => 'Stock Management',
                'code' => 'stock_management',
                'description' => 'Inventory and stock management system with real-time tracking',
                'icon' => 'inventory',
                'sort_order' => 2,
                'active' => true,
                'pages' => [
                    // Dashboard Pages
                    ['name' => 'Dashboard', 'code' => PageCode::DASHBOARD_PAGE->value, 'path' => '/main/dashboard', 'order' => 0, 'is_public' => false],
                    ['name' => 'Overview', 'code' => PageCode::DASHBOARD_OVERVIEW_PAGE->value, 'path' => '/main/dashboard/overview', 'order' => 1, 'is_public' => false],

                    // Inventory Management
                    ['name' => 'Product Lines', 'code' => PageCode::ITEMS_PRODUCT_LINES->value, 'path' => '/main/mainfiles/items?tab=0', 'order' => 10, 'is_public' => false],
                    ['name' => 'Categories', 'code' => PageCode::ITEMS_CATEGORIES->value, 'path' => '/main/mainfiles/items?tab=1', 'order' => 11, 'is_public' => false],
                    ['name' => 'Brands', 'code' => PageCode::ITEMS_BRANDS->value, 'path' => '/main/mainfiles/items?tab=2', 'order' => 12, 'is_public' => false],
                    ['name' => 'Items', 'code' => PageCode::ITEMS_ITEMS->value, 'path' => '/main/mainfiles/items?tab=3', 'order' => 13, 'is_public' => false],

                    // Suppliers and Customers
                    ['name' => 'Suppliers', 'code' => PageCode::SUPPLIERS_SUPPLIERS->value, 'path' => '/main/mainfiles/supplier?tab=1', 'order' => 20, 'is_public' => false],
                    ['name' => 'Customer Groups', 'code' => PageCode::CUSTOMERS_GROUPS->value, 'path' => '/main/mainfiles/customer?tab=0', 'order' => 21, 'is_public' => false],
                    ['name' => 'Salesmen', 'code' => PageCode::CUSTOMERS_SALESMEN->value, 'path' => '/main/mainfiles/customer?tab=1', 'order' => 22, 'is_public' => false],
                    ['name' => 'Customers', 'code' => PageCode::CUSTOMERS_CUSTOMERS->value, 'path' => '/main/mainfiles/customer?tab=2', 'order' => 23, 'is_public' => false],
                    ['name' => 'Customer Master Lists', 'code' => PageCode::CUSTOMERS_MASTER_LISTS->value, 'path' => '/main/mainfiles/customer?tab=3', 'order' => 24, 'is_public' => false],

                    // Settings pages
                    ['name' => 'User Management', 'code' => PageCode::USER_MANAGEMENT_PAGE->value, 'path' => '/main/settings/userManagement?tab=0', 'order' => 90, 'is_public' => false],
                    ['name' => 'Permission', 'code' => PageCode::PERMISSIONS_PAGE->value, 'path' => '/main/settings/userManagement?tab=1', 'order' => 91, 'is_public' => false],
                    ['name' => 'Role Management', 'code' => PageCode::ROLES_PAGE->value, 'path' => '/main/settings/userManagement?tab=2', 'order' => 92, 'is_public' => false],
                    ['name' => 'System Settings', 'code' => PageCode::SYSTEM_SETTINGS_PAGE->value, 'path' => '/main/settings/systemSettings', 'order' => 93, 'is_public' => false],
                ],
            ],
            [
                'name' => 'Customer Management',
                'code' => 'customer_management',
                'description' => 'Customer relationship management and contact tracking',
                'icon' => 'people',
                'sort_order' => 3,
                'active' => true,
                'pages' => [
                    ['name' => 'Customers', 'code' => 'customers', 'path' => '/customers', 'order' => 1, 'is_public' => false],
                ],
            ],
            [
                'name' => 'General Module',
                'code' => 'general_module',
                'description' => 'Complete module with all pages and resources for testing and development',
                'icon' => 'apps',
                'sort_order' => 0,
                'active' => true,
                'pages' => [
                    // Dashboard Pages
                    ['name' => 'Scheduler', 'code' => PageCode::SCHEDULER_PAGE->value, 'path' => '/main/scheduler', 'order' => 0, 'is_public' => false],
                    ['name' => 'Dashboard', 'code' => PageCode::DASHBOARD_PAGE->value, 'path' => '/main/dashboard', 'order' => 1, 'is_public' => false],
                    ['name' => 'Overview', 'code' => PageCode::DASHBOARD_OVERVIEW_PAGE->value, 'path' => '/main/dashboard/overview', 'order' => 2, 'is_public' => false],
                    ['name' => 'Analytics', 'code' => PageCode::DASHBOARD_ANALYTICS_PAGE->value, 'path' => '/main/dashboard/analytics', 'order' => 3, 'is_public' => false],
                    ['name' => 'Reports', 'code' => PageCode::DASHBOARD_REPORTS_PAGE->value, 'path' => '/main/dashboard/reports', 'order' => 4, 'is_public' => false],

                    // Main Files - Services
                    ['name' => 'Service Categories', 'code' => PageCode::SERVICES_CATEGORIES->value, 'path' => '/main/mainfiles/services?tab=0', 'order' => 10, 'is_public' => false],
                    ['name' => 'Services', 'code' => PageCode::SERVICES_SERVICES->value, 'path' => '/main/mainfiles/services?tab=1', 'order' => 11, 'is_public' => false],

                    // Main Files - Specialists
                    ['name' => 'Specialists', 'code' => PageCode::SPECIALISTS_SPECIALISTS->value, 'path' => '/main/mainfiles/specialists?tab=0', 'order' => 12, 'is_public' => false],
                    ['name' => 'Specialities', 'code' => PageCode::SPECIALISTS_SPECIALITIES->value, 'path' => '/main/mainfiles/specialists?tab=1', 'order' => 13, 'is_public' => false],
                    ['name' => 'Assets', 'code' => PageCode::SPECIALISTS_ASSETS->value, 'path' => '/main/mainfiles/specialists?tab=2', 'order' => 14, 'is_public' => false],
                    ['name' => 'Sections', 'code' => PageCode::SPECIALISTS_SECTIONS->value, 'path' => '/main/mainfiles/specialists?tab=3', 'order' => 15, 'is_public' => false],
                    ['name' => 'Rooms', 'code' => PageCode::SPECIALISTS_ROOMS->value, 'path' => '/main/mainfiles/specialists?tab=4', 'order' => 16, 'is_public' => false],

                    // Main Files - Items
                    ['name' => 'Product Lines', 'code' => PageCode::ITEMS_PRODUCT_LINES->value, 'path' => '/main/mainfiles/items?tab=0', 'order' => 20, 'is_public' => false],
                    ['name' => 'Categories', 'code' => PageCode::ITEMS_CATEGORIES->value, 'path' => '/main/mainfiles/items?tab=1', 'order' => 21, 'is_public' => false],
                    ['name' => 'Brands', 'code' => PageCode::ITEMS_BRANDS->value, 'path' => '/main/mainfiles/items?tab=2', 'order' => 22, 'is_public' => false],
                    ['name' => 'Items', 'code' => PageCode::ITEMS_ITEMS->value, 'path' => '/main/mainfiles/items?tab=3', 'order' => 23, 'is_public' => false],

                    // Main Files - Customers
                    ['name' => 'Customer Groups', 'code' => PageCode::CUSTOMERS_GROUPS->value, 'path' => '/main/mainfiles/customer?tab=0', 'order' => 30, 'is_public' => false],
                    ['name' => 'Salesmen', 'code' => PageCode::CUSTOMERS_SALESMEN->value, 'path' => '/main/mainfiles/customer?tab=1', 'order' => 31, 'is_public' => false],
                    ['name' => 'Customers', 'code' => PageCode::CUSTOMERS_CUSTOMERS->value, 'path' => '/main/mainfiles/customer?tab=2', 'order' => 32, 'is_public' => false],
                    ['name' => 'Customer Master Lists', 'code' => PageCode::CUSTOMERS_MASTER_LISTS->value, 'path' => '/main/mainfiles/customer?tab=3', 'order' => 33, 'is_public' => false],

                    // Suppliers
                    ['name' => 'Supplier Groups', 'code' => PageCode::SUPPLIERS_GROUPS->value, 'path' => '/main/mainfiles/supplier?tab=0', 'order' => 40, 'is_public' => false],
                    ['name' => 'Suppliers', 'code' => PageCode::SUPPLIERS_SUPPLIERS->value, 'path' => '/main/mainfiles/supplier?tab=1', 'order' => 41, 'is_public' => false],

                    // Address Codes
                    ['name' => 'Countries', 'code' => PageCode::ADDRESS_CODES_COUNTRIES->value, 'path' => '/main/mainfiles/addresscodes?tab=0', 'order' => 50, 'is_public' => false],
                    ['name' => 'Cities', 'code' => PageCode::ADDRESS_CODES_CITIES->value, 'path' => '/main/mainfiles/addresscodes?tab=1', 'order' => 51, 'is_public' => false],
                    ['name' => 'Districts', 'code' => PageCode::ADDRESS_CODES_DISTRICTS->value, 'path' => '/main/mainfiles/addresscodes?tab=2', 'order' => 52, 'is_public' => false],
                    ['name' => 'Zones', 'code' => PageCode::ADDRESS_CODES_ZONES->value, 'path' => '/main/mainfiles/addresscodes?tab=3', 'order' => 53, 'is_public' => false],

                    // Payment
                    ['name' => 'Payment Terms', 'code' => PageCode::PAYMENT_TERMS->value, 'path' => '/main/mainfiles/payment?tab=0', 'order' => 60, 'is_public' => false],
                    ['name' => 'Payment Methods', 'code' => PageCode::PAYMENT_METHODS->value, 'path' => '/main/mainfiles/payment?tab=1', 'order' => 61, 'is_public' => false],

                    // Units
                    ['name' => 'Unit Groups', 'code' => PageCode::UNITS_GROUPS->value, 'path' => '/main/mainfiles/units?tab=0', 'order' => 62, 'is_public' => false],
                    ['name' => 'Unit Of Measurements', 'code' => PageCode::UNITS_MEASUREMENTS->value, 'path' => '/main/mainfiles/units?tab=1', 'order' => 63, 'is_public' => false],

                    // General Files
                    ['name' => 'Business Types', 'code' => PageCode::GENERAL_FILES_BUSINESS_TYPES->value, 'path' => '/main/mainfiles/generalfiles?tab=0', 'order' => 70, 'is_public' => false],
                    ['name' => 'Sales Channels', 'code' => PageCode::GENERAL_FILES_SALES_CHANNELS->value, 'path' => '/main/mainfiles/generalfiles?tab=1', 'order' => 71, 'is_public' => false],
                    ['name' => 'Distribution Channels', 'code' => PageCode::GENERAL_FILES_DISTRIBUTION_CHANNELS->value, 'path' => '/main/mainfiles/generalfiles?tab=2', 'order' => 72, 'is_public' => false],
                    ['name' => 'Media Channels', 'code' => PageCode::GENERAL_FILES_MEDIA_CHANNELS->value, 'path' => '/main/mainfiles/generalfiles?tab=3', 'order' => 73, 'is_public' => false],

                    // Sections
                    ['name' => 'Projects', 'code' => PageCode::SECTIONS_PROJECTS->value, 'path' => '/main/mainfiles/sections?tab=0', 'order' => 80, 'is_public' => false],
                    ['name' => 'Cost Centers', 'code' => PageCode::SECTIONS_COST_CENTERS->value, 'path' => '/main/mainfiles/sections?tab=1', 'order' => 81, 'is_public' => false],
                    ['name' => 'Departments', 'code' => PageCode::SECTIONS_DEPARTMENTS->value, 'path' => '/main/mainfiles/sections?tab=2', 'order' => 82, 'is_public' => false],
                    ['name' => 'Trades', 'code' => PageCode::SECTIONS_TRADES->value, 'path' => '/main/mainfiles/sections?tab=3', 'order' => 83, 'is_public' => false],
                    ['name' => 'Company Codes', 'code' => PageCode::SECTIONS_COMPANY_CODES->value, 'path' => '/main/mainfiles/sections?tab=4', 'order' => 84, 'is_public' => false],
                    ['name' => 'Jobs', 'code' => PageCode::SECTIONS_JOBS->value, 'path' => '/main/mainfiles/sections?tab=5', 'order' => 85, 'is_public' => false],

                    // Relations
                    ['name' => 'Associations', 'code' => PageCode::RELATIONS_ASSOCIATIONS->value, 'path' => '/main/mainfiles/relations?tab=0', 'order' => 90, 'is_public' => false],
                    ['name' => 'Referrers', 'code' => PageCode::RELATIONS_REFERRERS->value, 'path' => '/main/mainfiles/relations?tab=1', 'order' => 91, 'is_public' => false],
                    ['name' => 'Media Types', 'code' => PageCode::RELATIONS_MEDIA_TYPES->value, 'path' => '/main/mainfiles/relations?tab=2', 'order' => 92, 'is_public' => false],

                    // Support
                    ['name' => 'Help', 'code' => PageCode::SUPPORT_HELP_PAGE->value, 'path' => '/main/support/help', 'order' => 100, 'is_public' => false],
                    ['name' => 'Contact', 'code' => PageCode::SUPPORT_CONTACT_PAGE->value, 'path' => '/main/support/contact', 'order' => 101, 'is_public' => false],

                    // Settings
                    ['name' => 'User Management', 'code' => PageCode::USER_MANAGEMENT_PAGE->value, 'path' => '/main/settings/userManagement?tab=0', 'order' => 110, 'is_public' => false],
                    ['name' => 'Permission', 'code' => PageCode::PERMISSIONS_PAGE->value, 'path' => '/main/settings/userManagement?tab=1', 'order' => 111, 'is_public' => false],
                    ['name' => 'Role Management', 'code' => PageCode::ROLES_PAGE->value, 'path' => '/main/settings/userManagement?tab=2', 'order' => 112, 'is_public' => false],
                    ['name' => 'System Settings', 'code' => PageCode::SYSTEM_SETTINGS_PAGE->value, 'path' => '/main/settings/systemSettings', 'order' => 113, 'is_public' => false],
                    ['name' => 'Profile', 'code' => PageCode::PROFILE_PAGE->value, 'path' => '/main/profile', 'order' => 114, 'is_public' => false],
                ],
            ],
        ];

        foreach ($modules as $moduleData) {
            $pages = $moduleData['pages'] ?? [];
            unset($moduleData['pages']);

            $module = Module::firstOrCreate(['code' => $moduleData['code']], $moduleData);

            foreach ($pages as $page) {
                ModulePage::firstOrCreate(
                    ['module_id' => $module->id, 'code' => $page['code']],
                    array_merge($page, ['module_id' => $module->id])
                );
            }
        }
    }
}
