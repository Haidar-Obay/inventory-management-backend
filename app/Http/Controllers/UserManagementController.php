<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use App\Helpers\CacheHelper;

class UserManagementController extends Controller
{
    // Deprecated: legacy role column authorization removed. Use middleware-based permissions.
    protected function authorizeRoles(array $roles)
    {
        return Auth::user();
    }

    protected function getCacheKey($suffix, $id = null)
    {
        $prefix = tenancy()->initialized
            ? 'tenant_' . tenant('id')
            : 'central';

        return $id ? "{$prefix}_{$suffix}_{$id}" : "{$prefix}_{$suffix}";
    }

    /**
     * Check whether the given user has a specific role by name.
     */
    protected function userHasRole(User $user, string $roleName): bool
    {
        // Roles are tenant-scoped. If tenancy isn't initialized or tables don't exist, treat as no role.
        if (!tenancy()->initialized) {
            return false;
        }

        if (!Schema::hasTable('roles') || !Schema::hasTable('user_roles')) {
            return false;
        }

        return $user->roles()->where('name', $roleName)->exists();
    }

    public function registerUser(Request $request)
    {
        
        // $email = $request->email;
        // $url = "https://apilayer.net/api/check?access_key=774df7c6873b3b081fb76f9e71580f93&email={$email}&smtp=1&format=1";
        // $response = Http::get($url);

        // if ($response->successful()) {
        //     $data = $response->json();

        //     if (
        //         !isset($data['format_valid'], $data['mx_found'], $data['smtp_check']) ||
        //         !($data['format_valid'] && $data['mx_found'] && $data['smtp_check'])
        //     ) {
        //         return response()->json([
        //             'status' => false,
        //             'message' => 'Email appears to be invalid or unreachable.',
        //         ], 422);
        //     }
        // } else {
        //     return response()->json([
        //         'status' => false,
        //         'message' => 'Could not validate email address. Try again later.',
        //     ], 500);
        // }
        $validated = $request->validate([
            'name' => 'required',
            'email' => 'required_if:active,true|nullable|email|unique:users',
            'password' => 'required_if:active,true|nullable',
            'active' => 'boolean',
        ]);

        $userData = [
            'name' => $validated['name'],
            'active' => $validated['active'] ?? true,
            'created_by' => Auth::user()->id,
        ];

        // Only set email and password if user is active
        if ($validated['active'] ?? true) {
            $userData['email'] = $validated['email'];
            $userData['password'] = Hash::make($validated['password']);
        }

        $user = User::create($userData);

        // Comment out email verification
        // $user->sendEmailVerificationNotification();

        CacheHelper::cacheInContext($this->getCacheKey('users'), null);

        return response()->json(['message' => 'User created successfully.', 'user' => $user], 201);
    }

