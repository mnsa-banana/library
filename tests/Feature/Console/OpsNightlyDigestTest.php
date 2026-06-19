<?php

namespace Tests\Feature\Console;

use App\Mail\NightlyDigest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OpsNightlyDigestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-16 11:00:00');
        // This test exercises the daily-digest happy path; pin the watch list to the
        // three daily jobs so the newer weekly/monthly entries (which have no log rows
        // here, and would correctly read 'no runs yet'→warn) don't make it 'incomplete'.
        config(['ops.watch' => [
            ['key' => 'streaming', 'table' => 'streaming_sync_log', 'type' => 'pipeline', 'label' => 'Streaming pipeline', 'cadence' => 'daily'],
            ['key' => 'verify_kids', 'table' => 'streaming_sync_log', 'type' => 'verify_kids', 'label' => 'Netflix Kids verify', 'cadence' => 'daily'],
            ['key' => 'book_enrich', 'table' => 'book_sync_log', 'type' => 'enrich', 'label' => 'Book enrich', 'cadence' => 'daily'],
        ]]);
        // Pipeline started at 09:00 so it predates verify_kids completion at 09:14 — realistic order.
        DB::table('streaming_sync_log')->insert([
            'sync_type' => 'pipeline', 'started_at' => '2026-06-16 09:00:00',
            'completed_at' => '2026-06-16 09:29:00', 'status' => 'completed',
        ]);
        foreach ([
            ['streaming_sync_log', 'verify_kids', '2026-06-16 09:14:00'],
            ['book_sync_log', 'enrich', '2026-06-16 10:22:00'],
        ] as [$table, $type, $done]) {
            DB::table($table)->insert([
                'sync_type' => $type,
                'started_at' => Carbon::parse($done)->subMinutes(5),
                'completed_at' => $done,
                'status' => 'completed',
            ]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_sends_digest_to_configured_address(): void
    {
        Mail::fake();
        config(['ops.digest.to' => 'ops@example.com']);

        $this->artisan('ops:nightly-digest')->assertExitCode(0);

        Mail::assertSent(NightlyDigest::class, function (NightlyDigest $m) {
            return $m->hasTo('ops@example.com')
                && str_contains($m->envelope()->subject, 'all green');
        });
    }

    public function test_dry_run_sends_nothing(): void
    {
        Mail::fake();

        $this->artisan('ops:nightly-digest', ['--dry-run' => true])->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_empty_recipient_fails_without_throwing(): void
    {
        Mail::fake();
        config(['ops.digest.to' => '']);

        $this->artisan('ops:nightly-digest')->assertExitCode(1);

        Mail::assertNothingSent();
    }
}
