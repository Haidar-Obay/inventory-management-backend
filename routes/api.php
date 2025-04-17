<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\TenantController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Auth\EmailVerificationRequest;

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

        Route::prefix('tenant')->group(function () {
            Route::post('', [TenantController::class, 'store']);
            Route::delete('/{id}', [TenantController::class, 'deleteTenant']);
            Route::get('/all', [TenantController::class, 'getAllTenants']);
            Route::get('/{id}', [TenantController::class, 'getTenant']);
            Route::put('/{id}', [TenantController::class, 'updateTenant']);
            Route::get('/export/excell', [TenantController::class, 'exportExcell']);
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
