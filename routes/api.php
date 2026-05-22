<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BillingController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\RestrictionController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Public
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/password/forgot', [\App\Http\Controllers\Api\V1\PasswordResetController::class, 'forgot'])
        ->middleware('throttle:password.forgot');
    Route::post('/password/reset', [\App\Http\Controllers\Api\V1\PasswordResetController::class, 'reset'])
        ->middleware('throttle:password.reset');
    Route::post('/account/email/confirm', [\App\Http\Controllers\Api\V1\AccountController::class, 'confirmEmailChange']);

    // Authenticated
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);

        // Billing (auth required, no subscription required)
        Route::get('/billing/status', [BillingController::class, 'status']);
        Route::get('/billing/checkout-url', [BillingController::class, 'checkoutUrl']);
        Route::get('/billing/manage-url', [BillingController::class, 'manageUrl']);

        // Account
        Route::post('/account/password', [\App\Http\Controllers\Api\V1\AccountController::class, 'updatePassword']);
        Route::post('/account/email', [\App\Http\Controllers\Api\V1\AccountController::class, 'requestEmailChange'])
            ->middleware('throttle:account.email');
        Route::delete('/account', [\App\Http\Controllers\Api\V1\AccountController::class, 'destroy']);

        // Data endpoints (subscription required)
        // TODO: Re-enable subscribed middleware when done testing
        // Route::middleware('subscribed')->group(function () {
            Route::get('/reports', [ReportController::class, 'index']);
            Route::get('/reports/{id}', [ReportController::class, 'show']);
            Route::get('/reports/{id}/streaming', [ReportController::class, 'streaming']);

            Route::get('/restrictions', [RestrictionController::class, 'index']);
        // });
    });
});
