<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserRoleController extends Controller
{
    /**
     * Assign a single role to a user.
     */
    public function assignRoles(Request $request, User $user): JsonResponse
    {
        try {
            $request->validate([
                'role_id' => 'required|exists:roles,id',
            ]);

            $roleId = (int) $request->input('role_id');

            // Check if trying to assign Owner role
            $ownerRole = Role::where('id', $roleId)->where('name', 'Owner')->first();
            if ($ownerRole) {
                // Check if there's already an owner
                $existingOwner = User::whereHas('role', function ($query) {
                    $query->where('name', 'Owner');
                })->first();

                if ($existingOwner && $existingOwner->id !== $user->id) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Only one user can have the Owner role.',
                    ], 422);
                }
            }

            // Enforce: Admin cannot assign Admin or Owner roles
            $authUser = $request->user();
            if ($authUser && $authUser->role?->name === 'Admin' && Role::where('id', $roleId)->whereIn('name', ['Admin', 'Owner'])->exists()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Admins cannot assign Admin or Owner roles.',
                ], 403);
            }

            // Set single role
            $user->role()->associate($roleId);
            $user->save();

            // Reload user with role
            $user->load('role');

            return response()->json([
                'status' => 'success',
                'message' => 'Roles assigned successfully',
                'data' => [
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'assigned_role' => $user->role ? [
                        'id' => $user->role->id,
                        'name' => $user->role->name,
                        'description' => $user->role->description,
                    ] : null,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to assign roles',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Clear role from a user (set to null).
     */
    public function removeRoles(Request $request, User $user): JsonResponse
    {
        try {
            // Enforce: Admin cannot remove Admin or Owner roles
            $authUser = $request->user();
            if ($authUser && $authUser->role?->name === 'Admin' && in_array(optional($user->role)->name, ['Admin', 'Owner'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Admins cannot modify Admin or Owner roles.',
                ], 403);
            }

            // Disassociate role
            $user->role()->dissociate();
            $user->save();

            // Reload user
            $user->load('role');

            return response()->json([
                'status' => 'success',
                'message' => 'Roles removed successfully',
                'data' => [
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'role' => $user->role ? [
                        'id' => $user->role->id,
                        'name' => $user->role->name,
                        'description' => $user->role->description,
                    ] : null,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to remove roles',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get role for a specific user.
     */
    public function getUserRoles(User $user): JsonResponse
    {
        try {
            $user->load('role');

            return response()->json([
                'status' => 'success',
                'message' => 'User roles retrieved successfully',
                'data' => [
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role ? [
                        'id' => $user->role->id,
                        'name' => $user->role->name,
                        'description' => $user->role->description,
                        'active' => $user->role->active,
                    ] : null,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve user roles',
                'error' => $e->getMessage(),
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
                            'created_at' => $user->created_at,
                        ];
                    }),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve role users',
                'error' => $e->getMessage(),
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
                'role_name' => 'required|string|exists:roles,name',
            ]);

            $roleName = $request->input('role_name');
            $hasRole = optional($user->role)->name === $roleName;

            return response()->json([
                'status' => 'success',
                'message' => 'Role check completed',
                'data' => [
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'role_name' => $roleName,
                    'has_role' => $hasRole,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to check user role',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
