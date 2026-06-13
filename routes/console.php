<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daily: full refresh pipeline — sync → enrich → verify-kids (fail-fast).
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

// TEMPORARY backfill (added 2026-06-13): the library was seeded ~9% enriched
// and the NYT history list isn't in yet. Run daily right after the 03:00
// streaming sync to chew through the backlog — book:seed resumes from its
// cursor, book:enrich resumes via enriched_at and self-budgets per run, so a
// few days of runs catch everything up. appendOutputTo routes the summary to
// the container log (Railway captures PID 1 stdout). Remove these two once
// `book:status` shows the library fully enriched and nyt-history seeded.
Schedule::command('book:seed --source=nyt-history --resume')->dailyAt('04:00')->withoutOverlapping()->appendOutputTo('/proc/1/fd/1');
Schedule::command('book:enrich')->dailyAt('04:30')->withoutOverlapping()->appendOutputTo('/proc/1/fd/1');
