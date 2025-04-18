<?php

declare(strict_types=1);

use App\Http\Controllers\AuthController;
use App\Http\Controllers\TenantAuthController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\TenantUserManagementController;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;


/*
|--------------------------------------------------------------------------
| Email Verification (must be loaded before tenancy routes)
|--------------------------------------------------------------------------
*/
// Route::middleware(['auth:sanctum', 'signed'])
//     ->get('email/verify/{id}/{hash}', function (EmailVerificationRequest $request) {
//         $request->fulfill();
//         return response()->json(['message' => 'Email verified!']);
//     })
//     ->name('verification.verify');

// Route::middleware(['auth:sanctum', 'throttle:6,1'])
//     ->post('email/verification-notification', function (Request $request) {
//         $request->user()->sendEmailVerificationNotification();
//         return response()->json(['message' => 'Verification email resent']);
//     })
//     ->name('verification.send');

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

        Route::post('/login', [AuthController::class, 'login']);

        Route::middleware(['auth:sanctum'])->group(function () {
            // Auth & User Management
            Route::post('/register', [TenantUserManagementController::class, 'registerUser']);
            Route::post('/logout', [TenantAuthController::class, 'logout']);
            Route::get('/get-all-users', action: [TenantUserManagementController::class, 'getAllUsers']);
            Route::get('/get-user/{id}', action: [TenantUserManagementController::class, 'getUser']);
            Route::delete('/delete-user', [TenantUserManagementController::class, 'deleteUser']);
            Route::delete('/bulk-delete-users', [TenantUserManagementController::class, 'bulkDeleteUsers']);
        });

        // Route::get('/email/verify/{id}/{hash}', function (EmailVerificationRequest $request) {
        //     $request->fulfill();
        //     return response()->json(['message' => 'Email verified!']);
        // })->middleware(['signed'])->name('verification.verify');

        // Route::post('/email/verification-notification', function (Request $request) {
        //     $request->user()->sendEmailVerificationNotification();
        //     return response()->json(['message' => 'Verification email resent']);
        // })->middleware(['auth:sanctum', 'throttle:6,1'])->name('verification.send');
    });
}
