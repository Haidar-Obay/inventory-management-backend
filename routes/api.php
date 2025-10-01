<?php

declare(strict_types=1);

use App\Http\Controllers\AuditController;
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
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\SubscriptionPlanController;
use App\Http\Controllers\TenantSubscriptionController;


/*
|--------------------------------------------------------------------------
| Sanctum‐protected "current user" endpoint
|--------------------------------------------------------------------------
*/

Route::get('/user', fn(Request $request) => $request->user())
    ->middleware('auth:sanctum');

// Simple ping endpoint for CI/testing
Route::get('/ping', fn() => response()->json([
    'pong' => true,
    'time' => now()->toISOString(),
]));

/*
|--------------------------------------------------------------------------
| Central‐domain tenant management
|--------------------------------------------------------------------------
*/
foreach (config('tenancy.central_domains') as $domain) {
    Route::domain($domain)->middleware('api')->group(function () {
        // Central root
        Route::get('/', fn() => response()->json([
            'message' => 'This is your central application.',
        ]));


        //getting tenant by name
        Route::get('tenant/get-tenant-by-name/{name}', [TenantController::class, 'getTenantByName']);
        //log audit
        Route::get('audits', [AuditController::class, 'index']);


        // Tenant CRUD
        Route::middleware(['auth:sanctum'])->prefix('tenant')->group(function () {
            Route::post('', [TenantController::class, 'store']);
            Route::delete('bulk-delete-tenants', [TenantController::class, 'bulkDeleteTenants']);
            Route::delete('{id}', [TenantController::class, 'deleteTenant']);
            Route::get('all', [TenantController::class, 'getAllTenants']);
            Route::get('{id}', [TenantController::class, 'getTenant']);
            Route::put('{id}', [TenantController::class, 'updateTenant']);
            Route::get('export/excell', [TenantController::class, 'exportExcell']);
            Route::get('/exportPdf', [TenantController::class, 'exportPdf']);
        });

        // Subscription Plans Management
        Route::middleware(['auth:sanctum'])->prefix('subscription-plans')->group(function () {
            Route::get('/', [SubscriptionPlanController::class, 'index']);
            Route::get('/default', [SubscriptionPlanController::class, 'getDefaultPlan']);
            Route::get('/{id}', [SubscriptionPlanController::class, 'show']);
            Route::post('/', [SubscriptionPlanController::class, 'store']);
            Route::put('/{id}', [SubscriptionPlanController::class, 'update']);
            Route::delete('/{id}', [SubscriptionPlanController::class, 'destroy']);
        });

        // Tenant Subscription Management
        Route::middleware(['auth:sanctum'])->prefix('tenant-subscriptions')->group(function () {
            Route::get('/', [TenantSubscriptionController::class, 'getAllTenantsSubscriptions']);
            Route::get('/check-expired', [TenantSubscriptionController::class, 'checkExpiredSubscriptions']);
            Route::get('/{tenantId}', [TenantSubscriptionController::class, 'getTenantSubscription']);
            Route::post('/{tenantId}/upgrade', [TenantSubscriptionController::class, 'upgradeTenantPlan']);
            Route::post('/{tenantId}/cancel', [TenantSubscriptionController::class, 'cancelTenantSubscription']);
            Route::post('/{tenantId}/reactivate', [TenantSubscriptionController::class, 'reactivateTenantSubscription']);
        });

        // Auth & User Management
        Route::post('/login', [AuthController::class, 'login']);


        // User Management

        Route::middleware(['auth:sanctum'])->group(function () {
            Route::post('/register', [UserManagementController::class, 'registerUserForCentral']);
            Route::post('/logout', [AuthController::class, 'logout']);
            // Route::get('/get-all-users', action: [UserManagementController::class, 'getAllUsers']);
            Route::get('/get-all-users', action: [UserManagementController::class, 'getAllUsersForCentral']);
            Route::get('/get-user/{id}', action: [UserManagementController::class, 'getUser']);
            Route::put('/update-user/{id}', [UserManagementController::class, 'updateUser']);
            Route::delete('/delete-user/{id}', [UserManagementController::class, 'deleteUser']);
            Route::delete('/bulk-delete-users', [UserManagementController::class, 'bulkDeleteUsers']);
        });




        // Route::get('/email/verify/{id}/{hash}', function (Request $request, $id, $hash) {
        //     $user = User::find($id);

        //     if (!$user) {
        //         return response()->json(['message' => 'User not found'], 404);
        //     }

        //     if (! hash_equals((string) $hash, sha1($user->getEmailForVerification()))) {
        //         return response()->json(['message' => 'Invalid verification link'], 403);
        //     }
        //     if ($user->hasVerifiedEmail()) {
        //         return response()->json(['message' => 'Email already verified']);
        //     }
        //     $user->markEmailAsVerified();
        //     event(new Verified($user));
        //     return response()->json(['message' => 'Email verified successfully']);
        // })->middleware(['signed'])->name('verification.verify');



        // Resend email verification link - commented out
        // Route::post('/email/resend', function (Request $request) {
        //     $request->user()->sendEmailVerificationNotification();
        //     return response()->json(['message' => 'Verification link sent!']);
        // })->middleware(['auth:sanctum'])->name('verification.resend');


        //reset password

        Route::post('/forgot-password', [ForgotPasswordController::class, 'sendResetLink']);
        Route::post('/reset-password', [ResetPasswordController::class, 'reset']);

        

    });
    Route::get('/health', function () {
        return response()->json([
            'ok' => true,
            'time' => now()->toISOString(),
        ]);
    });
}
