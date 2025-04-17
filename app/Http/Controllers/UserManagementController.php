<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
class UserManagementController extends Controller
{
    public function registerUser(Request $request)
    {
        $authUser = auth()->user();
        if ($authUser->role !== 'admin') {
            return response()->json(['message' => 'Only admins can create new users'], 403);
        }
        $email = $request->email;
        $url = "https://apilayer.net/api/check?access_key=774df7c6873b3b081fb76f9e71580f93&email={$email}&smtp=1&format=1";
        $response = Http::get($url);

        if ($response->successful()) {
            $data = $response->json();

            if (
                !isset($data['format_valid'], $data['mx_found'], $data['smtp_check']) ||
                !($data['format_valid'] && $data['mx_found'] && $data['smtp_check'])
            )
            {
                return response()->json([
                    'status' => false,
                    'message' => 'Email appears to be invalid or unreachable.',
                ], 422);
            }
        } else {
            return response()->json([
                'status' => false,
                'message' => 'Could not validate email address. Try again later.',
            ], 500);
        }

        $validated = $request->validate([
            'name' => 'required',
            'email' => 'required|email|unique:users',
            'password' => 'required|confirmed',
            'role' => 'nullable|in:user,admin',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
        ]);

        $user->sendEmailVerificationNotification();

        return response()->json(['message' => 'User created. Verification email sent.']);
    }

    public function getAllUsers()
    {
        $authUser = auth()->user();

        if ($authUser->role !== 'admin') {
            return response()->json(['message' => 'Only admins can view users.'], 403);
        }

        $users = User::select('id', 'name', 'email', 'role', 'created_at')
            ->orderBy('created_at', 'desc')
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

        $user = User::select('id', 'name', 'email', 'role', 'created_at')
            ->find($id);

        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        return response()->json([
            'message' => 'User retrieved successfully.',
            'user' => $user,
        ]);
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

        if ($request->id == $authUser->id) {
            return response()->json(['message' => 'You cannot delete your own account.'], 403);
        }

        try {
            User::where('id', $request->id)->delete();
            return response()->json(['message' => 'User deleted successfully.']);
        } catch (\Illuminate\Database\QueryException $e) {
            return response()->json(['message' => 'User could not be deleted.'], 400);
        }
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
            try {
                // Prevent admin from deleting themselves
                if ($authUser->id == $id) {
                    $skipped[] = [
                        'id' => $id,
                        'reason' => 'Cannot delete the currently authenticated admin.',
                    ];
                    continue;
                }

                $deleted += User::where('id', $id)->delete();
            } catch (\Illuminate\Database\QueryException $e) {
                $skipped[] = [
                    'id' => $id,
                    'reason' => 'Deletion failed due to constraints or DB error.',
                ];
            }
        }

        return response()->json([
            'message' => 'Bulk user deletion completed.',
            'deleted_count' => $deleted,
            'skipped' => $skipped,
        ]);
    }
}
