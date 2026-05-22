<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daily: incremental sync via /changes feed
Schedule::command('streaming:sync --hours=72')->dailyAt('03:00');

// Monthly: refresh streaming service catalog
Schedule::command('streaming:refresh-services')->monthlyOn(1, '02:00');

// Weekly: TMDB enrichment for new titles
Schedule::command('streaming:enrich')->weeklyOn(1, '04:00');
