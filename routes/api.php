<?php

declare(strict_types=1);

use App\Http\Controllers\AuthController;
// use App\Http\Controllers\TenantAuthController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\TenantUserManagementController;
use App\Http\Controllers\UserManagementController;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;



use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Auth;
/*
|--------------------------------------------------------------------------
| Sanctum‐protected “current user” endpoint
|--------------------------------------------------------------------------
*/
Route::get('/user', fn(Request $request) => $request->user())
    ->middleware('auth:sanctum');

/*
|--------------------------------------------------------------------------
| Central‐domain tenant management
|--------------------------------------------------------------------------
*/
foreach (config('tenancy.central_domains') as $domain) {
    Route::domain($domain)->group(function () {
        // Central root
        Route::get('/', fn() => response()->json([
            'message' => 'This is your central application.',
        ]));



        // Tenant CRUD
        Route::middleware(['auth:sanctum'])->prefix('tenant')->group(function () {
            Route::post('', [TenantController::class, 'store']);
            Route::delete('{id}', [TenantController::class, 'deleteTenant']);
            Route::get('all', [TenantController::class, 'getAllTenants']);
            Route::get('{id}', [TenantController::class, 'getTenant']);
            Route::put('{id}', [TenantController::class, 'updateTenant']);
            Route::get('export/excell', [TenantController::class, 'exportExcell']);
        });




        // Auth & User Management
        Route::post('/login', [AuthController::class, 'login']);



        Route::middleware(['auth:sanctum', 'verified'])->group(function () {
            Route::post('/register', [UserManagementController::class, 'registerUser']);
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::get('/get-all-users', action: [UserManagementController::class, 'getAllUsers']);
            Route::get('/get-user/{id}', action: [UserManagementController::class, 'getUser']);
            Route::delete('/delete-user/{id}', [UserManagementController::class, 'deleteUser']);
            Route::delete('/bulk-delete-users', [UserManagementController::class, 'bulkDeleteUsers']);
        });



        Route::get('/email/verify/{id}/{hash}', function (Request $request, $id, $hash) {
            $user = User::find($id);

            if (!$user) {
                return response()->json(['message' => 'User not found'], 404);
            }

            if (!hash_equals((string) $hash, sha1($user->getEmailForVerification()))) {
                return response()->json(['message' => 'Invalid verification link'], 403);
            }

            if ($user->hasVerifiedEmail()) {
                return response()->json(['message' => 'Email already verified']);
            }

            $user->markEmailAsVerified();
            event(new Verified($user));

            return response()->json(['message' => 'Email verified successfully']);
        })->middleware(['signed'])->name('verification.verify');
    });
}
