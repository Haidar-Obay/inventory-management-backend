<?php

namespace Database\Seeders;

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
                    // Align codes with frontend menu keys
                    ['name' => 'Overview', 'code' => 'overview', 'path' => '/main/dashboard/overview', 'order' => 1, 'is_public' => false],
                    ['name' => 'Service Categories', 'code' => 'serviceCategories', 'path' => '/main/mainfiles/services?tab=0', 'order' => 2, 'is_public' => false],
                    ['name' => 'Services', 'code' => 'services_inner', 'path' => '/main/mainfiles/services?tab=1', 'order' => 3, 'is_public' => false],
                    ['name' => 'Customers', 'code' => 'customer_inner', 'path' => '/main/mainfiles/customer?tab=2', 'order' => 4, 'is_public' => false],
                    ['name' => 'Customer Master Lists', 'code' => 'customerMasterList', 'path' => '/main/mainfiles/customer?tab=3', 'order' => 5, 'is_public' => false],
                    // Settings pages
                    ['name' => 'User Management', 'code' => 'userManagementTab', 'path' => '/main/settings/userManagement?tab=0', 'order' => 90, 'is_public' => false],
                    ['name' => 'Permission', 'code' => 'permission', 'path' => '/main/settings/userManagement?tab=1', 'order' => 91, 'is_public' => false],
                    ['name' => 'Role Management', 'code' => 'roleManagement', 'path' => '/main/settings/userManagement?tab=2', 'order' => 92, 'is_public' => false],
                    ['name' => 'System Settings', 'code' => 'systemSettings', 'path' => '/main/settings/systemSettings', 'order' => 93, 'is_public' => false],
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
                    // Align codes with frontend menu keys
                    ['name' => 'Product Lines', 'code' => 'productLines', 'path' => '/main/mainfiles/items?tab=0', 'order' => 1, 'is_public' => false],
                    ['name' => 'Categories', 'code' => 'categories', 'path' => '/main/mainfiles/items?tab=1', 'order' => 2, 'is_public' => false],
                    ['name' => 'Brands', 'code' => 'brands', 'path' => '/main/mainfiles/items?tab=2', 'order' => 3, 'is_public' => false],
                    ['name' => 'Items', 'code' => 'items_inner', 'path' => '/main/mainfiles/items?tab=3', 'order' => 4, 'is_public' => false],
                    ['name' => 'Suppliers', 'code' => 'supplier_inner', 'path' => '/main/supplier?tab=1', 'order' => 5, 'is_public' => false],
                    // Settings pages
                    ['name' => 'User Management', 'code' => 'userManagementTab', 'path' => '/main/settings/userManagement?tab=0', 'order' => 90, 'is_public' => false],
                    ['name' => 'Permission', 'code' => 'permission', 'path' => '/main/settings/userManagement?tab=1', 'order' => 91, 'is_public' => false],
                    ['name' => 'Role Management', 'code' => 'roleManagement', 'path' => '/main/settings/userManagement?tab=2', 'order' => 92, 'is_public' => false],
                    ['name' => 'System Settings', 'code' => 'systemSettings', 'path' => '/main/settings/systemSettings', 'order' => 93, 'is_public' => false],
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