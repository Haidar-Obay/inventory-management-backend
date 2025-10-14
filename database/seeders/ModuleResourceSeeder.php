<?php

namespace Database\Seeders;

use App\Models\Module;
use App\Models\ModuleResource;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ModuleResourceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Map page codes to backend resource keys
        $resourceMapping = [
            // System Management (always available)
            'userManagementTab' => 'users',
            'permission' => 'permissions', 
            'roleManagement' => 'roles',
            
            // Beauty Center Module
            'serviceCategories' => 'service_categories',
            'services_inner' => 'services',
            'customer_inner' => 'customers',
            'customerMasterList' => 'customer_master_lists',
            
            // Stock Management Module  
            'productLines' => 'product_lines',
            'categories' => 'categories',
            'brands' => 'brands',
            'items_inner' => 'items',
            'supplier_inner' => 'suppliers',
            'product_lines' => 'product_lines',
            // Customer Management Module
            'customers' => 'customers',
        ];

        // Get all modules
        $modules = Module::all();

        foreach ($modules as $module) {
            // Get all pages for this module
            $pages = $module->pages;
            
            foreach ($pages as $page) {
                // Check if this page code maps to a backend resource
                if (isset($resourceMapping[$page->code])) {
                    $resourceKey = $resourceMapping[$page->code];
                    
                    // Create module resource if it doesn't exist
                    ModuleResource::firstOrCreate(
                        [
                            'module_id' => $module->id,
                            'code' => $resourceKey,
                        ],
                        [
                            'name' => ucwords(str_replace('_', ' ', $resourceKey)),
                            'description' => "Backend resource for {$resourceKey}",
                            'enabled' => true,
                            'version' => 1,
                        ]
                    );
                }
            }

            // Explicit resources per module code (ensures backend alignment regardless of page codes)
            $moduleSpecificResources = [
                'stock_management' => ['product_lines', 'categories', 'brands', 'items', 'suppliers'],
                'beauty_center' => ['service_categories', 'services', 'customers', 'customer_master_lists'],
                'customer_management' => ['customers'],
            ];

            $resourcesForModule = $moduleSpecificResources[$module->code] ?? [];
            foreach ($resourcesForModule as $resourceKey) {
                ModuleResource::firstOrCreate(
                    [
                        'module_id' => $module->id,
                        'code' => $resourceKey,
                    ],
                    [
                        'name' => ucwords(str_replace('_', ' ', $resourceKey)),
                        'description' => "Backend resource for {$resourceKey}",
                        'enabled' => true,
                        'version' => 1,
                    ]
                );
            }
        }

        // Add core system resources to all modules (users, roles, permissions)
        $coreResources = [
            'users' => 'User Management',
            'roles' => 'Role Management', 
            'permissions' => 'Permission Management',
        ];

        foreach ($modules as $module) {
            foreach ($coreResources as $resourceKey => $resourceName) {
                ModuleResource::firstOrCreate(
                    [
                        'module_id' => $module->id,
                        'code' => $resourceKey,
                    ],
                    [
                        'name' => $resourceName,
                        'description' => "Core system resource for {$resourceKey}",
                        'enabled' => true,
                        'version' => 1,
                    ]
                );
            }
        }

        $this->command->info('Module resources seeded successfully!');
    }
}
