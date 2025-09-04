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

    public function __construct(private int $ownerUserId)
    {
    }

    public function handle(): void
    {
        // Base permissions for user/role/permission management
        $permissionKeys = [
            ['resource_key' => 'users', 'resource_label' => 'User Management'],
            ['resource_key' => 'roles', 'resource_label' => 'Role Management'],
            ['resource_key' => 'permissions', 'resource_label' => 'Permission Management'],
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

        $permissions = Permission::whereIn('resource_key', ['users', 'roles', 'permissions'])->get();

        // Owner: full on these resources
        foreach ($permissions as $permission) {
            RolePermission::updateOrCreate(
                ['role_id' => $ownerRole->id, 'permission_id' => $permission->id],
                ['can_view' => true, 'can_add' => true, 'can_edit' => true, 'can_delete' => true]
            );
        }

        // Admin: can manage users/roles/permissions except critical owner/admin constraints (enforced elsewhere)
        foreach ($permissions as $permission) {
            RolePermission::updateOrCreate(
                ['role_id' => $adminRole->id, 'permission_id' => $permission->id],
                ['can_view' => true, 'can_add' => true, 'can_edit' => true, 'can_delete' => true]
            );
        }

        // Assign Owner role to initial owner user
        $owner = User::find($this->ownerUserId);
        if ($owner) {
            $owner->roles()->syncWithoutDetaching([$ownerRole->id]);
        }
    }
}
