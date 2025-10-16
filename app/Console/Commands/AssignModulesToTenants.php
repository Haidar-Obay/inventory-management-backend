<?php

namespace App\Console\Commands;

use App\Models\Module;
use App\Models\Tenant;
use Illuminate\Console\Command;

class AssignModulesToTenants extends Command
{
    protected $signature = 'tenants:assign-modules';
    protected $description = 'Assign modules to tenants';

    public function handle()
    {
        $tenants = Tenant::all();
        $modules = Module::all();

        $this->info("Found {$tenants->count()} tenants and {$modules->count()} modules");

        foreach ($tenants as $tenant) {
            $this->info("Tenant: {$tenant->name} (ID: {$tenant->id})");
            
            // Assign all modules to each tenant for now
            foreach ($modules as $module) {
                $tenant->modules()->syncWithoutDetaching([$module->id => [
                    'assigned_price' => 0,
                    'is_included' => true,
                    'subscription_plan_id' => null,
                ]]);
                $this->line("  - Assigned module: {$module->name}");
            }
        }

        $this->info('All modules assigned to all tenants!');
    }
}
