<?php

namespace App\Jobs;

use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class BootstrapTenantRbac implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private int $ownerUserId) {}

    public function handle(): void
    {
        // Base permissions for all main business entities
        $permissionKeys = [
            // System Management
            ['resource_key' => 'users', 'resource_label' => 'User Management'],
            ['resource_key' => 'roles', 'resource_label' => 'Role Management'],
            ['resource_key' => 'permissions', 'resource_label' => 'Permission Management'],

            // Core Business Entities
            ['resource_key' => 'customers', 'resource_label' => 'Customer Management'],
            ['resource_key' => 'suppliers', 'resource_label' => 'Supplier Management'],
            ['resource_key' => 'items', 'resource_label' => 'Item Management'],
            ['resource_key' => 'services', 'resource_label' => 'Service Management'],
            ['resource_key' => 'specialists', 'resource_label' => 'Specialist Management'],
            ['resource_key' => 'specialities', 'resource_label' => 'Speciality Management'],
            ['resource_key' => 'assets', 'resource_label' => 'Asset Management'],
            ['resource_key' => 'associations', 'resource_label' => 'Association Management'],
            ['resource_key' => 'media_types', 'resource_label' => 'Media Type Management'],
            ['resource_key' => 'referrers', 'resource_label' => 'Referrer Management'],

            // Configuration/Reference Data
            ['resource_key' => 'brands', 'resource_label' => 'Brand Management'],
            ['resource_key' => 'categories', 'resource_label' => 'Category Management'],
            ['resource_key' => 'sub_categories', 'resource_label' => 'Sub Category Management'],
            ['resource_key' => 'customer_groups', 'resource_label' => 'Customer Group Management'],
            ['resource_key' => 'supplier_groups', 'resource_label' => 'Supplier Group Management'],
            ['resource_key' => 'service_categories', 'resource_label' => 'Service Category Management'],

            // Financial/Operations
            ['resource_key' => 'currencies', 'resource_label' => 'Currency Management'],
            ['resource_key' => 'payment_terms', 'resource_label' => 'Payment Terms Management'],
            ['resource_key' => 'payment_methods', 'resource_label' => 'Payment Methods Management'],
            ['resource_key' => 'salesmen', 'resource_label' => 'Salesman Management'],
            ['resource_key' => 'branches', 'resource_label' => 'Branch Management'],
            ['resource_key' => 'warehouses', 'resource_label' => 'Warehouse Management'],
            ['resource_key' => 'tax_groups', 'resource_label' => 'Tax Group Management'],
            ['resource_key' => 'projects', 'resource_label' => 'Project Management'],
            ['resource_key' => 'jobs', 'resource_label' => 'Job Management'],
            ['resource_key' => 'product_lines', 'resource_label' => 'Product Line Management'],

            // Units
            ['resource_key' => 'unit_groups', 'resource_label' => 'Unit Group Management'],
            ['resource_key' => 'unit_of_measurements', 'resource_label' => 'Unit Of Measurement Management'],

            // Invoices
            ['resource_key' => 'invoices', 'resource_label' => 'Invoice Management'],
        ];

        foreach ($permissionKeys as $perm) {
            Permission::firstOrCreate(['resource_key' => $perm['resource_key']], $perm);
        }

        $ownerRole = Role::firstOrCreate(['name' => 'Owner'], [
            'description' => 'Full access',
            'active' => true,
        ]);
        $adminRole = Role::firstOrCreate(['name' => 'Admin'], [
            'description' => 'Administrative access',
            'active' => true,
        ]);

        // Grant full access to ALL defined permissions (covers newly added permissions automatically)
        $permissions = Permission::all();

        // Owner: full on these resources
        foreach ($permissions as $permission) {
            RolePermission::updateOrCreate(
                ['role_id' => $ownerRole->id, 'permission_id' => $permission->id],
                ['can_view' => true, 'can_add' => true, 'can_edit' => true, 'can_delete' => true, 'can_import' => true, 'can_export' => true]
            );
        }

        // Admin: can manage users/roles/permissions except critical owner/admin constraints (enforced elsewhere)
        foreach ($permissions as $permission) {
            RolePermission::updateOrCreate(
                ['role_id' => $adminRole->id, 'permission_id' => $permission->id],
                ['can_view' => true, 'can_add' => true, 'can_edit' => true, 'can_delete' => true, 'can_import' => true, 'can_export' => true]
            );
        }

        // Assign Owner role to initial owner user
        $owner = User::find($this->ownerUserId);
        if ($owner) {
            $owner->role()->associate($ownerRole->id);
            $owner->save();
        }
    }
}
