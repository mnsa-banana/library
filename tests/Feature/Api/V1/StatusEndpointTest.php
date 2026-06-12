<?php

namespace Tests\Feature\Api\V1;

use App\Models\BookSyncLog;
use App\Models\StreamingSyncLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatusEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['library.read_tokens' => 'admin:admin-token']);
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/api/v1/status')->assertStatus(401);
    }

    public function test_returns_nulls_when_nothing_has_completed(): void
    {
        StreamingSyncLog::create(['sync_type' => 'pipeline', 'status' => 'running']);
        StreamingSyncLog::create(['sync_type' => 'pipeline', 'status' => 'failed', 'completed_at' => now()]);

        $this->withToken('admin-token')
            ->getJson('/api/v1/status')
            ->assertOk()
            ->assertExactJson(['streaming' => null, 'services' => null, 'books' => null]);
    }

    public function test_reports_last_completed_run_per_pipeline(): void
    {
        // Older completed pipeline run, then a newer one — the newer wins.
        StreamingSyncLog::create(['sync_type' => 'pipeline', 'status' => 'completed', 'completed_at' => '2026-06-10 03:25:00']);
        StreamingSyncLog::create(['sync_type' => 'pipeline', 'status' => 'completed', 'completed_at' => '2026-06-11 03:24:00']);
        // A later FAILED pipeline run must not advance the timestamp.
        StreamingSyncLog::create(['sync_type' => 'pipeline', 'status' => 'failed', 'completed_at' => '2026-06-12 03:30:00']);
        // Other streaming sync_types (enrich/changes) don't count as the pipeline signal.
        StreamingSyncLog::create(['sync_type' => 'enrich', 'status' => 'completed', 'completed_at' => '2026-06-12 04:00:00']);

        StreamingSyncLog::create(['sync_type' => 'service_refresh', 'status' => 'completed', 'completed_at' => '2026-06-01 02:05:00']);

        BookSyncLog::create(['sync_type' => 'weekly', 'status' => 'completed', 'completed_at' => '2026-06-11 09:10:00']);
        BookSyncLog::create(['sync_type' => 'enrich', 'status' => 'failed', 'completed_at' => '2026-06-11 10:10:00']);

        $response = $this->withToken('admin-token')->getJson('/api/v1/status')->assertOk();

        $this->assertSame('2026-06-11T03:24:00+00:00', $response->json('streaming'));
        $this->assertSame('2026-06-01T02:05:00+00:00', $response->json('services'));
        $this->assertSame('2026-06-11T09:10:00+00:00', $response->json('books'));
    }
}
