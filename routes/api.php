<?php

declare(strict_types=1);

use App\Http\Controllers\AuditController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Central\AvailableCurrencyController;
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
use Illuminate\Support\Facades\Route;

/*
||--------------------------------------------------------------------------
|| Sanctum‐protected "current user" endpoint
||--------------------------------------------------------------------------
*/

// Public health check (used by tests and uptime monitors)
Route::get('/health', fn () => response()->json(['status' => 'ok']))->middleware('api');

Route::get('/user', fn (Request $request) => $request->user())
    ->middleware('auth:sanctum');

/*
||--------------------------------------------------------------------------
|| Central‐domain tenant management
||--------------------------------------------------------------------------
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
            Route::get('all', [TenantController::class, 'getAllTenants']);
            Route::get('', [TenantController::class, 'getAllTenants']);
            Route::delete('bulk-delete-tenants', [TenantController::class, 'bulkDeleteTenants']);
            Route::get('export/excell', [TenantController::class, 'exportExcell']);
            Route::get('/exportPdf', [TenantController::class, 'exportPdf']);
            Route::get('delete/{id}', [TenantController::class, 'deleteTenant']);
            Route::get('{id}', [TenantController::class, 'getTenant']);
            Route::delete('{id}', [TenantController::class, 'deleteTenant']);
            Route::put('{id}', [TenantController::class, 'updateTenant']);
        });

        // Modules Management
        Route::middleware(['auth:sanctum'])->prefix('modules')->group(function () {
            Route::get('/', [ModuleController::class, 'index']);
            Route::get('/usage-stats', [ModuleController::class, 'getUsageStats']);
            Route::post('/', [ModuleController::class, 'store']);

            // Module Pages (nested - must come before /{id})
            Route::get('/{moduleId}/pages', [ModulePageController::class, 'index']);
            Route::post('/{moduleId}/pages', [ModulePageController::class, 'store']);
            Route::get('/{moduleId}/pages/{pageId}', [ModulePageController::class, 'show']);
            Route::put('/{moduleId}/pages/{pageId}', [ModulePageController::class, 'update']);
            Route::delete('/{moduleId}/pages/{pageId}', [ModulePageController::class, 'destroy']);

            // Module Resources (nested - must come before /{id})
            Route::get('/{moduleId}/resources', [ModuleResourceController::class, 'index']);
            Route::post('/{moduleId}/resources', [ModuleResourceController::class, 'store']);
            Route::get('/{moduleId}/resources/{resourceId}', [ModuleResourceController::class, 'show']);
            Route::put('/{moduleId}/resources/{resourceId}', [ModuleResourceController::class, 'update']);
            Route::delete('/{moduleId}/resources/{resourceId}', [ModuleResourceController::class, 'destroy']);

            // CRUD operations (must come after specific routes)
            Route::get('/{id}', [ModuleController::class, 'show']);
            Route::put('/{id}', [ModuleController::class, 'update']);
            Route::delete('/{id}', [ModuleController::class, 'destroy']);

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

        // Available Currencies Management
        Route::middleware(['auth:sanctum'])->prefix('available-currencies')->group(function () {
            Route::get('/', [AvailableCurrencyController::class, 'index']);
            Route::get('/{id}', [AvailableCurrencyController::class, 'show']);
            Route::post('/', [AvailableCurrencyController::class, 'store']);
            Route::put('/{id}', [AvailableCurrencyController::class, 'update']);
            Route::delete('/{id}', [AvailableCurrencyController::class, 'destroy']);
            Route::patch('/{id}/toggle-active', [AvailableCurrencyController::class, 'toggleActive']);
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

        // User Management (protected routes)
        Route::middleware(['auth:sanctum'])->group(function () {
            Route::get('get-all-users', [UserManagementController::class, 'getAllUsersForCentral']);
            Route::get('get-user/{id}', [UserManagementController::class, 'getCentralUser']);
            Route::post('register', [UserManagementController::class, 'registerUserForCentral']);
            Route::put('update-user/{id}', [UserManagementController::class, 'updateCentralUser']);
            Route::delete('delete-user/{id}', [UserManagementController::class, 'deleteCentralUser']);
            Route::delete('bulk-delete-users', [UserManagementController::class, 'bulkDeleteCentralUsers']);
        });
    });
}
