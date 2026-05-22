<?php

namespace Tests\Feature\Api\V1;

use App\Models\Report;
use App\Models\StreamingService;
use App\Models\StreamingTitle;
use App\Models\StreamingTitleOffer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportStreamingEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_grouped_streaming_options_for_published_report(): void
    {
        $user = User::factory()->create();
        $report = Report::create([
            'content_type' => 'movie',
            'title' => 'Test Movie',
            'imdb_id' => 'tt7654321',
            'tmdb_id' => 9999,
            'published' => true,
        ]);
        StreamingService::create(['id' => 'netflix', 'name' => 'Netflix']);
        $title = StreamingTitle::create([
            'id' => 't_abc',
            'imdb_id' => 'tt7654321',
            'tmdb_id' => 9999,
            'tmdb_type' => 'movie',
            'show_type' => 'movie',
            'title' => 'Test Movie',
        ]);
        StreamingTitleOffer::create([
            'title_id' => $title->id,
            'service_id' => 'netflix',
            'region' => 'US',
            'type' => 'subscription',
            'video_quality' => 'hd',
            'link' => 'https://nflx',
        ]);

        $resp = $this->actingAs($user, 'sanctum')->getJson("/api/v1/reports/{$report->id}/streaming");
        $resp->assertOk()
            ->assertJsonStructure(['subscription', 'free', 'rent', 'buy'])
            ->assertJsonPath('subscription.0.name', 'Netflix');
    }

    public function test_returns_empty_groups_for_unknown_report(): void
    {
        $user = User::factory()->create();
        $resp = $this->actingAs($user, 'sanctum')->getJson('/api/v1/reports/999999/streaming');
        $resp->assertOk()->assertJson(['subscription' => [], 'free' => [], 'rent' => [], 'buy' => []]);
    }
}
