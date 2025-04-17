<?php

declare(strict_types=1);

use App\Http\Controllers\CustomerAttachmentController;
use Illuminate\Support\Facades\Route;
use App\Models\User;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

// Controllers
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CityController;
use App\Http\Controllers\CountryController;
use App\Http\Controllers\ProvinceController;
use App\Http\Controllers\CurrencyController;
use App\Http\Controllers\SalesmanController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerGroupController;
use App\Http\Controllers\PaymentMethodController;
use App\Http\Controllers\ReferByController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\UserManagementController;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\Request;


/*
|--------------------------------------------------------------------------
| Tenant Routes
|--------------------------------------------------------------------------
| Routes loaded by TenantRouteServiceProvider, initialized per tenant.
| Customize freely.
|--------------------------------------------------------------------------
*/

Route::middleware([
    'api',
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
])->group(function () {

    Route::get('/', function () {
        return response()->json([
            'tenant_id' => tenant('id'),
            'tenant_domain' => tenant('domains')->first()->domain,
            'tenant_name' => tenant('name'),
            'tenant_email' => tenant('email'),
            'role' => User::where('role', 'admin')->first()->name ?? 'N/A',
            'message' => tenant('name') . ' welcome to your tenant API!',
        ]);
    });

    // Public Routes
    Route::post('/login', [AuthController::class, 'login']);



    // Protected Routes
    Route::middleware(['auth:sanctum', 'verified'])->group(function () {

        // Auth & User Management
        Route::post('/register', [UserManagementController::class, 'registerUser']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/get-all-users', action: [UserManagementController::class, 'getAllUsers']);
        Route::get('/get-user/{id}', action: [UserManagementController::class, 'getUser']);
        Route::delete('/delete-user', [UserManagementController::class, 'deleteUser']);
        Route::delete('/bulk-delete-users', [UserManagementController::class, 'bulkDeleteUsers']);

        // Resource APIs
        Route::apiResource('cities', CityController::class);
        Route::apiResource('countries', CountryController::class);
        Route::apiResource('provinces', ProvinceController::class);
        Route::apiResource('currencies', CurrencyController::class);
        Route::apiResource('salesmen', SalesmanController::class);
        Route::apiResource('customers', CustomerController::class);
        Route::apiResource('customer-groups', CustomerGroupController::class);
        // Route::apiResource('customer-attachments', CustomerAttachmentController::class);
        Route::apiResource('payment-methods', PaymentMethodController::class);
        Route::apiResource('refer-bies', ReferByController::class);

        // Export to Excel Routes
        // Note: The export routes are prefixed with 'exportExcell' to avoid confusion with the import routes.
        Route::prefix('exportExcell')->group(function () {
            Route::get('customers', [CustomerController::class, 'exportExcell']);
            Route::get('cities', [CityController::class, 'exportExcell']);
            Route::get('countries', [CountryController::class, 'exportExcell']);
            Route::get('provinces', [ProvinceController::class, 'exportExcell']);
            Route::get('currencies', [CurrencyController::class, 'exportExcell']);
            Route::get('customer-groups', [CustomerGroupController::class, 'exportExcell']);
            Route::get('payment-methods', [PaymentMethodController::class, 'exportExcell']);
            Route::get('salesmen', [SalesmanController::class, 'exportExcell']);
            Route::get('refer-bies', [ReferByController::class, 'exportExcell']);
        });

        // Export to PDF Routes
        // Note: The export routes are prefixed with 'exportPdf' to avoid confusion with the import routes.
        Route::prefix('exportPdf')->group(function () {
            Route::get('/customers', [CustomerController::class, 'exportPdf']);
            Route::get('/cities', [CityController::class, 'exportPdf']);
            Route::get('/countries', [CountryController::class, 'exportPdf']);
            Route::get('/provinces', [ProvinceController::class, 'exportPdf']);
            Route::get('/currencies', [CurrencyController::class, 'exportPdf']);
            Route::get('/customer-groups', [CustomerGroupController::class, 'exportPdf']);
            Route::get('/payment-methods', [CustomerGroupController::class, 'exportPdf']);
            Route::get('/salesmen', [SalesmanController::class, 'exportPdf']);
            Route::get('/refer-bies', [ReferByController::class, 'exportPdf']);
        });

        // Import from Excel Routes
        // Note: The import routes are prefixed with 'importFromExcel' to avoid confusion with the export routes.
        Route::prefix('importFromExcel')->group(function () {
            Route::post('/customers', [CustomerController::class, 'importFromExcel']);
            Route::post('/cities', [CityController::class, 'importFromExcel']);
            Route::post('/countries', [CountryController::class, 'importFromExcel']);
            Route::post('/provinces', [ProvinceController::class, 'importFromExcel']);
            Route::post('/currencies', [CurrencyController::class, 'importFromExcel']);
            Route::post('/customer-groups', [CustomerGroupController::class, 'importFromExcel']);
            Route::post('/payment-methods', [PaymentMethodController::class, 'importFromExcel']);
            Route::post('/salesmen', [SalesmanController::class, 'importFromExcel']);
            Route::post('/refer-bies', [ReferByController::class, 'importFromExcel']);
        });

        // Bulk Delete Routes
        // Note: The bulk delete routes are prefixed with 'bulk-delete' to avoid confusion with the import/export routes.
        Route::prefix('bulk-delete')->group(function () {
            Route::delete('/customers', [CustomerController::class, 'bulkDelete']);
            Route::delete('/cities', [CityController::class, 'bulkDelete']);
            Route::delete('/countries', [CountryController::class, 'bulkDelete']);
            Route::delete('/provinces', [ProvinceController::class, 'bulkDelete']);
            Route::delete('/currencies', [CurrencyController::class, 'bulkDelete']);
            Route::delete('/customer-groups', [CustomerGroupController::class, 'bulkDelete']);
            Route::delete('/salesmen', [SalesmanController::class, 'bulkDelete']);
            Route::delete('/payment-methods', [PaymentMethodController::class, 'bulkDelete']);
            Route::delete('/refer-bies', [ReferByController::class, 'bulkDelete']);
        });
        
        //Email Verification Routes
        Route::get('/email/verify/{id}/{hash}', function (EmailVerificationRequest $request) {
            $request->fulfill();
            return response()->json(['message' => 'Email verified!']);
        })->middleware(['auth:sanctum', 'signed'])->name('verification.verify');

        Route::post('/email/verification-notification', function (Request $request) {
            $request->user()->sendEmailVerificationNotification();
            return response()->json(['message' => 'Verification email resent']);
        })->middleware(['auth:sanctum', 'throttle:6,1'])->name('verification.send');
    });
});
