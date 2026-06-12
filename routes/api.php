<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('verify.read-token')->group(function (): void {
    // Read API — grows with consumers. Single writer is the cron; this API is read-only, forever.
});
