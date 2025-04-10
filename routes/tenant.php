<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\InitializeTenancyBySubdomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;
use App\Http\Controllers\CityController;
use App\Http\Controllers\CountryController;
use App\Http\Controllers\ProvinceController;



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
            'super_user' => User::where('role', 'super_user')->first()->name,
            'message' => tenant('name').' welcome to your tenant API!',
        ]);
    });
    Route::apiResource('cities', CityController::class);
    Route::apiResource('countries', CountryController::class);
    Route::apiResource('provinces', ProvinceController::class);


});

