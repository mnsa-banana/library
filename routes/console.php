<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daily: full refresh pipeline — sync → enrich → verify-kids → push-availability (fail-fast).
// Overlap protection lives in the command itself (cache lock in StreamingUpdate::handle()),
// so manual recovery runs and this schedule share the same guard.
Schedule::command('streaming:update')->dailyAt('03:00');

// Monthly: refresh streaming service catalog
Schedule::command('streaming:refresh-services')->monthlyOn(1, '02:00');

// Weekly (Thursdays): sync the current NYT children's bestseller lists into
// the book library. No-ops gracefully (exit 0) when NYT_BOOKS_API_KEY is unset.
Schedule::command('book:weekly')->weeklyOn(4, '09:00')->withoutOverlapping();

// Weekly (Thursdays, an hour after book:weekly so fresh rows are included):
// enrich pending library rows via Open Library + Google Books. Self-budgeting
// (900-call ceiling, quota-stop) and naturally resumable via enriched_at.
Schedule::command('book:enrich')->weeklyOn(4, '10:00')->withoutOverlapping();
