<?php

namespace Tests\Unit\Services\Ops;

use App\Models\BookLibraryTitle;
use App\Services\Ops\HealthReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HealthReportTest extends TestCase
{
    use RefreshDatabase;

    /** The three daily watch entries, isolated from the real (now 5-entry) config. */
    private const DAILY_WATCH = [
        ['key' => 'streaming', 'table' => 'streaming_sync_log', 'type' => 'pipeline', 'label' => 'Streaming pipeline', 'cadence' => 'daily'],
        ['key' => 'verify_kids', 'table' => 'streaming_sync_log', 'type' => 'verify_kids', 'label' => 'Netflix Kids verify', 'cadence' => 'daily'],
        ['key' => 'book_enrich', 'table' => 'book_sync_log', 'type' => 'enrich', 'label' => 'Book enrich', 'cadence' => 'daily'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-16 11:00:00');

        // Pin the staleness map so tests don't depend on the real config values.
        config([
            'ops.digest.pipeline_stale_hours' => 26,
            'ops.digest.cadence_stale_hours' => [
                'daily' => 26,
                'weekly' => 192,
                'monthly' => 768,
            ],
            // Default most tests to the three daily jobs; cadence tests override this.
            'ops.watch' => self::DAILY_WATCH,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function log(string $table, string $type, string $status, ?string $completedAt, array $extra = []): void
    {
        DB::table($table)->insert(array_merge([
            'sync_type' => $type,
            'started_at' => $completedAt ? Carbon::parse($completedAt)->subMinutes(10) : now()->subMinutes(10),
            'completed_at' => $completedAt,
            'status' => $status,
        ], $extra));
    }

    private function jobs(HealthReport $r): array
    {
        $out = [];
        foreach ($r->jobs as $j) {
            $out[$j->key] = $j->verdict;
        }

        return $out;
    }

    public function test_all_completed_today_is_overall_ok(): void
    {
        $this->log('streaming_sync_log', 'pipeline', 'completed', '2026-06-16 09:29:00', ['started_at' => '2026-06-16 09:00:00', 'titles_processed' => 4653, 'api_calls_used' => 272]);
        $this->log('streaming_sync_log', 'verify_kids', 'completed', '2026-06-16 09:14:00', ['metadata' => json_encode(['candidates' => 600, 'surfaced' => 9, 'pruned' => 38])]);
        $this->log('book_sync_log', 'enrich', 'completed', '2026-06-16 10:22:00', ['titles_processed' => 463]);

        $report = HealthReport::build();

        $this->assertSame('ok', $report->overall);
        $this->assertSame(['ok', 'ok', 'ok'], array_values($this->jobs($report)));
    }

    public function test_failed_pipeline_is_fail_and_drives_overall(): void
    {
        $this->log('streaming_sync_log', 'pipeline', 'failed', '2026-06-16 09:14:00', ['error_message' => 'cookie stale']);
        $this->log('streaming_sync_log', 'verify_kids', 'failed', '2026-06-16 09:14:00', ['error_message' => 'session gate aborted']);
        $this->log('book_sync_log', 'enrich', 'completed', '2026-06-16 10:22:00');

        $report = HealthReport::build();

        $this->assertSame('fail', $report->overall);
        $this->assertSame('fail', $this->jobs($report)['streaming']);
        $this->assertSame('fail', $this->jobs($report)['verify_kids']);
    }

    public function test_stale_run_older_than_window_is_fail(): void
    {
        // Most recent pipeline completed 30h ago → past the 26h window.
        $this->log('streaming_sync_log', 'pipeline', 'completed', '2026-06-15 05:00:00');
        $this->log('streaming_sync_log', 'verify_kids', 'completed', '2026-06-16 09:14:00');
        $this->log('book_sync_log', 'enrich', 'completed', '2026-06-16 10:22:00');

        $this->assertSame('fail', $this->jobs(HealthReport::build())['streaming']);
    }

    public function test_running_today_is_incomplete_warn(): void
    {
        $this->log('streaming_sync_log', 'pipeline', 'completed', '2026-06-16 09:29:00', ['started_at' => '2026-06-16 09:00:00']);
        $this->log('streaming_sync_log', 'verify_kids', 'completed', '2026-06-16 09:14:00');
        // enrich still running (no completed_at), started today.
        $this->log('book_sync_log', 'enrich', 'running', null);

        $report = HealthReport::build();
        $this->assertSame('warn', $this->jobs($report)['book_enrich']);
        $this->assertSame('warn', $report->overall);
    }

    public function test_never_run_job_is_fail_missing(): void
    {
        $this->log('streaming_sync_log', 'pipeline', 'completed', '2026-06-16 09:29:00', ['started_at' => '2026-06-16 09:00:00']);
        $this->log('streaming_sync_log', 'verify_kids', 'completed', '2026-06-16 09:14:00');
        // no enrich row at all

        $this->assertSame('fail', $this->jobs(HealthReport::build())['book_enrich']);
    }

    public function test_book_enrich_shows_remaining_and_eta_when_backlog_left(): void
    {
        // 10 titles, 3 enriched → 7 remaining; tonight processed 2 → ETA ~4 more nights
        BookLibraryTitle::create(['title' => 'Book A', 'enriched_at' => now()->subDay()]);
        BookLibraryTitle::create(['title' => 'Book B', 'enriched_at' => now()->subDay()]);
        BookLibraryTitle::create(['title' => 'Book C', 'enriched_at' => now()->subDay()]);
        for ($i = 1; $i <= 7; $i++) {
            BookLibraryTitle::create(['title' => "Unenriched {$i}"]);
        }

        $this->log('book_sync_log', 'enrich', 'completed', '2026-06-16 10:22:00', ['titles_processed' => 2]);

        $report = HealthReport::build();
        $job = collect($report->jobs)->firstWhere('key', 'book_enrich');

        $this->assertSame('ok', $job->verdict);
        $this->assertStringContainsString('7 remaining', $job->summary);
        $this->assertStringContainsString('more nights', $job->summary);
    }

    public function test_book_enrich_shows_backfill_complete_when_drained(): void
    {
        // 5 titles, all enriched → 0 remaining
        for ($i = 1; $i <= 5; $i++) {
            BookLibraryTitle::create(['title' => "Book {$i}", 'enriched_at' => now()->subDay()]);
        }

        $this->log('book_sync_log', 'enrich', 'completed', '2026-06-16 10:22:00', ['titles_processed' => 5]);

        $report = HealthReport::build();
        $job = collect($report->jobs)->firstWhere('key', 'book_enrich');

        $this->assertSame('ok', $job->verdict);
        $this->assertStringContainsString('BACKFILL COMPLETE', $job->summary);
    }

    public function test_weekly_job_completed_3_days_ago_is_ok(): void
    {
        config(['ops.watch' => [
            ['key' => 'discover_netflix', 'table' => 'streaming_sync_log', 'type' => 'discover_netflix', 'label' => 'Netflix Kids discover', 'cadence' => 'weekly'],
        ]]);

        // Completed 3 days ago — well inside the 192h (8-day) weekly window.
        $this->log('streaming_sync_log', 'discover_netflix', 'completed', '2026-06-13 11:05:00', [
            'started_at' => '2026-06-13 11:00:00',
            'metadata' => json_encode([
                'offers_created' => 12, 'offers_restamped' => 30,
                'motn_owned_skipped' => 4, 'unmatched_count' => 7,
            ]),
        ]);

        $job = collect(HealthReport::build()->jobs)->firstWhere('key', 'discover_netflix');
        $this->assertSame('ok', $job->verdict);
        $this->assertStringContainsString('created 12', $job->summary);
        $this->assertStringContainsString('restamped 30', $job->summary);
        $this->assertStringContainsString('skipped(MOTN) 4', $job->summary);
        $this->assertStringContainsString('unmatched 7', $job->summary);
    }

    public function test_weekly_job_completed_10_days_ago_is_stale_fail(): void
    {
        config(['ops.watch' => [
            ['key' => 'discover_netflix', 'table' => 'streaming_sync_log', 'type' => 'discover_netflix', 'label' => 'Netflix Kids discover', 'cadence' => 'weekly'],
        ]]);

        // 10 days ago → past the 192h (8-day) weekly window.
        $this->log('streaming_sync_log', 'discover_netflix', 'completed', '2026-06-06 11:00:00', [
            'metadata' => json_encode(['offers_created' => 1]),
        ]);

        $job = collect(HealthReport::build()->jobs)->firstWhere('key', 'discover_netflix');
        $this->assertSame('fail', $job->verdict);
    }

    public function test_monthly_job_with_no_runs_is_warn_not_fail(): void
    {
        config(['ops.watch' => [
            ['key' => 'tmdb_backstop', 'table' => 'streaming_sync_log', 'type' => 'tmdb_backstop', 'label' => 'TMDB backstop', 'cadence' => 'monthly'],
        ]]);
        // no tmdb_backstop rows at all

        $report = HealthReport::build();
        $job = collect($report->jobs)->firstWhere('key', 'tmdb_backstop');

        $this->assertSame('warn', $job->verdict);
        $this->assertSame('no runs yet', $job->summary);
        // A brand-new periodic job that hasn't run yet must not drag overall to fail.
        $this->assertNotSame('fail', $report->overall);
    }

    public function test_failed_periodic_run_is_fail(): void
    {
        config(['ops.watch' => [
            ['key' => 'tmdb_backstop', 'table' => 'streaming_sync_log', 'type' => 'tmdb_backstop', 'label' => 'TMDB backstop', 'cadence' => 'monthly'],
        ]]);

        $this->log('streaming_sync_log', 'tmdb_backstop', 'failed', '2026-06-16 09:01:00', [
            'started_at' => '2026-06-16 09:00:00', 'error_message' => 'invalid US Kids session',
        ]);

        $report = HealthReport::build();
        $job = collect($report->jobs)->firstWhere('key', 'tmdb_backstop');

        $this->assertSame('fail', $job->verdict);
        $this->assertStringContainsString('invalid US Kids session', $job->summary);
        $this->assertSame('fail', $report->overall);
    }

    public function test_daily_job_behavior_unchanged(): void
    {
        config(['ops.watch' => [
            ['key' => 'streaming', 'table' => 'streaming_sync_log', 'type' => 'pipeline', 'label' => 'Streaming pipeline', 'cadence' => 'daily'],
        ]]);

        // A daily pipeline that completed 2h ago — within the 26h daily window.
        $this->log('streaming_sync_log', 'pipeline', 'completed', '2026-06-16 09:00:00', ['started_at' => '2026-06-16 08:30:00']);

        $report = HealthReport::build();
        $job = collect($report->jobs)->firstWhere('key', 'streaming');

        $this->assertSame('ok', $job->verdict);
        $this->assertSame('ok', $report->overall);
    }

    public function test_verify_kids_warns_when_skipped_in_latest_pipeline(): void
    {
        // Tonight's pipeline failed early (sync) so verify-kids never ran; the latest
        // verify_kids row is yesterday's and would otherwise look fresh (<26h).
        $this->log('streaming_sync_log', 'pipeline', 'failed', '2026-06-16 09:05:00', ['started_at' => '2026-06-16 09:00:00', 'error_message' => 'sync failed']);
        $this->log('streaming_sync_log', 'verify_kids', 'completed', '2026-06-15 09:14:00');
        $this->log('book_sync_log', 'enrich', 'completed', '2026-06-16 10:22:00');

        $jobs = $this->jobs(HealthReport::build());
        $this->assertSame('fail', $jobs['streaming']);
        $this->assertSame('warn', $jobs['verify_kids']);
    }

    public function test_streaming_summary_pulls_counts_from_changes_row(): void
    {
        $this->log('streaming_sync_log', 'pipeline', 'completed', '2026-06-16 09:29:00', ['started_at' => '2026-06-16 09:00:00']);
        $this->log('streaming_sync_log', 'changes', 'completed', '2026-06-16 09:13:00', ['titles_processed' => 4653, 'api_calls_used' => 272]);
        $this->log('streaming_sync_log', 'verify_kids', 'completed', '2026-06-16 09:14:00');

        $job = collect(HealthReport::build()->jobs)->firstWhere('key', 'streaming');
        $this->assertSame('ok', $job->verdict);
        $this->assertStringContainsString('4653 titles', $job->summary);
        $this->assertStringContainsString('272 calls', $job->summary);
    }
}
