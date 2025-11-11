<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class TenantPurgeController extends Controller
{
    /**
     * Define the default tables to purge when the request
     * doesn't explicitly provide a list. Populate this array
     * with the tenant tables you want to be cleared.
     */
    protected array $defaultTables = [
        'cities',
        'countries',
        'districts',
        'zones',
        'service_categories',
        'services',
        'associations',
        'referrers',
        'media_types',
        'projects',
        'cost_centers',
        'departments',
        'trades',
        'company_codes',
        'jobs',
        'business_types',
        'sales_channels',
        'distribution_channels',
        'media_channels',
        'payment_terms',
        'payment_methods',
        'product_lines',
        'categories',
        'brands',
        'items',
        'customer_groups',
        'salesmen',
        'customers',
        'customer_master_lists',
        'supplier_groups',
        'suppliers',
    ];

    /**
     * Tables that reference our target tables and must be cleared first.
     * These are common dependent tables that have foreign keys to the tables we want to purge.
     */
    protected array $dependentTables = [
        'addresses', // references cities, countries, districts, zones
        'customer_addresses', // references customers, addresses
        'customer_contacts', // references customers
        'customer_routes', // references customers, salesmen
        'customer_credit_limits', // references customers
        'customer_cheque_limits', // references customers
        'customer_opening_balances', // references customers
        'customer_attachments', // references customers
        'customer_tax', // references customers
        'item_attachments', // references items
        'item_supplier', // references items, suppliers
        'item_unit_of_measurements', // references items
        'supplier_addresses', // references suppliers, addresses
        'supplier_contacts', // references suppliers
        'supplier_credit_limits', // references suppliers
        'supplier_cheque_limits', // references suppliers
        'supplier_opening_balances', // references suppliers
        'supplier_attachments', // references suppliers
        'service_needed_items', // references services, items
        'service_advanced_pricings', // references services
        'association_contacts', // references associations
        'association_service_prices', // references associations, services
        'referrer_service_commissions', // references referrers, services
        'customer_master_list_items', // references customer_master_lists, items
        'projects', // might reference customers
        'jobs', // references projects
    ];

    public function purge(Request $request)
    {
        $data = $request->validate([
            'tables' => ['sometimes', 'array', 'min:1'],
            'tables.*' => ['string'],
        ], [], [
            'tables' => 'tables',
        ]);

        // In stancl/tenancy, this runs inside the tenant DB (InitializeTenancyByDomain middleware)
        $targetTables = isset($data['tables'])
            ? array_values(array_unique(array_map(static fn ($t) => trim($t), $data['tables'])))
            : $this->defaultTables;

        if (empty($targetTables)) {
            return response()->json([
                'status' => false,
                'message' => 'No tables configured. Add table names to TenantPurgeController::$defaultTables or pass tables in the request.',
            ], 422);
        }

        $results = [
            'deleted_counts' => [],
            'skipped' => [],
        ];

        // Get database driver to handle constraints properly
        $driver = DB::connection()->getDriverName();
        $isPostgreSQL = $driver === 'pgsql';

        DB::beginTransaction();

        try {
            // Step 1: Clear dependent tables first (tables that reference our target tables)
            foreach ($this->dependentTables as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }

                try {
                    $count = (int) DB::table($table)->count();
                    if ($count > 0) {
                        // For PostgreSQL, use TRUNCATE CASCADE to handle foreign keys
                        if ($isPostgreSQL) {
                            DB::statement("TRUNCATE TABLE \"{$table}\" CASCADE");
                        } else {
                            DB::table($table)->delete();
                        }
                        $results['deleted_counts'][$table] = $count;
                    }
                } catch (\Throwable $e) {
                    // If TRUNCATE fails, try regular delete
                    try {
                        $count = (int) DB::table($table)->count();
                        DB::table($table)->delete();
                        $results['deleted_counts'][$table] = $count;
                    } catch (\Throwable $ignored) {
                        // Skip if table can't be cleared
                    }
                }
            }

            // Step 2: Clear target tables in reverse dependency order
            // Order: Most dependent first, then parent tables
            $orderedTables = $this->orderTablesByDependencies($targetTables);

            foreach ($orderedTables as $table) {
                if ($table === '' || ! Schema::hasTable($table)) {
                    $results['skipped'][] = [
                        'table' => $table,
                        'reason' => 'Table does not exist',
                    ];

                    continue;
                }

                try {
                    $count = (int) DB::table($table)->count();
                    if ($count > 0) {
                        // For PostgreSQL, use TRUNCATE CASCADE
                        if ($isPostgreSQL) {
                            DB::statement("TRUNCATE TABLE \"{$table}\" CASCADE");
                        } else {
                            DB::table($table)->delete();
                        }
                        $results['deleted_counts'][$table] = $count;
                    }
                } catch (\Throwable $e) {
                    // If TRUNCATE fails, try regular delete
                    try {
                        $count = (int) DB::table($table)->count();
                        DB::table($table)->delete();
                        $results['deleted_counts'][$table] = $count;
                    } catch (\Throwable $deleteError) {
                        $results['skipped'][] = [
                            'table' => $table,
                            'reason' => $deleteError->getMessage(),
                        ];
                    }
                }
            }

            DB::commit();

            // Clear cache after successful purge
            try {
                Artisan::call('cache:clear');
            } catch (\Throwable $cacheError) {
                // Cache clearing failed, but purge was successful
                // Log the error but don't fail the entire operation
                Log::warning('Cache clear failed during purge: '.$cacheError->getMessage());
            }
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Purge failed: '.$e->getMessage(),
            ], 500);
        }

        return response()->json([
            'status' => true,
            'message' => 'Purge completed.',
            'deleted_counts' => $results['deleted_counts'],
            'skipped' => $results['skipped'],
        ]);
    }

    /**
     * Order tables by dependencies: children first, then parents.
     * This ensures we delete tables that reference others before deleting the referenced tables.
     */
    protected function orderTablesByDependencies(array $tables): array
    {
        // Define dependency order: tables that reference others come first
        $dependencyOrder = [
            // Items depend on categories, brands, product_lines, trades, company_codes
            'items',
            // Services depend on service_categories
            'services',
            // Customers depend on many tables
            'customers',
            'customer_master_lists',
            // Suppliers depend on supplier_groups
            'suppliers',
            // Jobs depend on projects
            'jobs',
            'projects',
            // Location hierarchy: cities depend on districts/zones, districts depend on zones
            'cities',
            'districts',
            'zones',
            'countries',
            // Other independent or parent tables
            'roles',
            'salesmen',
            'customer_groups',
            'supplier_groups',
            'categories',
            'brands',
            'product_lines',
            'service_categories',
            'associations',
            'referrers',
            'media_types',
            'cost_centers',
            'departments',
            'trades',
            'company_codes',
            'business_types',
            'sales_channels',
            'distribution_channels',
            'media_channels',
            'payment_terms',
            'payment_methods',
        ];

        // Sort tables according to dependency order
        $ordered = [];
        $remaining = array_flip($tables);

        foreach ($dependencyOrder as $table) {
            if (isset($remaining[$table])) {
                $ordered[] = $table;
                unset($remaining[$table]);
            }
        }

        // Add any remaining tables that weren't in the order list
        foreach ($tables as $table) {
            if (isset($remaining[$table])) {
                $ordered[] = $table;
            }
        }

        return $ordered;
    }
}
