<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RolePermissionController extends Controller
{
    /**
     * Get all permissions for a specific role.
     */
    public function getRolePermissions(Role $role): JsonResponse
    {
        try {
            // Filter role permissions by tenant's assigned module resources (central DB)
            $tenantId = tenant('id');
            $central = config('tenancy.database.central_connection', config('database.default'));
            $allowedResourceKeys = collect(
                DB::connection($central)
                    ->table('module_resources')
                    ->join('modules', 'module_resources.module_id', '=', 'modules.id')
                    ->join('tenant_modules', 'modules.id', '=', 'tenant_modules.module_id')
                    ->join('tenants', 'tenant_modules.tenant_id', '=', 'tenants.id')
                    ->where('tenants.id', $tenantId)
                    ->pluck('module_resources.code')
            )->unique()->values();

            $role->load(['permissions' => function ($q) use ($allowedResourceKeys) {
                if ($allowedResourceKeys->isNotEmpty()) {
                    $q->whereIn('permissions.resource_key', $allowedResourceKeys);
                } else {
                    $q->whereRaw('1=0');
                }
            }]);

            $permissions = $role->permissions->map(function ($permission) {
                return [
                    'id' => $permission->id,
                    'resource_key' => $permission->resource_key,
                    'resource_label' => $permission->resource_label,
                    'can_view' => $permission->pivot->can_view,
                    'can_add' => $permission->pivot->can_add,
                    'can_edit' => $permission->pivot->can_edit,
                    'can_delete' => $permission->pivot->can_delete,
                ];
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Role permissions retrieved successfully',
                'data' => [
                    'role_id' => $role->id,
                    'role_name' => $role->name,
                    'permissions' => $permissions,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve role permissions',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Assign permissions to a role with granular access control.
     */
    public function assignPermissions(Request $request, Role $role): JsonResponse
    {
        try {
            $request->validate([
                'permissions' => 'required|array',
                'permissions.*.permission_id' => 'required|exists:permissions,id',
                'permissions.*.can_view' => 'boolean',
                'permissions.*.can_add' => 'boolean',
                'permissions.*.can_edit' => 'boolean',
                'permissions.*.can_delete' => 'boolean',
            ]);

            $permissionsData = $request->input('permissions');
            // Enforce: Admin cannot edit Owner or Admin roles
            $authUser = $request->user();
            if ($authUser && $authUser->role?->name === 'Admin' && in_array($role->name, ['Owner', 'Admin'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Admins cannot modify Owner/Admin role permissions.',
                ], 403);
            }

            // Clear existing permissions for this role
            $role->permissions()->detach();

            // Attach new permissions with granular access
            foreach ($permissionsData as $permissionData) {
                $role->permissions()->attach($permissionData['permission_id'], [
                    'can_view' => $permissionData['can_view'] ?? false,
                    'can_add' => $permissionData['can_add'] ?? false,
                    'can_edit' => $permissionData['can_edit'] ?? false,
                    'can_delete' => $permissionData['can_delete'] ?? false,
                ]);
            }

            // Reload role with permissions
            $role->load('permissions');

            return response()->json([
                'status' => 'success',
                'message' => 'Permissions assigned successfully',
                'data' => [
                    'role_id' => $role->id,
                    'role_name' => $role->name,
                    'assigned_permissions' => $role->permissions->map(function ($permission) {
                        return [
                            'id' => $permission->id,
                            'resource_key' => $permission->resource_key,
                            'resource_label' => $permission->resource_label,
                            'can_view' => $permission->pivot->can_view,
                            'can_add' => $permission->pivot->can_add,
                            'can_edit' => $permission->pivot->can_edit,
                            'can_delete' => $permission->pivot->can_delete,
                        ];
                    }),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to assign permissions',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update specific permission for a role.
     */
    public function updatePermission(Request $request, Role $role, Permission $permission): JsonResponse
    {
        try {
            $request->validate([
                'can_view' => 'boolean',
                'can_add' => 'boolean',
                'can_edit' => 'boolean',
                'can_delete' => 'boolean',
            ]);

            // Enforce: Admin cannot edit Owner or Admin roles
            $authUser = $request->user();
            if ($authUser && $authUser->role?->name === 'Admin' && in_array($role->name, ['Owner', 'Admin'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Admins cannot modify Owner/Admin role permissions.',
                ], 403);
            }

            $role->permissions()->updateExistingPivot($permission->id, [
                'can_view' => $request->input('can_view', false),
                'can_add' => $request->input('can_add', false),
                'can_edit' => $request->input('can_edit', false),
                'can_delete' => $request->input('can_delete', false),
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Permission updated successfully',
                'data' => [
                    'role_id' => $role->id,
                    'role_name' => $role->name,
                    'permission' => [
                        'id' => $permission->id,
                        'resource_key' => $permission->resource_key,
                        'resource_label' => $permission->resource_label,
                        'can_view' => $request->input('can_view', false),
                        'can_add' => $request->input('can_add', false),
                        'can_edit' => $request->input('can_edit', false),
                        'can_delete' => $request->input('can_delete', false),
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update permission',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove a specific permission from a role.
     */
    public function removePermission(Role $role, Permission $permission): JsonResponse
    {
        try {
            $role->permissions()->detach($permission->id);

            return response()->json([
                'status' => 'success',
                'message' => 'Permission removed successfully',
                'data' => [
                    'role_id' => $role->id,
                    'role_name' => $role->name,
                    'permission' => [
                        'id' => $permission->id,
                        'resource_key' => $permission->resource_key,
                        'resource_label' => $permission->resource_label,
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to remove permission',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get all roles for a specific permission.
     */
    public function getPermissionRoles(Permission $permission): JsonResponse
    {
        try {
            $permission->load('roles');

            $roles = $permission->roles->map(function ($role) {
                return [
                    'id' => $role->id,
                    'name' => $role->name,
                    'description' => $role->description,
                    'can_view' => $role->pivot->can_view,
                    'can_add' => $role->pivot->can_add,
                    'can_edit' => $role->pivot->can_edit,
                    'can_delete' => $role->pivot->can_delete,
                ];
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Permission roles retrieved successfully',
                'data' => [
                    'permission_id' => $permission->id,
                    'resource_key' => $permission->resource_key,
                    'resource_label' => $permission->resource_label,
                    'roles' => $roles,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve permission roles',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Check if a role has specific permission for a resource.
     */
    public function checkPermission(Request $request, Role $role): JsonResponse
    {
        try {
            $request->validate([
                'resource_key' => 'required|string|exists:permissions,resource_key',
                'action' => 'required|string|in:view,add,edit,delete',
            ]);

            $resourceKey = $request->input('resource_key');
            $action = $request->input('action');

            $permission = $role->permissions()->where('resource_key', $resourceKey)->first();

            if (! $permission) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Permission check completed',
                    'data' => [
                        'role_id' => $role->id,
                        'role_name' => $role->name,
                        'resource_key' => $resourceKey,
                        'action' => $action,
                        'has_permission' => false,
                    ],
                ]);
            }

            $hasPermission = false;
            switch ($action) {
                case 'view':
                    $hasPermission = $permission->pivot->can_view;

                    break;
                case 'add':
                    $hasPermission = $permission->pivot->can_add;

                    break;
                case 'edit':
                    $hasPermission = $permission->pivot->can_edit;

                    break;
                case 'delete':
                    $hasPermission = $permission->pivot->can_delete;

                    break;
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Permission check completed',
                'data' => [
                    'role_id' => $role->id,
                    'role_name' => $role->name,
                    'resource_key' => $resourceKey,
                    'action' => $action,
                    'has_permission' => $hasPermission,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to check permission',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
