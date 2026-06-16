<?php

namespace Tests\Unit\Services\Ops;

use App\Services\Ops\HealthReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HealthReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-16 11:00:00');
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
        $this->log('book_sync_log', 'seed_nyt_history', 'completed', '2026-06-16 09:30:00', ['titles_processed' => 10]);

        $report = HealthReport::build();

        $this->assertSame('ok', $report->overall);
        $this->assertSame(['ok', 'ok', 'ok', 'ok'], array_values($this->jobs($report)));
    }

    public function test_failed_pipeline_is_fail_and_drives_overall(): void
    {
        $this->log('streaming_sync_log', 'pipeline', 'failed', '2026-06-16 09:14:00', ['error_message' => 'cookie stale']);
        $this->log('streaming_sync_log', 'verify_kids', 'failed', '2026-06-16 09:14:00', ['error_message' => 'session gate aborted']);
        $this->log('book_sync_log', 'enrich', 'completed', '2026-06-16 10:22:00');
        $this->log('book_sync_log', 'seed_nyt_history', 'completed', '2026-06-16 09:30:00');

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
        $this->log('book_sync_log', 'seed_nyt_history', 'completed', '2026-06-16 09:30:00');

        $this->assertSame('fail', $this->jobs(HealthReport::build())['streaming']);
    }

    public function test_running_today_is_incomplete_warn(): void
    {
        $this->log('streaming_sync_log', 'pipeline', 'completed', '2026-06-16 09:29:00', ['started_at' => '2026-06-16 09:00:00']);
        $this->log('streaming_sync_log', 'verify_kids', 'completed', '2026-06-16 09:14:00');
        $this->log('book_sync_log', 'enrich', 'completed', '2026-06-16 10:22:00');
        // seed still running (no completed_at), started today.
        $this->log('book_sync_log', 'seed_nyt_history', 'running', null);

        $report = HealthReport::build();
        $this->assertSame('warn', $this->jobs($report)['book_seed']);
        $this->assertSame('warn', $report->overall);
    }

    public function test_never_run_job_is_fail_missing(): void
    {
        $this->log('streaming_sync_log', 'pipeline', 'completed', '2026-06-16 09:29:00', ['started_at' => '2026-06-16 09:00:00']);
        $this->log('streaming_sync_log', 'verify_kids', 'completed', '2026-06-16 09:14:00');
        $this->log('book_sync_log', 'enrich', 'completed', '2026-06-16 10:22:00');
        // no seed row at all

        $this->assertSame('fail', $this->jobs(HealthReport::build())['book_seed']);
    }

    public function test_book_enrich_shows_remaining_and_eta_when_backlog_left(): void
    {
        // 10 titles, 3 enriched → 7 remaining; tonight processed 2 → ETA ~4 more nights
        \App\Models\BookLibraryTitle::create(['title' => 'Book A', 'enriched_at' => now()->subDay()]);
        \App\Models\BookLibraryTitle::create(['title' => 'Book B', 'enriched_at' => now()->subDay()]);
        \App\Models\BookLibraryTitle::create(['title' => 'Book C', 'enriched_at' => now()->subDay()]);
        for ($i = 1; $i <= 7; $i++) {
            \App\Models\BookLibraryTitle::create(['title' => "Unenriched {$i}"]);
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
            \App\Models\BookLibraryTitle::create(['title' => "Book {$i}", 'enriched_at' => now()->subDay()]);
        }

        $this->log('book_sync_log', 'enrich', 'completed', '2026-06-16 10:22:00', ['titles_processed' => 5]);

        $report = HealthReport::build();
        $job = collect($report->jobs)->firstWhere('key', 'book_enrich');

        $this->assertSame('ok', $job->verdict);
        $this->assertStringContainsString('BACKFILL COMPLETE', $job->summary);
    }

    public function test_book_seed_shows_exhausted_when_nothing_added(): void
    {
        $this->log('book_sync_log', 'seed_nyt_history', 'completed', '2026-06-16 09:30:00', ['titles_processed' => 0]);

        $report = HealthReport::build();
        $job = collect($report->jobs)->firstWhere('key', 'book_seed');

        $this->assertSame('ok', $job->verdict);
        $this->assertStringContainsString('exhausted', $job->summary);
    }

    public function test_non_daily_cadence_degrades_to_warn_without_throwing(): void
    {
        config(['ops.watch' => [
            ['key' => 'streaming', 'table' => 'streaming_sync_log', 'type' => 'pipeline', 'label' => 'Streaming pipeline', 'cadence' => 'daily'],
            ['key' => 'book_weekly', 'table' => 'book_sync_log', 'type' => 'weekly', 'label' => 'Book weekly', 'cadence' => 'weekly:thu'],
        ]]);
        $this->log('streaming_sync_log', 'pipeline', 'completed', '2026-06-16 09:29:00', ['started_at' => '2026-06-16 09:00:00']);

        $jobs = $this->jobs(HealthReport::build());
        $this->assertSame('warn', $jobs['book_weekly']);
        $this->assertSame('ok', $jobs['streaming']); // the daily job is still assessed normally
    }

    public function test_verify_kids_warns_when_skipped_in_latest_pipeline(): void
    {
        // Tonight's pipeline failed early (sync) so verify-kids never ran; the latest
        // verify_kids row is yesterday's and would otherwise look fresh (<26h).
        $this->log('streaming_sync_log', 'pipeline', 'failed', '2026-06-16 09:05:00', ['started_at' => '2026-06-16 09:00:00', 'error_message' => 'sync failed']);
        $this->log('streaming_sync_log', 'verify_kids', 'completed', '2026-06-15 09:14:00');
        $this->log('book_sync_log', 'enrich', 'completed', '2026-06-16 10:22:00');
        $this->log('book_sync_log', 'seed_nyt_history', 'completed', '2026-06-16 09:30:00');

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
