<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

class TenantUserManagementController extends Controller
{
    public function registerUser(Request $request)
    {
        $authUser = auth()->user();

        if ($authUser->role !== 'admin') {
            return response()->json(['message' => 'Only admins can create new users'], 403);
        }

        // Validate structure first
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users',
            'password' => 'required|confirmed|min:6',
            'role' => 'nullable|in:user,admin',
        ]);

        // Email verification via API Layer (disabled for now)
        // $email = $validated['email'];
        // $url = "https://apilayer.net/api/check?access_key=YOUR_KEY&email={$email}&smtp=1&format=1";
        // $response = Http::get($url);
        // if ($response->failed() || !($data = $response->json()) || !($data['format_valid'] ?? false) || !($data['mx_found'] ?? false) || !($data['smtp_check'] ?? false)) {
        //     return response()->json([
        //         'status' => false,
        //         'message' => 'Email appears to be invalid or unreachable.',
        //     ], 422);
        // }

        // Create the user
        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'] ?? 'user',
            'email_verified_at' => now(), // auto-verify for now
        ]);

        return response()->json([
            'message' => 'User created successfully.',
            'user' => $user->only('id', 'name', 'email', 'role'),
        ], 201);
    }


    public function getAllUsers()
    {
        $authUser = auth()->user();

        if ($authUser->role !== 'admin') {
            return response()->json(['message' => 'Only admins can view users.'], 403);
        }

        $users = User::select('id', 'name', 'email', 'role', 'created_at')
            ->latest()
            ->get();

        return response()->json([
            'message' => 'Users retrieved successfully.',
            'users' => $users,
        ]);
    }

    public function getUser($id)
    {
        $authUser = auth()->user();

        if ($authUser->role !== 'admin') {
            return response()->json(['message' => 'Only admins can view user details.'], 403);
        }

        $user = User::select('id', 'name', 'email', 'role', 'created_at')->find($id);

        return $user
            ? response()->json(['user' => $user])
            : response()->json(['message' => 'User not found.'], 404);
    }

    public function deleteUser(Request $request)
    {
        $authUser = auth()->user();

        if ($authUser->role !== 'admin') {
            return response()->json(['message' => 'Only admins can delete users.'], 403);
        }

        $request->validate([
            'id' => 'required|exists:users,id',
        ]);

        if ($authUser->id == $request->id) {
            return response()->json(['message' => 'You cannot delete your own account.'], 403);
        }

        $deleted = User::where('id', $request->id)->delete();

        return response()->json([
            'message' => $deleted ? 'User deleted successfully.' : 'User could not be deleted.',
        ]);
    }

    public function bulkDeleteUsers(Request $request)
    {
        $authUser = auth()->user();

        if ($authUser->role !== 'admin') {
            return response()->json(['message' => 'Only admins can delete users.'], 403);
        }

        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:users,id',
        ]);

        $skipped = [];
        $deleted = 0;

        foreach ($request->ids as $id) {
            if ($authUser->id == $id) {
                $skipped[] = ['id' => $id, 'reason' => 'Cannot delete yourself'];
                continue;
            }

            try {
                $deleted += User::where('id', $id)->delete();
            } catch (\Throwable $e) {
                $skipped[] = ['id' => $id, 'reason' => 'DB error or constraint'];
            }
        }

        return response()->json([
            'message' => 'Bulk deletion processed.',
            'deleted_count' => $deleted,
            'skipped' => $skipped,
        ]);
    }
}
