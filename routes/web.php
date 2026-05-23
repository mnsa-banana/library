<?php

use App\Support\SpaShell;
use Illuminate\Support\Facades\Route;

Route::fallback(SpaShell::class);
