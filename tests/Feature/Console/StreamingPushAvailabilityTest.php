<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StreamingPushAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_posts_distinct_netflix_us_imdb_ids_to_mnsa(): void
    {
        Http::fake([
            '*' => Http::response([
                'matched' => 2, 'missing' => 0, 'marked_true' => 2, 'marked_false' => 0,
            ], 200),
        ]);

        config([
            'services.mnsa.base_url' => 'https://mnsa.test',
            'services.mnsa.service_token' => 'test-token',
        ]);

        // Seed two streaming titles on Netflix US.
        DB::table('streaming_services')->insert(['id' => 'netflix', 'name' => 'Netflix']);
        DB::table('streaming_titles')->insert([
            ['id' => 1, 'imdb_id' => 'tt0000001', 'title' => 'A', 'show_type' => 'movie'],
            ['id' => 2, 'imdb_id' => 'tt0000002', 'title' => 'B', 'show_type' => 'movie'],
        ]);
        DB::table('streaming_title_offers')->insert([
            ['title_id' => 1, 'service_id' => 'netflix', 'region' => 'US', 'type' => 'subscription', 'link' => ''],
            ['title_id' => 2, 'service_id' => 'netflix', 'region' => 'US', 'type' => 'subscription', 'link' => ''],
        ]);

        $this->artisan('streaming:push-availability')->assertExitCode(0);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://mnsa.test/api/v1/internal/netflix-availability'
                && $request->method() === 'POST'
                && $request['imdb_ids'] === ['tt0000001', 'tt0000002']
                && $request->header('Authorization')[0] === 'Bearer test-token';
        });
    }
}
