<?php

namespace Database\Seeders;

use App\Enums\PageCode;
use App\Models\Module;
use App\Models\Resource;
use Illuminate\Database\Seeder;

class ModuleResourceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $resourceMapping = [
            PageCode::USER_MANAGEMENT_PAGE->value => 'users',
            PageCode::PERMISSIONS_PAGE->value => 'permissions',
            PageCode::ROLES_PAGE->value => 'roles',
            PageCode::ADDRESS_CODES_COUNTRIES->value => 'countries',
            PageCode::ADDRESS_CODES_CITIES->value => 'cities',
            PageCode::ADDRESS_CODES_DISTRICTS->value => 'districts',
            PageCode::ADDRESS_CODES_ZONES->value => 'zones',
            PageCode::SERVICES_CATEGORIES->value => 'service_categories',
            PageCode::SERVICES_SERVICES->value => 'services',
            PageCode::ITEMS_PRODUCT_LINES->value => 'product_lines',
            PageCode::ITEMS_CATEGORIES->value => 'categories',
            PageCode::ITEMS_BRANDS->value => 'brands',
            PageCode::ITEMS_GROUPS->value => 'item_groups',
            PageCode::ITEMS_ITEMS->value => 'items',
            PageCode::ITEMS_WAREHOUSES->value => 'warehouses',
            PageCode::UNITS_GROUPS->value => 'unit_groups',
            PageCode::UNITS_MEASUREMENTS->value => 'unit_of_measurements',
            PageCode::SUPPLIERS_GROUPS->value => 'suppliers',
            PageCode::SUPPLIERS_SUPPLIERS->value => 'suppliers',
            PageCode::CUSTOMERS_GROUPS->value => 'customer_groups',
            PageCode::CUSTOMERS_SALESMEN->value => 'salesmen',
            PageCode::CUSTOMERS_CUSTOMERS->value => 'customers',
            PageCode::CUSTOMERS_MASTER_LISTS->value => 'customer_master_lists',
            PageCode::RELATIONS_ASSOCIATIONS->value => 'associations',
            PageCode::RELATIONS_REFERRERS->value => 'referrers',
            PageCode::RELATIONS_MEDIA_TYPES->value => 'media_types',
            PageCode::SECTIONS_PROJECTS->value => 'projects',
            PageCode::SECTIONS_COST_CENTERS->value => 'cost_centers',
            PageCode::SECTIONS_DEPARTMENTS->value => 'departments',
            PageCode::SECTIONS_TRADES->value => 'trades',
            PageCode::SECTIONS_COMPANY_CODES->value => 'company_codes',
            PageCode::SECTIONS_JOBS->value => 'jobs',
            PageCode::GENERAL_FILES_BUSINESS_TYPES->value => 'business_types',
            PageCode::GENERAL_FILES_SALES_CHANNELS->value => 'sales_channels',
            PageCode::GENERAL_FILES_DISTRIBUTION_CHANNELS->value => 'distribution_channels',
            PageCode::GENERAL_FILES_MEDIA_CHANNELS->value => 'media_channels',
            PageCode::PAYMENT_TERMS->value => 'payment_terms',
            PageCode::PAYMENT_METHODS->value => 'payment_methods',
            PageCode::PAYMENT_TAX_GROUPS->value => 'tax_groups',
            PageCode::INVOICES_INVOICES->value => 'invoices',
            PageCode::INVOICES_PURCHASE_INVOICES->value => 'invoices',
            PageCode::INVOICES_SALES_INVOICES->value => 'invoices',
        ];

        $modules = Module::with('pages')->get();

        foreach ($modules as $module) {
            foreach ($module->pages as $page) {
                if (isset($resourceMapping[$page->code])) {
                    $resourceKey = $resourceMapping[$page->code];
                    $resource = Resource::firstOrCreate(
                        ['code' => $resourceKey],
                        [
                            'name' => ucwords(str_replace('_', ' ', $resourceKey)),
                            'description' => "Backend resource for {$resourceKey}",
                            'enabled' => true,
                            'version' => 1,
                        ]
                    );
                    $module->resources()->syncWithoutDetaching([$resource->id]);
                }
            }

            $moduleSpecificResources = [
                'stock_management' => ['product_lines', 'categories', 'sub_categories', 'brands', 'item_groups', 'items', 'supplier_groups', 'customers', 'unit_groups', 'unit_of_measurements', 'warehouses', 'tax_groups'],
                'beauty_center' => ['service_categories', 'services', 'customers', 'customer_master_lists', 'currencies', 'payment_terms', 'payment_methods', 'customer_groups', 'salesmen'],
                'customer_management' => ['customers'],
                'general_module' => [
                    'service_categories', 'services', 'customers', 'customer_master_lists', 'currencies',
                    'product_lines', 'categories', 'sub_categories', 'brands', 'item_groups', 'unit_groups', 'items',
                    'suppliers', 'supplier_groups', 'countries', 'cities', 'districts', 'zones', 'payment_terms',
                    'payment_methods', 'business_types', 'sales_channels', 'distribution_channels', 'media_channels',
                    'projects', 'cost_centers', 'departments', 'trades', 'company_codes', 'jobs', 'associations',
                    'referrers', 'media_types', 'customer_groups', 'salesmen', 'specialists', 'specialities',
                    'locations', 'rooms', 'sections', 'assets', 'table_templates', 'unit_groups', 'unit_of_measurements',
                    'warehouses', 'tax_groups', 'invoices',
                ],
            ];

            $resourcesForModule = $moduleSpecificResources[$module->code] ?? [];
            foreach ($resourcesForModule as $resourceKey) {
                $resource = Resource::firstOrCreate(
                    ['code' => $resourceKey],
                    [
                        'name' => ucwords(str_replace('_', ' ', $resourceKey)),
                        'description' => "Backend resource for {$resourceKey}",
                        'enabled' => true,
                        'version' => 1,
                    ]
                );
                $module->resources()->syncWithoutDetaching([$resource->id]);
            }
        }

        $coreResources = [
            'users' => 'User Management',
            'roles' => 'Role Management',
            'permissions' => 'Permission Management',
        ];

        foreach ($modules as $module) {
            foreach ($coreResources as $resourceKey => $resourceName) {
                $resource = Resource::firstOrCreate(
                    ['code' => $resourceKey],
                    [
                        'name' => $resourceName,
                        'description' => "Core system resource for {$resourceKey}",
                        'enabled' => true,
                        'version' => 1,
                    ]
                );
                $module->resources()->syncWithoutDetaching([$resource->id]);
            }
        }

        $commonResources = [
            'warehouses' => 'Warehouse Management',
            'tax_groups' => 'Tax Group Management',
        ];

        foreach ($modules as $module) {
            foreach ($commonResources as $resourceKey => $resourceName) {
                $resource = Resource::firstOrCreate(
                    ['code' => $resourceKey],
                    [
                        'name' => $resourceName,
                        'description' => "Common business resource for {$resourceKey}",
                        'enabled' => true,
                        'version' => 1,
                    ]
                );
                $module->resources()->syncWithoutDetaching([$resource->id]);
            }
        }

        $this->command->info('Module resources seeded successfully!');
    }
}
