<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daily: full refresh pipeline — sync → enrich → verify-kids → push-availability (fail-fast)
Schedule::command('streaming:update')->dailyAt('03:00');

// Monthly: refresh streaming service catalog
Schedule::command('streaming:refresh-services')->monthlyOn(1, '02:00');
