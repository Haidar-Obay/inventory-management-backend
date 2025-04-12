<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserManagementController extends Controller
{
    public function registerUser(Request $request)
    {
        $authUser = auth()->user();

        if ($authUser->role !== 'admin') {
            return response()->json(['message' => 'Only admins can create new users'], 403);
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

        // $user->sendEmailVerificationNotification();

        return response()->json(['message' => 'User created. Verification email sent.']);
    }
}
