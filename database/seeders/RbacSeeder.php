<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Role;
use App\Models\Permission;
use App\Models\RolePermission;
use Illuminate\Support\Facades\Hash;

class RbacSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create permissions
        $permissions = [
            ['resource_key' => 'roles', 'resource_label' => 'Roles'],
            ['resource_key' => 'permissions', 'resource_label' => 'Permissions'],
            ['resource_key' => 'users', 'resource_label' => 'Users'],
            ['resource_key' => 'customers', 'resource_label' => 'Customers'],
            ['resource_key' => 'suppliers', 'resource_label' => 'Suppliers'],
            ['resource_key' => 'items', 'resource_label' => 'Items'],
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(
                ['resource_key' => $permission['resource_key']],
                $permission
            );
        }

        // Create roles
        $roles = [
            [
                'name' => 'Owner',
                'description' => 'Full system access - can do everything',
                'active' => true
            ],
            [
                'name' => 'Admin',
                'description' => 'Administrative access - can manage users and most resources',
                'active' => true
            ],
            [
                'name' => 'User',
                'description' => 'Basic user access - view only',
                'active' => true
            ]
        ];

        foreach ($roles as $role) {
            Role::firstOrCreate(
                ['name' => $role['name']],
                $role
            );
        }

        // Get role IDs
        $ownerRole = Role::where('name', 'Owner')->first();
        $adminRole = Role::where('name', 'Admin')->first();
        $userRole = Role::where('name', 'User')->first();

        // Get permission IDs
        $permissions = Permission::all();

        // Assign permissions to Owner (full access to everything)
        foreach ($permissions as $permission) {
            RolePermission::firstOrCreate(
                [
                    'role_id' => $ownerRole->id,
                    'permission_id' => $permission->id
                ],
                [
                    'can_view' => true,
                    'can_add' => true,
                    'can_edit' => true,
                    'can_delete' => true
                ]
            );
        }

        // Assign permissions to Admin (can manage everything except roles/permissions of Owner/Admin)
        foreach ($permissions as $permission) {
            if (in_array($permission->resource_key, ['roles', 'permissions'])) {
                // Admin can view and edit roles/permissions but not delete Owner/Admin roles
                RolePermission::firstOrCreate(
                    [
                        'role_id' => $adminRole->id,
                        'permission_id' => $permission->id
                    ],
                    [
                        'can_view' => true,
                        'can_add' => true,
                        'can_edit' => true,
                        'can_delete' => false // Cannot delete roles/permissions
                    ]
                );
            } else {
                // Admin has full access to other resources
                RolePermission::firstOrCreate(
                    [
                        'role_id' => $adminRole->id,
                        'permission_id' => $permission->id
                    ],
                    [
                        'can_view' => true,
                        'can_add' => true,
                        'can_edit' => true,
                        'can_delete' => true
                    ]
                );
            }
        }

        // Assign permissions to User (view only)
        foreach ($permissions as $permission) {
            RolePermission::firstOrCreate(
                [
                    'role_id' => $userRole->id,
                    'permission_id' => $permission->id
                ],
                [
                    'can_view' => true,
                    'can_add' => false,
                    'can_edit' => false,
                    'can_delete' => false
                ]
            );
        }

        // Create users and assign roles
        $users = [
            [
                'name' => 'Owner User',
                'email' => 'owner@hadishokor.com',
                'password' => Hash::make('12345678'),
                'active' => true,
                'role' => $ownerRole
            ],
            [
                'name' => 'Admin User',
                'email' => 'admin@hadishokor.com',
                'password' => Hash::make('12345678'),
                'active' => true,
                'role' => $adminRole
            ],
            [
                'name' => 'Regular User',
                'email' => 'user@hadishokor.com',
                'password' => Hash::make('12345678'),
                'active' => true,
                'role' => $userRole
            ]
        ];

        foreach ($users as $userData) {
            $role = $userData['role'];
            unset($userData['role']);
            
            $user = User::firstOrCreate(
                ['email' => $userData['email']],
                $userData
            );

            // Assign role to user
            $user->roles()->syncWithoutDetaching([$role->id]);
        }

        $this->command->info('RBAC system seeded successfully!');
        $this->command->info('Users created:');
        $this->command->info('- Owner: owner@hadishokor.com (password: 12345678)');
        $this->command->info('- Admin: admin@hadishokor.com (password: 12345678)');
        $this->command->info('- User: user@hadishokor.com (password: 12345678)');
    }
}
