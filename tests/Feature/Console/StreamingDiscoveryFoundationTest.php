<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StreamingDiscoveryFoundationTest extends TestCase
{
    use RefreshDatabase;

    /** Seed a title + service so an offer row's FKs resolve. */
    private function seedTitle(string $id, string $title = 'A Title'): void
    {
        DB::table('streaming_services')->updateOrInsert(['id' => 'netflix'], ['name' => 'Netflix']);
        DB::table('streaming_titles')->updateOrInsert(
            ['id' => $id],
            ['show_type' => 'movie', 'title' => $title, 'created_at' => now(), 'updated_at' => now()],
        );
    }

    private function cfg(): void
    {
        config([
            'services.streaming_availability.api_key' => 'test-key',
            'services.streaming_availability.base_url' => 'https://api.streaming.test/v4',
            'services.streaming_availability.qps' => 0,
            'services.streaming_availability.timeout' => 5,
        ]);
    }

    /** Fake /changes: returns $show for the given catalog+new, empty for all others. */
    private function fakeChangesFor(string $catalog, string $showId, array $show): void
    {
        Http::fake(function ($request) use ($catalog, $showId, $show) {
            $url = $request->url();
            if (str_contains($url, '/changes')) {
                parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $q);
                $hit = ($q['catalogs'] ?? '') === $catalog && ($q['change_type'] ?? '') === 'new';

                return Http::response($hit
                    ? ['changes' => [['showId' => $showId]], 'shows' => [$showId => $show], 'hasMore' => false]
                    : ['changes' => [], 'shows' => [], 'hasMore' => false], 200);
            }

            return Http::response(['matched' => 0], 200);
        });
    }

    private function discoveryNetflixOffer(string $titleId, int $nfid): void
    {
        DB::table('streaming_title_offers')->insert([
            'title_id' => $titleId, 'service_id' => 'netflix', 'region' => 'US',
            'type' => 'subscription', 'video_quality' => null,
            'link' => "https://www.netflix.com/title/{$nfid}/", 'source' => 'discovery', 'updated_at' => now(),
        ]);
    }

    public function test_offer_source_defaults_to_motn(): void
    {
        $this->seedTitle('t1');
        DB::table('streaming_title_offers')->insert([
            'title_id' => 't1', 'service_id' => 'netflix', 'region' => 'US',
            'type' => 'subscription', 'link' => 'https://www.netflix.com/title/1/', 'updated_at' => now(),
        ]);

        $this->assertSame('motn',
            DB::table('streaming_title_offers')->where('title_id', 't1')->value('source'));
    }

    public function test_motn_sync_preserves_discovery_offer_for_a_service_it_does_not_report(): void
    {
        $this->cfg();
        $this->seedTitle('show-d', 'Disney Kid Show');
        $this->discoveryNetflixOffer('show-d', 999); // we discovered it on Netflix; MOTN hasn't.

        // MOTN reports this title via Disney only — NO netflix in the payload.
        $this->fakeChangesFor('disney.subscription', 'show-d', [
            'id' => 'show-d', 'title' => 'Disney Kid Show', 'showType' => 'series',
            'streamingOptions' => ['us' => [[
                'service' => ['id' => 'disney', 'name' => 'Disney+'], 'type' => 'subscription',
                'quality' => 'hd', 'link' => 'https://disneyplus.com/x',
            ]]],
        ]);

        $this->artisan('streaming:sync', ['--hours' => 72])->assertSuccessful();

        $this->assertDatabaseHas('streaming_title_offers',
            ['title_id' => 'show-d', 'service_id' => 'netflix', 'source' => 'discovery']);
        $this->assertDatabaseHas('streaming_title_offers',
            ['title_id' => 'show-d', 'service_id' => 'disney', 'source' => 'motn']);
    }

    public function test_motn_supersedes_discovery_netflix_offer_when_it_catches_up_without_crashing(): void
    {
        $this->cfg();
        $this->seedTitle('show-n', 'Now On Netflix');
        $this->discoveryNetflixOffer('show-n', 1); // discovery used video_quality = NULL

        // MOTN now reports netflix for it, with a DIFFERENT video_quality ('hd').
        $this->fakeChangesFor('netflix.subscription', 'show-n', [
            'id' => 'show-n', 'title' => 'Now On Netflix', 'showType' => 'series',
            'streamingOptions' => ['us' => [[
                'service' => ['id' => 'netflix', 'name' => 'Netflix'], 'type' => 'subscription',
                'quality' => 'hd', 'link' => 'https://www.netflix.com/title/1/',
            ]]],
        ]);

        $this->artisan('streaming:sync', ['--hours' => 72])->assertSuccessful(); // no /changes crash

        $offers = DB::table('streaming_title_offers')
            ->where('title_id', 'show-n')->where('service_id', 'netflix')->get();
        $this->assertCount(1, $offers);
        $this->assertSame('motn', $offers[0]->source);
        $this->assertSame('hd', $offers[0]->video_quality);
    }
}
