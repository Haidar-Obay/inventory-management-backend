<?php

declare(strict_types=1);

use App\Http\Controllers\AuditController;
use App\Http\Controllers\Auth\ForgotPasswordController;
// use App\Http\Controllers\TenantAuthController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ModuleController;
use App\Http\Controllers\ModulePageController;
use App\Http\Controllers\ModuleResourceController;
use App\Http\Controllers\SubscriptionPlanController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\TenantModuleController;
use App\Http\Controllers\TenantSubscriptionController;
use App\Http\Controllers\UserManagementController;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Sanctum‐protected "current user" endpoint
|--------------------------------------------------------------------------
*/

Route::get('/user', fn (Request $request) => $request->user())
    ->middleware('auth:sanctum');

// Simple ping endpoint for CI/testing

/*
|--------------------------------------------------------------------------
| Central‐domain tenant management
|--------------------------------------------------------------------------
*/
foreach (config('tenancy.central_domains') as $domain) {
    Route::domain($domain)->middleware('api')->group(function () {
        // Central root
        Route::get('/', fn () => response()->json([
            'message' => 'This is your central application.',
        ]));
        // getting tenant by name
        Route::get('tenant/get-tenant-by-name/{name}', [TenantController::class, 'getTenantByName']);
        
        // Public module pages route (no auth required)
        Route::get('get-module-pages/{moduleId}/pages', [TenantModuleController::class, 'getModulePages']);
        // log audit
        Route::get('audits', [AuditController::class, 'index']);

        // Tenant CRUD
        Route::middleware(['auth:sanctum'])->prefix('tenant')->group(function () {
            Route::post('', [TenantController::class, 'store']);
            Route::get('', [TenantController::class, 'getAllTenants']);
            Route::get('delete/{id}', [TenantController::class, 'deleteTenant']);
            Route::get('makeDatabase/{id}', [TenantController::class, 'makeDatabase']);
            Route::get('runMigrations/{id}', [TenantController::class, 'runMigrations']);
            Route::get('deleteDatabase/{id}', [TenantController::class, 'deleteDatabase']);
            Route::get('createAdmin/{id}', [TenantController::class, 'createAdmin']);
            Route::put('{id}', [TenantController::class, 'updateTenant']);
            Route::get('export/excell', [TenantController::class, 'exportExcell']);
            Route::get('/exportPdf', [TenantController::class, 'exportPdf']);
        });

        // Modules Management
        Route::middleware(['auth:sanctum'])->prefix('modules')->group(function () {
            Route::get('/', [ModuleController::class, 'index']);
            Route::get('/{id}', [ModuleController::class, 'show']);
            Route::post('/', [ModuleController::class, 'store']);
            Route::put('/{id}', [ModuleController::class, 'update']);
            Route::delete('/{id}', [ModuleController::class, 'destroy']);
            Route::get('/usage-stats', [ModuleController::class, 'getUsageStats']);

            // Module Pages (nested)
            Route::get('/{moduleId}/pages', [ModulePageController::class, 'index']);
            Route::post('/{moduleId}/pages', [ModulePageController::class, 'store']);
            Route::get('/{moduleId}/pages/{pageId}', [ModulePageController::class, 'show']);
            Route::put('/{moduleId}/pages/{pageId}', [ModulePageController::class, 'update']);
            Route::delete('/{moduleId}/pages/{pageId}', [ModulePageController::class, 'destroy']);

            // Module Resources (nested)
            Route::get('/{moduleId}/resources', [ModuleResourceController::class, 'index']);
            Route::post('/{moduleId}/resources', [ModuleResourceController::class, 'store']);
            Route::get('/{moduleId}/resources/{resourceId}', [ModuleResourceController::class, 'show']);
            Route::put('/{moduleId}/resources/{resourceId}', [ModuleResourceController::class, 'update']);
            Route::delete('/{moduleId}/resources/{resourceId}', [ModuleResourceController::class, 'destroy']);

            // Tenant-specific module pages
          
        });

        // Subscription Plans Management
        Route::middleware(['auth:sanctum'])->prefix('subscription-plans')->group(function () {
            Route::get('/', [SubscriptionPlanController::class, 'index']);
            Route::get('/default', [SubscriptionPlanController::class, 'getDefaultPlan']);
            Route::get('/{id}', [SubscriptionPlanController::class, 'show']);
            Route::post('/', [SubscriptionPlanController::class, 'store']);
            Route::put('/{id}', [SubscriptionPlanController::class, 'update']);
            Route::delete('/{id}', [SubscriptionPlanController::class, 'destroy']);
            Route::get('/check-current-user-subscription', [SubscriptionPlanController::class, 'checkCurrentUserSubscription']);
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
        Route::post('login', [AuthController::class, 'login']);
        Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
        Route::post('change-password', [AuthController::class, 'changePassword'])->middleware('auth:sanctum');
        Route::post('verify-email', function () {
            if (! request()->hasValidSignature()) {
                abort(401);
            }
            $user = User::find(request('id'));
            if ($user && ! $user->hasVerifiedEmail()) {
                $user->markEmailAsVerified();
                event(new Verified($user));
            }
            return response()->json(['message' => 'Email verified successfully']);
        });
    });
}
