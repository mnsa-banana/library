<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daily nightly sequence runs at 1am PST (09:00 UTC) onward — after Google
// Books' midnight-Pacific quota reset, so book:enrich gets a fresh quota.
// 1. Streaming refresh pipeline (sync → enrich → verify-kids, fail-fast).
//    Overlap protection is a cache lock in StreamingUpdate::handle(), shared
//    with manual recovery runs.
Schedule::command('streaming:update')->dailyAt('09:00');

// Monthly: refresh streaming service catalog
Schedule::command('streaming:refresh-services')->monthlyOn(1, '02:00');

// Weekly (Thursdays): sync the current NYT children's bestseller lists into
// the book library. No-ops gracefully (exit 0) when NYT_BOOKS_API_KEY is unset.
Schedule::command('book:weekly')->weeklyOn(4, '09:00')->withoutOverlapping();

// Weekly (Thursdays, an hour after book:weekly so fresh rows are included):
// enrich pending library rows via Open Library + Google Books. Self-budgeting
// (900-call ceiling, quota-stop) and naturally resumable via enriched_at.
Schedule::command('book:enrich')->weeklyOn(4, '10:00')->withoutOverlapping();

// TEMPORARY backfill (added 2026-06-13; nyt-history seed retired 2026-06-17 once
// fully seeded). The library was seeded ~9% enriched; this daily enrich run
// resumes via enriched_at and self-budgets (~900 calls/run), at 10:00 UTC (~3h
// after Google Books' midnight-Pacific quota reset). appendOutputTo routes the
// summary to the container log. Remove once `book:status` shows fully enriched.
Schedule::command('book:enrich')->dailyAt('10:00')->withoutOverlapping()->appendOutputTo('/proc/1/fd/1');

// Daily ops health digest — emails the night's run summary at 11:00 UTC, after
// the latest nightly job (book:enrich, ~10:22) finishes. Reads the sync-log
// tables only. Watch-list lives in config/ops.php — keep that list in sync with
// the schedule entries above.
Schedule::command('ops:nightly-digest')->dailyAt('11:00');

// Monthly Netflix-Kids gap backstop: cross-reference TMDB watch/providers against
// our offer table and fill in Netflix offers MOTN missed (off-peak from the daily
// 09:00 pipeline; reads TMDB (free) + a bounded set of Kids searches).
Schedule::command('streaming:tmdb-backstop')->monthlyOn(1, '11:00')->withoutOverlapping();

// Weekly Netflix-Kids catalog browse: enumerate the live Kids catalog and fill in
// offers MOTN missed (off-peak; additive — never un-marks). Saturday 11:00 UTC.
Schedule::command('streaming:discover-netflix')->weeklyOn(6, '11:00')->withoutOverlapping();