    public function registerUserForCentral(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required',
            'email' => 'required_if:active,true|nullable|email|unique:users',
            'password' => 'required_if:active,true|nullable',
            'active' => 'boolean',
        ]);

        $userData = [
            'name' => $validated['name'],
            'active' => $validated['active'] ?? true,
        ];

        // Only set email and password if user is active
        if ($validated['active'] ?? true) {
            $userData['email'] = $validated['email'];
            $userData['password'] = Hash::make($validated['password']);
        }

        $user = User::create($userData);

        CacheHelper::cacheInContext($this->getCacheKey('central_users'), null);

        return response()->json(['message' => 'User created successfully.', 'user' => $user], 201);
    }

    public function getAllUsers()
    {
        // $this->authorizeRoles(['admin', 'owner']);

        $cacheKey = $this->getCacheKey('users');
        $users = CacheHelper::cacheInContext($cacheKey);

        if (!$users) {
            $users = User::select('id', 'name', 'email', 'active', 'created_at', 'created_by')
                ->orderBy('created_at', 'desc')->with(['roles', 'creator'])
                ->get();

            CacheHelper::cacheInContext($cacheKey, $users);
        }

        return response()->json([
            'message' => 'Users retrieved successfully.',
            'users' => $users,
        ]);
    }

    public function getAllUsersForCentral()
    {
        $cacheKey = $this->getCacheKey('central_users');
        $users = CacheHelper::cacheInContext($cacheKey);

        if (!$users) {
            $users = User::select('id', 'name', 'email', 'active', 'created_at')
                ->orderBy('created_at', 'desc')
                ->get();

            CacheHelper::cacheInContext($cacheKey, $users);
        }

        return response()->json([
            'message' => 'Users retrieved successfully.',
            'users' => $users,
        ]);
    }

    public function getAssignableUsers()
    {
        $cacheKey = $this->getCacheKey('assignable_users');
        $users = CacheHelper::cacheInContext($cacheKey);

        if (!$users) {
            $users = User::select('id', 'name', 'email', 'active')
                ->with(['roles:id,name'])
                ->whereDoesntHave('roles', function ($query) {
                    $query->whereIn('name', ['owner', 'admin']);
                })
                ->where('active', true)
                ->orderBy('name')
                ->get();

            CacheHelper::cacheInContext($cacheKey, $users);
        }

        return response()->json([
            'message' => 'Assignable users retrieved successfully.',
            'users' => $users,
        ]);
    }

    public function getUser($id)
    {
        // $this->authorizeRoles(['admin', 'owner']);

        // Validate ID parameter
        if (!$id || $id === 'undefined' || !is_numeric($id)) {
            return response()->json(['message' => 'Invalid user ID provided.'], 400);
        }

        $cacheKey = $this->getCacheKey('user', $id);
        $user = CacheHelper::cacheInContext($cacheKey);

        if (!$user) {
            $user = User::select('id', 'name', 'email', 'active', 'created_at', 'created_by')->with(['roles', 'creator'])->find($id);

            if (!$user) {
                return response()->json(['message' => 'User not found.'], 404);
            }

            CacheHelper::cacheInContext($cacheKey, $user);
        }

        return response()->json([
            'message' => 'User retrieved successfully.',
            'user' => $user,
        ]);
    }

    /**
     * Return the authenticated user.
     */
    public function me(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $user->load(['roles', 'creator']);

        return response()->json([
            'message' => 'User retrieved successfully.',
            'user' => $user,
        ]);
    }

    public function updateUser(Request $request, $id)
    {
        $authUser = $this->authorizeRoles(['admin', 'owner']);

        // Validate ID parameter
        if (!$id || $id === 'undefined' || !is_numeric($id)) {
            return response()->json(['message' => 'Invalid user ID provided.'], 400);
        }

        $user = User::find($id);

        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        // Prevent non-Owner from updating an Owner user
        $authIsOwner = $this->userHasRole($authUser, 'Owner');
        $targetIsOwner = $this->userHasRole($user, 'Owner');
        if ($targetIsOwner && !$authIsOwner) {
            return response()->json(['message' => 'You are not allowed to modify an Owner user.'], 403);
        }

        // Check if user has password in database
        $hasPassword = !empty($user->password);
        
        // Build validation rules based on whether user has password
        $validationRules = [
            'name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $id,
            'active' => 'sometimes|boolean',
        ];
        
        // If user doesn't have password, make it required
        if (!$hasPassword) {
            $validationRules['password'] = 'required|confirmed|min:6';
        } else {
            $validationRules['password'] = 'sometimes|confirmed|min:6';
        }

        $validated = $request->validate($validationRules, [
            'password.required' => 'Password is required when user is active'
        ]);

        $updateData = [];

        if (isset($validated['name'])) {
            $updateData['name'] = $validated['name'];
        }

        if (isset($validated['email'])) {
            $updateData['email'] = $validated['email'];
        }

        if (isset($validated['password'])) {
            $updateData['password'] = Hash::make($validated['password']);
        }

        if (isset($validated['active'])) {
            $updateData['active'] = $validated['active'];
        }

        try {
            $user->update($updateData);

            // Clear cache
            CacheHelper::cacheInContext($this->getCacheKey('users'), null);
            CacheHelper::cacheInContext($this->getCacheKey('user', $id), null);

            // Only load roles if we're in a tenant context
            $freshUser = tenancy()->initialized ? $user->fresh(['roles']) : $user->fresh();
            
            return response()->json([
                'message' => 'User updated successfully.',
                'user' => $freshUser
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update user.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function deleteUser($id)
    {
        // Validate ID parameter
        if (!$id || $id === 'undefined' || !is_numeric($id)) {
            return response()->json(['message' => 'Invalid user ID provided.'], 400);
        }

        $authUser = Auth::user();

        if ($authUser->id == $id) {
            return response()->json(['message' => 'You cannot delete your own account.'], 403);
        }

        $user = User::find($id);

        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        // Prevent non-Owner from deleting an Owner user
        $authIsOwner = $this->userHasRole($authUser, 'Owner');
        $targetIsOwner = $this->userHasRole($user, 'Owner');
        if ($targetIsOwner && !$authIsOwner) {
            return response()->json(['message' => 'You are not allowed to modify an Owner user.'], 403);
        }

        // Note: Role-based authorization now handled by middleware/permissions

        try {
            $user->delete();

            CacheHelper::cacheInContext($this->getCacheKey('users'), null);
            CacheHelper::cacheInContext($this->getCacheKey('user', $id), null);

            return response()->json(['message' => 'User deleted successfully.']);
        } catch (\Illuminate\Database\QueryException $e) {
            return response()->json(['message' => 'User could not be deleted.'], 400);
        }
    }

    public function bulkDeleteUsers(Request $request)
    {
        $authUser = $this->authorizeRoles(['admin', 'owner']);

        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:users,id',
        ]);

        $skipped = [];
        $deleted = 0;

        foreach ($request->ids as $id) {
            if ($authUser->id == $id) {
                $skipped[] = ['id' => $id, 'reason' => 'Cannot delete the currently authenticated user.'];
                continue;
            }

            // Skip deleting Owner user if auth user is not Owner
            $target = User::find($id);
            if ($target && $this->userHasRole($target, 'Owner') && !$this->userHasRole($authUser, 'Owner')) {
                $skipped[] = ['id' => $id, 'reason' => 'You are not allowed to modify an Owner user.'];
                continue;
            }

            try {
                $deleted += User::where('id', $id)->delete();
                CacheHelper::cacheInContext($this->getCacheKey('user', $id), null);
            } catch (\Illuminate\Database\QueryException $e) {
                $skipped[] = ['id' => $id, 'reason' => 'Deletion failed due to constraints or DB error.'];
            }
        }

        CacheHelper::cacheInContext($this->getCacheKey('users'), null);

        return response()->json([
            'message' => 'Bulk user deletion completed.',
            'deleted_count' => $deleted,
            'skipped' => $skipped,
        ]);
    }

    /**
     * Toggle user active status.
     */
    public function toggleUserStatus(Request $request, $id)
    {
        // Validate ID parameter
        if (!$id || $id === 'undefined' || !is_numeric($id)) {
            return response()->json(['message' => 'Invalid user ID provided.'], 400);
        }

        $authUser = $this->authorizeRoles(['admin', 'owner']);

        if ($authUser->id == $id) {
            return response()->json(['message' => 'You cannot change your own account status.'], 403);
        }

        $user = User::find($id);

        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        // Prevent non-Owner from toggling an Owner user's status
        $authIsOwner = $this->userHasRole($authUser, 'Owner');
        $targetIsOwner = $this->userHasRole($user, 'Owner');
        if ($targetIsOwner && !$authIsOwner) {
            return response()->json(['message' => 'You are not allowed to modify an Owner user.'], 403);
        }

        // Note: Role-based authorization now handled by middleware/permissions

        // If activating user (from false to true)
        if (!$user->active) {
            // Check if user already has email and password
            if ($user->email && $user->password) {
                // User has credentials, activate instantly
                try {
                    $user->update(['active' => true]);
                } catch (\Exception $e) {
                    return response()->json(['message' => 'Failed to activate user.'], 500);
                }
            } else {
                // User needs email and password, require them
                if (!$request->has('email') || !$request->has('password')) {
                    return response()->json([
                        'message' => 'Add an email and password for the user and try again'
                    ], 422);
                }

                $validated = $request->validate([
                    'email' => 'required|email|unique:users,email,' . $id,
                    'password' => 'required|confirmed|min:6',
                ]);

                try {
                    $user->update([
                        'active' => true,
                        'email' => $validated['email'],
                        'password' => Hash::make($validated['password']),
                    ]);
                } catch (\Exception $e) {
                    return response()->json(['message' => 'Failed to activate user. Please check email and password.'], 500);
                }
            }
        } else {
            // If deactivating user (from true to false), just update status
            try {
                $user->update(['active' => false]);
            } catch (\Exception $e) {
                return response()->json(['message' => 'Failed to deactivate user.'], 500);
            }
        }

        // Clear cache
        CacheHelper::cacheInContext($this->getCacheKey('users'), null);
        CacheHelper::cacheInContext($this->getCacheKey('user', $id), null);

        $action = $user->active ? 'activated' : 'deactivated';
        
        return response()->json([
            'message' => "User {$action} successfully.",
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'active' => $user->active
            ]
        ]);
    }
}
