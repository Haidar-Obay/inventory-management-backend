<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Auth\Notifications\ResetPassword;
use OwenIt\Auditing\Models\Audit;
use App\Observers\AuditObserver;

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

        
        ResetPassword::createUrlUsing(function ($user, string $token) {
            // Replace this with your frontend URL
            return 'https://frontend-app.com/reset-password?token=' . $token . '&email=' . urlencode($user->email);
        });
       
    }
    }

