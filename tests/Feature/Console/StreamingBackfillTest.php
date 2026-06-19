<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StreamingBackfillTest extends TestCase
{
    use RefreshDatabase;

    /** Seed a title + services so an offer row's FKs resolve. */
    private function seedTitle(string $id, string $title = 'A Title'): void
    {
        DB::table('streaming_services')->updateOrInsert(['id' => 'netflix'], ['name' => 'Netflix']);
        DB::table('streaming_services')->updateOrInsert(['id' => 'disney'], ['name' => 'Disney+']);
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

    /**
     * Fake /shows/search/filters: one page returning $show for the given catalog,
     * empty for every other catalog the backfill iterates.
     */
    private function fakeFiltersFor(string $catalog, array $show): void
    {
        Http::fake(function ($request) use ($catalog, $show) {
            $url = $request->url();
            if (str_contains($url, '/shows/search/filters')) {
                parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $q);
                $hit = ($q['catalogs'] ?? '') === $catalog;

                return Http::response($hit
                    ? ['shows' => [$show], 'nextCursor' => null, 'hasMore' => false]
                    : ['shows' => [], 'nextCursor' => null, 'hasMore' => false], 200);
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

    public function test_backfill_preserves_discovery_offer_for_a_service_it_does_not_report(): void
    {
        $this->cfg();
        $this->seedTitle('show-d', 'Disney Kid Show');
        $this->discoveryNetflixOffer('show-d', 999); // discovered on Netflix; backfill payload won't mention it.

        // Backfill the disney catalog with a payload whose streamingOptions.us has NO netflix.
        $this->fakeFiltersFor('disney.subscription', [
            'id' => 'show-d', 'title' => 'Disney Kid Show', 'showType' => 'series',
            'streamingOptions' => ['us' => [[
                'service' => ['id' => 'disney', 'name' => 'Disney+'], 'type' => 'subscription',
                'quality' => 'hd', 'link' => 'https://disneyplus.com/x',
            ]]],
        ]);

        $this->artisan('streaming:backfill', ['--catalog' => ['disney.subscription']])->assertSuccessful();

        // The discovery netflix offer SURVIVES (old code would have deleted it).
        $this->assertDatabaseHas('streaming_title_offers',
            ['title_id' => 'show-d', 'service_id' => 'netflix', 'source' => 'discovery']);
        $this->assertDatabaseHas('streaming_title_offers',
            ['title_id' => 'show-d', 'service_id' => 'disney', 'source' => 'motn']);
    }

    public function test_backfill_supersedes_discovery_netflix_offer_when_payload_includes_netflix(): void
    {
        $this->cfg();
        $this->seedTitle('show-n', 'Now On Netflix');
        $this->discoveryNetflixOffer('show-n', 1); // discovery used video_quality = NULL.

        // Backfill the netflix catalog with a payload that DOES include netflix (different quality).
        $this->fakeFiltersFor('netflix.subscription', [
            'id' => 'show-n', 'title' => 'Now On Netflix', 'showType' => 'series',
            'streamingOptions' => ['us' => [[
                'service' => ['id' => 'netflix', 'name' => 'Netflix'], 'type' => 'subscription',
                'quality' => 'hd', 'link' => 'https://www.netflix.com/title/1/',
            ]]],
        ]);

        $this->artisan('streaming:backfill', ['--catalog' => ['netflix.subscription']])->assertSuccessful();

        $offers = DB::table('streaming_title_offers')
            ->where('title_id', 'show-n')->where('service_id', 'netflix')->get();
        $this->assertCount(1, $offers);
        $this->assertSame('motn', $offers[0]->source);
        $this->assertSame('hd', $offers[0]->video_quality);
    }
}
