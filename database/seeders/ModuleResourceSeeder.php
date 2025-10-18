<?php

namespace Database\Seeders;

use App\Enums\PageCode;
use App\Models\Module;
use App\Models\ModuleResource;
use Illuminate\Database\Seeder;

class ModuleResourceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Map frontend PageCode values to backend resource keys
        $resourceMapping = [
            // System Management
            PageCode::USER_MANAGEMENT_PAGE->value => 'users',
            PageCode::PERMISSIONS_PAGE->value => 'permissions',
            PageCode::ROLES_PAGE->value => 'roles',

            // Address Codes
            PageCode::ADDRESS_CODES_COUNTRIES->value => 'countries',
            PageCode::ADDRESS_CODES_CITIES->value => 'cities',
            PageCode::ADDRESS_CODES_DISTRICTS->value => 'districts',
            PageCode::ADDRESS_CODES_ZONES->value => 'zones',

            // Services (granularity handled by module-specific resources too)
            PageCode::SERVICES_PAGE->value => 'services',

            // Items (granularity handled below)
            PageCode::ITEMS_PAGE->value => 'items',

            // Suppliers
            PageCode::SUPPLIERS_GROUPS->value => 'suppliers',
            PageCode::SUPPLIERS_SUPPLIERS->value => 'suppliers',

            // Customers
            PageCode::CUSTOMERS_PAGE->value => 'customers',


            // Relations
            PageCode::RELATIONS_ASSOCIATIONS->value => 'associations',
            PageCode::RELATIONS_REFERRERS->value => 'referrers',
            PageCode::RELATIONS_CONNECTIONS->value => 'connections',

            // Sections
            PageCode::SECTIONS_PROJECTS->value => 'projects',
            PageCode::SECTIONS_COST_CENTERS->value => 'cost_centers',
            PageCode::SECTIONS_DEPARTMENTS->value => 'departments',
            PageCode::SECTIONS_TRADES->value => 'trades',
            PageCode::SECTIONS_COMPANY_CODES->value => 'company_codes',
            PageCode::SECTIONS_JOBS->value => 'jobs',

            // General Files
            PageCode::GENERAL_FILES_BUSINESS_TYPES->value => 'business_types',
            PageCode::GENERAL_FILES_SALES_CHANNELS->value => 'sales_channels',
            PageCode::GENERAL_FILES_DISTRIBUTION_CHANNELS->value => 'distribution_channels',
            PageCode::GENERAL_FILES_MEDIA_CHANNELS->value => 'media_channels',

            // Payment
            PageCode::PAYMENT_TERMS->value => 'payment_terms',
            PageCode::PAYMENT_METHODS->value => 'payment_methods',
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
                // Do NOT hard-add 'suppliers' here; it will be added only if a supplier page code exists

                'stock_management'   => ['product_lines', 'categories', 'brands', 'items', 'supplier_groups'],
                'beauty_center'      => [
                    'service_categories',
                    'services',
                    'customers',
                    'customer_master_lists',
                    'currencies',
                    // Newly added common resources used by Beauty Center screens
                    'payment_terms',
                    'payment_methods',
                    'customer_groups',
                    'salesmen',
                ],
                'customer_management'=> ['customers'],
                'general_module'     => [
                    // All available resources for comprehensive testing
                    'service_categories',
                    'services',
                    'customers',
                    'customer_master_lists',
                    'currencies',
                    'product_lines',
                    'categories',
                    'brands',
                    'items',
                    'suppliers',
                    'supplier_groups',
                    'countries',
                    'cities',
                    'districts',
                    'zones',
                    'payment_terms',
                    'payment_methods',
                    'business_types',
                    'sales_channels',
                    'distribution_channels',
                    'media_channels',
                    'projects',
                    'cost_centers',
                    'departments',
                    'trades',
                    'company_codes',
                    'jobs',
                    'associations',
                    'referrers',
                    'connections',
                    'customer_groups',
                    'salesmen',
                    'specialists',
                    'specialities',
                    'locations',
                    'rooms',
                    'sections',
                    'assets',
                    'table_templates',
                ],
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
