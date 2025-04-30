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
            'email' => 'required|email|unique:users',
            'password' => 'required|confirmed',
            'role' => "nullable|in:{$allowedRoles}",
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
        ]);

        CacheHelper::cacheInContext($this->getCacheKey('users'), null);

        return response()->json(['message' => 'User created successfully.', 'user' => $user], 201);
    }

    public function getAllUsers()
    {
        $this->authorizeRoles(['admin', 'owner']);

        $cacheKey = $this->getCacheKey('users');
        $users = CacheHelper::cacheInContext($cacheKey);

        if (!$users) {
            $users = User::select('id', 'name', 'email', 'role', 'created_at')
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
            $user = User::select('id', 'name', 'email', 'role', 'created_at')->find($id);

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
}
