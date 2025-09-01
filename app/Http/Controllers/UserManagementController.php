<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Helpers\CacheHelper;

class UserManagementController extends Controller
{
    protected function authorizeRoles(array $roles)
    {
        $authUser = auth()->user();

        if (!in_array($authUser->role, $roles)) {
            abort(response()->json(['message' => 'Only owner or admins can perform this operation'], 403));
        }

        return $authUser;
    }

    protected function getCacheKey($suffix, $id = null)
    {
        $prefix = tenancy()->initialized
            ? 'tenant_' . tenant('id')
            : 'central';

        return $id ? "{$prefix}_{$suffix}_{$id}" : "{$prefix}_{$suffix}";
    }

    public function registerUser(Request $request)
    {
        $authUser = $this->authorizeRoles(['admin', 'owner']);
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
        $allowedRoles = $authUser->role === 'owner' ? 'user,admin' : 'user';
    
        $validated = $request->validate([
            'name' => 'required',
            'email' => 'required_if:active,true|nullable|email|unique:users',
            'password' => 'required_if:active,true|nullable|confirmed',
            'role' => "nullable|in:{$allowedRoles}",
            'active' => 'boolean',
        ]);

        $userData = [
            'name' => $validated['name'],
            'role' => $validated['role'] ?? 'user',
            'active' => $validated['active'] ?? true,
        ];

        // Only set email and password if user is active
        if ($validated['active'] ?? true) {
            $userData['email'] = $validated['email'];
            $userData['password'] = Hash::make($validated['password']);
        }

        $user = User::create($userData);

        CacheHelper::cacheInContext($this->getCacheKey('users'), null);

        return response()->json(['message' => 'User created successfully.', 'user' => $user], 201);
    }

    public function getAllUsers()
    {
        $this->authorizeRoles(['admin', 'owner']);

        $cacheKey = $this->getCacheKey('users');
        $users = CacheHelper::cacheInContext($cacheKey);

        if (!$users) {
            $users = User::select('id', 'name', 'email', 'role', 'active', 'created_at')
                ->orderBy('created_at', 'desc')
                ->get();

            CacheHelper::cacheInContext($cacheKey, $users);
        }

        return response()->json([
            'message' => 'Users retrieved successfully.',
            'users' => $users,
        ]);
    }

    public function getUser($id)
    {
        $this->authorizeRoles(['admin', 'owner']);

        $cacheKey = $this->getCacheKey('user', $id);
        $user = CacheHelper::cacheInContext($cacheKey);

        if (!$user) {
            $user = User::select('id', 'name', 'email', 'role', 'active', 'created_at')->find($id);

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

    public function deleteUser($id)
    {
        $authUser = $this->authorizeRoles(['admin', 'owner']);

        if ($authUser->id == $id) {
            return response()->json(['message' => 'You cannot delete your own account.'], 403);
        }

        $user = User::find($id);

        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        if ($authUser->role === 'admin' && $user->role !== 'user') {
            return response()->json(['message' => 'Admins can only delete users.'], 403);
        }

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
        $authUser = $this->authorizeRoles(['admin', 'owner']);

        if ($authUser->id == $id) {
            return response()->json(['message' => 'You cannot change your own account status.'], 403);
        }

        $user = User::find($id);

        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        if ($authUser->role === 'admin' && $user->role !== 'user') {
            return response()->json(['message' => 'Admins can only modify users.'], 403);
        }

        // If activating user (from false to true), require email and password
        if (!$user->active) {
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
