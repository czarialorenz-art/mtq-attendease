<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

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
        // Production (InfinityFree) is served over HTTPS; phone cameras only work on HTTPS pages
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Sign-in and sign-up: 10 a minute per account, so password guessing is slow,
        // but a whole class signing in from the same school Wi-Fi (one IP) is not blocked.
        RateLimiter::for('auth', function (Request $request) {
            $email = strtolower(trim((string) $request->input('email', optional($request->user())->email)));

            return [
                Limit::perMinute(10)->by('auth:' . $email . '|' . $request->ip()),
                Limit::perMinute(120)->by('auth-ip:' . $request->ip()),
            ];
        });
    }
}
