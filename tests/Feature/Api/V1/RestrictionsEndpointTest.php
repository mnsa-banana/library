<?php

namespace Tests\Feature\Api\V1;

use App\Models\Rating;
use App\Models\Report;
use App\Models\StreamingService;
use App\Models\StreamingTitle;
use App\Models\StreamingTitleOffer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RestrictionsEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_titles_on_netflix_with_lgbtq_explicit_rating(): void
    {
        $user = User::factory()->create();
        StreamingService::create(['id' => 'netflix', 'name' => 'Netflix']);
        $report = Report::create([
            'content_type' => 'movie',
            'title' => 'Foo',
            'imdb_id' => 'tt12345',
            'tmdb_id' => 1,
            'year' => '2020',
            'published' => true,
            'published_at' => now(),
        ]);
        Rating::create([
            'report_id' => $report->id,
            'section_key' => 'themes_and_depictions',
            'group_key' => 'relationships_and_family',
            'subcategory_key' => 'explicit_characters_or_relationships',
            'present' => true,
            'evidence' => 'because reasons',
        ]);
        $title = StreamingTitle::create([
            'id' => 't_x',
            'imdb_id' => 'tt12345',
            'show_type' => 'movie',
            'title' => 'Foo',
        ]);
        StreamingTitleOffer::create([
            'title_id' => $title->id,
            'service_id' => 'netflix',
            'region' => 'US',
            'type' => 'subscription',
            'video_quality' => 'hd',
            'link' => 'https://nflx',
        ]);

        $resp = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/restrictions?platform=netflix');

        $resp->assertOk()
            ->assertJsonPath('platform', 'netflix')
            ->assertJsonPath('titles.0.imdb_id', 'tt12345')
            ->assertJsonPath('titles.0.lgbtq_explicit', true);
    }

    public function test_rejects_unsupported_platform(): void
    {
        $user = User::factory()->create();
        $resp = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/restrictions?platform=hulu');
        $resp->assertStatus(422);
    }
}
