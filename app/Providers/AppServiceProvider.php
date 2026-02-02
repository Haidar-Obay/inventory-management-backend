<?php

namespace App\Providers;

use App\Models\PaymentTerm;
use App\Models\Warehouse;
use App\Observers\AuditObserver;
use App\Observers\PaymentTermObserver;
use App\Observers\WarehouseObserver;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;
use OwenIt\Auditing\Models\Audit;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Audit::observe(AuditObserver::class);
        PaymentTerm::observe(PaymentTermObserver::class);
        Warehouse::observe(WarehouseObserver::class);

        ResetPassword::createUrlUsing(function ($user, string $token) {
            // Replace this with your frontend URL
            return 'https://frontend-app.com/reset-password?token='.$token.'&email='.urlencode($user->email);
        });

        // Removed global ID assignment logic; IDs are assigned explicitly in controllers.
    }
}
