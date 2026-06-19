<?php

namespace Tests\Unit\Models;

use App\Models\StreamingTitleOffer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StreamingTitleOfferTest extends TestCase
{
    use RefreshDatabase;

    public function test_netflix_title_link_builds_canonical_url(): void
    {
        $this->assertSame(
            'https://www.netflix.com/title/70243343/',
            StreamingTitleOffer::netflixTitleLink(70243343),
        );
    }

    public function test_netflix_video_id_from_link_extracts_or_returns_null(): void
    {
        $this->assertSame(70243343, StreamingTitleOffer::netflixVideoIdFromLink('https://www.netflix.com/title/70243343/'));
        $this->assertNull(StreamingTitleOffer::netflixVideoIdFromLink('https://www.netflix.com/watch/5'));
        $this->assertNull(StreamingTitleOffer::netflixVideoIdFromLink(null));
    }

    public function test_upsert_discovery_netflix_is_idempotent(): void
    {
        DB::table('streaming_services')->insert([
            'id' => 'netflix',
            'name' => 'Netflix',
        ]);
        DB::table('streaming_titles')->insert([
            'id' => 'title-1',
            'show_type' => 'movie',
            'title' => 'Test Title',
        ]);

        StreamingTitleOffer::upsertDiscoveryNetflix('title-1', 70243343);

        $offers = DB::table('streaming_title_offers')->where('title_id', 'title-1')->get();
        $this->assertCount(1, $offers);
        $this->assertSame('discovery', $offers->first()->source);
        $this->assertSame('subscription', $offers->first()->type);
        $this->assertSame('https://www.netflix.com/title/70243343/', $offers->first()->link);

        // Re-running with a different videoId must UPDATE the single row, not insert a duplicate.
        StreamingTitleOffer::upsertDiscoveryNetflix('title-1', 81009946);

        $offers = DB::table('streaming_title_offers')->where('title_id', 'title-1')->get();
        $this->assertCount(1, $offers);
        $this->assertSame('https://www.netflix.com/title/81009946/', $offers->first()->link);
    }

    public function test_upsert_discovery_does_not_clobber_an_existing_motn_offer(): void
    {
        DB::table('streaming_services')->insert(['id' => 'netflix', 'name' => 'Netflix']);
        DB::table('streaming_titles')->insert([
            'id' => 'title-2', 'show_type' => 'movie', 'title' => 'MOTN Owned',
        ]);

        // A MOTN-owned offer at the same source-excluding unique key
        // (title_id, service_id, region, type, video_quality=null).
        DB::table('streaming_title_offers')->insert([
            'title_id' => 'title-2', 'service_id' => 'netflix', 'region' => 'US',
            'type' => 'subscription', 'video_quality' => null,
            'link' => 'https://www.netflix.com/title/1/', 'source' => 'motn', 'updated_at' => now(),
        ]);

        StreamingTitleOffer::upsertDiscoveryNetflix('title-2', 999);

        // The discovery write must have been ignored — MOTN owns the key.
        $offers = DB::table('streaming_title_offers')->where('title_id', 'title-2')->get();
        $this->assertCount(1, $offers);
        $this->assertSame('motn', $offers->first()->source);
        $this->assertSame('https://www.netflix.com/title/1/', $offers->first()->link);
    }
}
