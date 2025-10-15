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
                    ['name' => 'Scheduler', 'code' => PageCode::SCHEDULER_PAGE->value, 'path' => '/main/scheduler', 'order' => 0, 'is_public' => false],
                    ['name' => 'Dashboard', 'code' => PageCode::DASHBOARD_PAGE->value, 'path' => '/main/dashboard', 'order' => 1, 'is_public' => false],
                    ['name' => 'Overview', 'code' => PageCode::DASHBOARD_OVERVIEW_PAGE->value, 'path' => '/main/dashboard/overview', 'order' => 2, 'is_public' => false],
                    ['name' => 'Analytics', 'code' => PageCode::DASHBOARD_ANALYTICS_PAGE->value, 'path' => '/main/dashboard/analytics', 'order' => 3, 'is_public' => false],
                    ['name' => 'Reports', 'code' => PageCode::DASHBOARD_REPORTS_PAGE->value, 'path' => '/main/dashboard/reports', 'order' => 4, 'is_public' => false],
                    ['name' => 'Service Categories', 'code' => PageCode::SERVICES_PAGE->value, 'path' => '/main/mainfiles/services?tab=0', 'order' => 10, 'is_public' => false],
                    ['name' => 'Services', 'code' => PageCode::SERVICES_PAGE->value, 'path' => '/main/mainfiles/services?tab=1', 'order' => 11, 'is_public' => false],
                    ['name' => 'Customers', 'code' => PageCode::CUSTOMERS_PAGE->value, 'path' => '/main/mainfiles/customer', 'order' => 12, 'is_public' => false],
                    ['name' => 'Customer Master Lists', 'code' => PageCode::CUSTOMERS_PAGE->value, 'path' => '/main/mainfiles/customer?tab=3', 'order' => 13, 'is_public' => false],
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
                    ['name' => 'Product Lines', 'code' => PageCode::ITEMS_PAGE->value, 'path' => '/main/mainfiles/items?tab=0', 'order' => 1, 'is_public' => false],
                    ['name' => 'Categories', 'code' => PageCode::ITEMS_PAGE->value, 'path' => '/main/mainfiles/items?tab=1', 'order' => 2, 'is_public' => false],
                    ['name' => 'Brands', 'code' => PageCode::ITEMS_PAGE->value, 'path' => '/main/mainfiles/items?tab=2', 'order' => 3, 'is_public' => false],
                    ['name' => 'Items', 'code' => PageCode::ITEMS_PAGE->value, 'path' => '/main/mainfiles/items?tab=3', 'order' => 4, 'is_public' => false],
                    ['name' => 'Suppliers', 'code' => PageCode::SUPPLIERS_SUPPLIERS->value, 'path' => '/main/supplier?tab=1', 'order' => 5, 'is_public' => false],
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