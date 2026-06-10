<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daily: full refresh pipeline — sync → enrich → verify-kids → push-availability (fail-fast).
// withoutOverlapping guards against a hung run (upstream /changes latency is erratic) bleeding
// into the next day's run; the 12h lock expiry clears a stale lock from a killed process well
// before the next 03:00 slot.
Schedule::command('streaming:update')->dailyAt('03:00')->withoutOverlapping(60 * 12);

// Monthly: refresh streaming service catalog
Schedule::command('streaming:refresh-services')->monthlyOn(1, '02:00');
