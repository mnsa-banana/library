<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind Railway's edge proxy — trust X-Forwarded-* so url()->current(),
        // https URL generation, and secure cookies work.
        $middleware->trustProxies(at: '*');

        $middleware->api(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);

        $middleware->alias([
            'subscribed' => \App\Http\Middleware\EnsureSubscribed::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // /api/* always gets a JSON error response (incl. a 401 for an
        // unauthenticated request) — never a redirect to the (nonexistent) login
        // route, which would 500.
        $exceptions->shouldRenderJsonWhen(
            fn ($request, $e) => $request->is('api/*') || $request->expectsJson()
        );
    })->create();
