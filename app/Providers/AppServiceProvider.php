<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        RateLimiter::for('password.forgot', function (Request $request) {
            $email = strtolower((string) $request->input('email'));

            return [
                // Inbox-flood protection: don't allow >3/min to a single address.
                Limit::perMinute(3)->by("email:$email"),
                // Sustained-spam protection: cap each address at 20/hr, lenient enough
                // that legitimate users retrying every few minutes aren't locked out.
                Limit::perHour(20)->by("email-hour:$email"),
                // Per-IP cap stays.
                Limit::perHour(10)->by($request->ip()),
            ];
        });

        RateLimiter::for('password.reset', function (Request $request) {
            return [
                Limit::perHour(10)->by(strtolower((string) $request->input('email'))),
                Limit::perHour(20)->by($request->ip()),
            ];
        });

        RateLimiter::for('account.email', function (Request $request) {
            return Limit::perHour(5)->by(optional($request->user())->id ?: $request->ip());
        });
    }
}
