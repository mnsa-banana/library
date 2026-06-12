<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NetflixAvailabilityEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['library.read_tokens' => 'mnsa:mnsa-token']);
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/api/v1/netflix/availability')->assertStatus(401);
    }

    public function test_returns_the_old_push_payload_shape(): void
    {
        DB::table('streaming_services')->insert(['id' => 'netflix', 'name' => 'Netflix']);
        DB::table('streaming_titles')->insert([
            ['id' => 1, 'imdb_id' => 'tt0000001', 'title' => 'A', 'show_type' => 'movie', 'netflix_kids_surfaced' => true],
            ['id' => 2, 'imdb_id' => 'tt0000002', 'title' => 'B', 'show_type' => 'movie', 'netflix_kids_surfaced' => false],
            ['id' => 3, 'imdb_id' => 'tt0000003', 'title' => 'C', 'show_type' => 'movie', 'netflix_kids_surfaced' => null],
        ]);
        DB::table('streaming_title_offers')->insert([
            ['title_id' => 1, 'service_id' => 'netflix', 'region' => 'US', 'type' => 'subscription', 'link' => ''],
            ['title_id' => 2, 'service_id' => 'netflix', 'region' => 'US', 'type' => 'subscription', 'link' => ''],
            ['title_id' => 3, 'service_id' => 'netflix', 'region' => 'US', 'type' => 'subscription', 'link' => ''],
        ]);

        $this->withToken('mnsa-token')
            ->getJson('/api/v1/netflix/availability')
            ->assertOk()
            ->assertExactJson([
                'imdb_ids' => ['tt0000001', 'tt0000002', 'tt0000003'],
                'kids_imdb_ids' => ['tt0000001'],
            ]);
    }

    public function test_excludes_non_subscription_non_us_and_imdbless_offers(): void
    {
        DB::table('streaming_services')->insert([
            ['id' => 'netflix', 'name' => 'Netflix'],
            ['id' => 'prime', 'name' => 'Prime Video'],
        ]);
        DB::table('streaming_titles')->insert([
            ['id' => 1, 'imdb_id' => 'tt0000001', 'title' => 'RentOnly', 'show_type' => 'movie'],
            ['id' => 2, 'imdb_id' => 'tt0000002', 'title' => 'GbOnly', 'show_type' => 'movie'],
            ['id' => 3, 'imdb_id' => null, 'title' => 'NoImdb', 'show_type' => 'movie'],
            ['id' => 4, 'imdb_id' => '', 'title' => 'EmptyImdb', 'show_type' => 'movie'],
            ['id' => 5, 'imdb_id' => 'tt0000005', 'title' => 'PrimeOnly', 'show_type' => 'movie'],
        ]);
        DB::table('streaming_title_offers')->insert([
            ['title_id' => 1, 'service_id' => 'netflix', 'region' => 'US', 'type' => 'rent', 'link' => ''],
            ['title_id' => 2, 'service_id' => 'netflix', 'region' => 'GB', 'type' => 'subscription', 'link' => ''],
            ['title_id' => 3, 'service_id' => 'netflix', 'region' => 'US', 'type' => 'subscription', 'link' => ''],
            ['title_id' => 4, 'service_id' => 'netflix', 'region' => 'US', 'type' => 'subscription', 'link' => ''],
            ['title_id' => 5, 'service_id' => 'prime', 'region' => 'US', 'type' => 'subscription', 'link' => ''],
        ]);

        $this->withToken('mnsa-token')
            ->getJson('/api/v1/netflix/availability')
            ->assertOk()
            ->assertExactJson(['imdb_ids' => [], 'kids_imdb_ids' => []]);
    }
}
