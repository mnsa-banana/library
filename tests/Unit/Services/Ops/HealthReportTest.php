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
        $this->log('streaming_sync_log', 'pipeline', 'completed', '2026-06-16 09:29:00', ['titles_processed' => 4653, 'api_calls_used' => 272]);
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
        $this->log('streaming_sync_log', 'pipeline', 'completed', '2026-06-16 09:29:00');
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
        $this->log('streaming_sync_log', 'pipeline', 'completed', '2026-06-16 09:29:00');
        $this->log('streaming_sync_log', 'verify_kids', 'completed', '2026-06-16 09:14:00');
        $this->log('book_sync_log', 'enrich', 'completed', '2026-06-16 10:22:00');
        // no seed row at all

        $this->assertSame('fail', $this->jobs(HealthReport::build())['book_seed']);
    }
}
