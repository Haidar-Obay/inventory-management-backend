<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class UserRoleController extends Controller
{
    /**
     * Assign roles to a user.
     */
    public function assignRoles(Request $request, User $user): JsonResponse
    {
        try {
            $request->validate([
                'role_ids' => 'required|array',
                'role_ids.*' => 'exists:roles,id'
            ]);

            $roleIds = $request->input('role_ids');
            
            // Sync roles (this will replace existing roles)
            $user->roles()->sync($roleIds);

            // Reload user with roles
            $user->load('roles');

            return response()->json([
                'status' => 'success',
                'message' => 'Roles assigned successfully',
                'data' => [
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'assigned_roles' => $user->roles->map(function ($role) {
                        return [
                            'id' => $role->id,
                            'name' => $role->name,
                            'description' => $role->description
                        ];
                    })
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to assign roles',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove roles from a user.
     */
    public function removeRoles(Request $request, User $user): JsonResponse
    {
        try {
            $request->validate([
                'role_ids' => 'required|array',
                'role_ids.*' => 'exists:roles,id'
            ]);

            $roleIds = $request->input('role_ids');
            
            // Detach specified roles
            $user->roles()->detach($roleIds);

            // Reload user with remaining roles
            $user->load('roles');

            return response()->json([
                'status' => 'success',
                'message' => 'Roles removed successfully',
                'data' => [
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'remaining_roles' => $user->roles->map(function ($role) {
                        return [
                            'id' => $role->id,
                            'name' => $role->name,
                            'description' => $role->description
                        ];
                    })
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to remove roles',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all roles for a specific user.
     */
    public function getUserRoles(User $user): JsonResponse
    {
        try {
            $user->load('roles');

            return response()->json([
                'status' => 'success',
                'message' => 'User roles retrieved successfully',
                'data' => [
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'email' => $user->email,
                    'roles' => $user->roles->map(function ($role) {
                        return [
                            'id' => $role->id,
                            'name' => $role->name,
                            'description' => $role->description,
                            'active' => $role->active
                        ];
                    })
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve user roles',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all users for a specific role.
     */
    public function getRoleUsers(Role $role): JsonResponse
    {
        try {
            $role->load('users');

            return response()->json([
                'status' => 'success',
                'message' => 'Role users retrieved successfully',
                'data' => [
                    'role_id' => $role->id,
                    'role_name' => $role->name,
                    'users' => $role->users->map(function ($user) {
                        return [
                            'id' => $user->id,
                            'name' => $user->name,
                            'email' => $user->email,
                            'created_at' => $user->created_at
                        ];
                    })
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve role users',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Check if a user has a specific role.
     */
    public function checkUserRole(Request $request, User $user): JsonResponse
    {
        try {
            $request->validate([
                'role_name' => 'required|string|exists:roles,name'
            ]);

            $roleName = $request->input('role_name');
            $hasRole = $user->roles()->where('name', $roleName)->exists();

            return response()->json([
                'status' => 'success',
                'message' => 'Role check completed',
                'data' => [
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'role_name' => $roleName,
                    'has_role' => $hasRole
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to check user role',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
