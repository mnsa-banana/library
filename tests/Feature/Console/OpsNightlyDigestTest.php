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
        foreach ([
            ['streaming_sync_log', 'pipeline', '2026-06-16 09:29:00'],
            ['streaming_sync_log', 'verify_kids', '2026-06-16 09:14:00'],
            ['book_sync_log', 'enrich', '2026-06-16 10:22:00'],
            ['book_sync_log', 'seed_nyt_history', '2026-06-16 09:30:00'],
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
}
