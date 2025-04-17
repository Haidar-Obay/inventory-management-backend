<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TenantAuthController extends Controller
{
    public function login(Request $request)
    {
        // Validate input
        $credentials = $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        // Attempt login
        if (!Auth::attempt($credentials)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $user = Auth::user();

        // Optional: enforce email verification
        // if (!$user->hasVerifiedEmail()) {
        //     return response()->json(['message' => 'Please verify your email address.'], 403);
        // }

        // Create token
        $token = $user->createToken('tenant-auth-token')->plainTextToken;

        return response()->json([
            'message'      => 'Login successful',
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'user'         => $user, // optional: return user info
        ]);
    }

    public function logout(Request $request)
    {
        // Revoke current user's tokens
        $request->user()->tokens()->delete();

        return response()->json(['message' => 'Logged out']);
    }
}
