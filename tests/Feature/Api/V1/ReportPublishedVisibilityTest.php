<?php

namespace Tests\Feature\Api\V1;

use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Visibility is gated on the `published` boolean, NOT on `published_at`.
 * `published_at` is NOT NULL (defaults to CURRENT_TIMESTAMP), so a
 * `whereNotNull('published_at')` filter is always true and would leak
 * unpublished reports into the parent feed.
 */
class ReportPublishedVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function makeReport(bool $published, string $title): Report
    {
        return Report::create([
            'content_type' => 'movie',
            'title' => $title,
            'imdb_id' => 'tt'.crc32($title),
            'tmdb_id' => crc32($title) % 100000,
            'year' => '2020',
            'published' => $published,
            'published_at' => now(),
        ]);
    }

    public function test_index_excludes_unpublished_reports(): void
    {
        $user = User::factory()->create();
        $this->makeReport(true, 'Published One');
        $this->makeReport(false, 'Unpublished One');

        $resp = $this->actingAs($user, 'sanctum')->getJson('/api/v1/reports');

        $resp->assertOk();
        $titles = collect($resp->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('Published One'));
        $this->assertFalse($titles->contains('Unpublished One'));
    }

    public function test_show_404s_for_unpublished_report(): void
    {
        $user = User::factory()->create();
        $report = $this->makeReport(false, 'Hidden Movie');

        $resp = $this->actingAs($user, 'sanctum')->getJson("/api/v1/reports/{$report->id}");

        $resp->assertNotFound();
    }
}
