<?php

use App\Http\Controllers\LandingController;
use App\Support\SpaShell;
use Illuminate\Support\Facades\Route;

Route::get('/', LandingController::class);

// SpaShell::class (invokable) rather than a closure — closure routes break `php artisan route:cache`.
Route::fallback(SpaShell::class);
