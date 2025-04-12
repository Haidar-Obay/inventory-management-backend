<?php

declare(strict_types=1);

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
    Route::middleware('auth:sanctum')->group(function () {

        // Auth & User Management
        Route::post('/register', [UserManagementController::class, 'registerUser']);
        Route::post('/logout', [AuthController::class, 'logout']);

        // Resource APIs
        Route::apiResource('cities', CityController::class);
        Route::apiResource('countries', CountryController::class);
        Route::apiResource('provinces', ProvinceController::class);
        Route::apiResource('currencies', CurrencyController::class);
        Route::apiResource('salesmen', SalesmanController::class);
        Route::apiResource('customers', CustomerController::class);
        Route::apiResource('customer-groups', CustomerGroupController::class);
        Route::apiResource('payment-methods', PaymentMethodController::class);
        Route::apiResource('refer-bies', ReferByController::class);

        // Export to Excel Routes
        Route::prefix('export')->group(function () {
            Route::get('customers', [CustomerController::class, 'export']);
            Route::get('cities', [CityController::class, 'export']);
            Route::get('countries', [CountryController::class, 'export']);
            Route::get('provinces', [ProvinceController::class, 'export']);
            Route::get('currencies', [CurrencyController::class, 'export']);
            Route::get('customer-groups', [CustomerGroupController::class, 'export']);
            Route::get('payment-methods', [PaymentMethodController::class, 'export']);
            Route::get('salesmen', [SalesmanController::class, 'export']);
            Route::get('refer-bies', [ReferByController::class, 'export']);
        });

        // Export to PDF Routes
        Route::prefix('export')->group(function () {
            Route::get('/customers/export-pdf', [CustomerController::class, 'exportPdf']);
            Route::get('/cities/export-pdf', [CityController::class, 'exportPdf']);
            Route::get('/countries/export-pdf', [CountryController::class, 'exportPdf']);
            Route::get('/provinces/export-pdf', [ProvinceController::class, 'exportPdf']);
            Route::get('/currencies/export-pdf', [CurrencyController::class, 'exportPdf']);
            Route::get('/customer-groups/export-pdf', [CustomerGroupController::class, 'exportPdf']);
            Route::get('/payment-methods/export-pdf', [CustomerGroupController::class, 'exportPdf']);
            Route::get('/salesmen/export-pdf', [SalesmanController::class, 'exportPdf']);
            Route::get('/refer-bies/export-pdf', [ReferByController::class, 'exportPdf']);
        });
    });
});
