<?php

use App\Http\Controllers\TenantController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

foreach (config('tenancy.central_domains') as $domain) {
    Route::domain($domain)->group(function () {
        Route::get('/', function () {
            return response()->json([
                'message' => 'This is your central application.',
            ]);
        });
        Route::post('/tenant', [TenantController::class, 'store']);
        Route::delete('/tenant/{id}', [TenantController::class, 'deleteTenant']);
    });
}
