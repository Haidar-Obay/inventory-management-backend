<?php

declare(strict_types=1);

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerGroupController;
use App\Http\Controllers\PaymentMethodController;
use App\Http\Controllers\ReferByController;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;
use App\Http\Controllers\CityController;
use App\Http\Controllers\CountryController;
use App\Http\Controllers\ProvinceController;
use App\Http\Controllers\CurrencyController;
use App\Http\Controllers\SalesmanController;
use App\Http\Controllers\UserManagementController;

/*
|--------------------------------------------------------------------------
| Tenant Routes
|--------------------------------------------------------------------------
|
| Here you can register the tenant routes for your application.
| These routes are loaded by the TenantRouteServiceProvider.
|
| Feel free to customize them however you want. Good luck!
|
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
            'role' => User::where('role', 'admin')->first()->name,
            'message' => tenant('name') . ' welcome to your tenant API!',
        ]);
    });
    Route::post('/login', [AuthController::class, 'login']);

    Route::apiResource('countries', CountryController::class);
    Route::apiResource('provinces', ProvinceController::class);
    Route::apiResource('currencies', CurrencyController::class);
    Route::apiResource('salesmen', SalesmanController::class);
    Route::apiResource('customer-groups', CustomerGroupController::class);
    Route::apiResource('customers', CustomerController::class);
    Route::apiResource('refer-bies', ReferByController::class);


    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/register', [UserManagementController::class, 'registerUser']);
        Route::post('/logout',  [AuthController::class,'logout']);
        Route::apiResource('cities', CityController::class);
        Route::apiResource('countries', CountryController::class);
        Route::apiResource('provinces', ProvinceController::class);
        Route::apiResource('currencies', CurrencyController::class);
        Route::apiResource('salesmen', SalesmanController::class);
        Route::apiResource('customer-groups', CustomerGroupController::class);
        Route::apiResource('customers', CustomerController::class);
        Route::apiResource('refer-bies', ReferByController::class);
        Route::apiResource('payment-methods', PaymentMethodController::class);
    });
});


