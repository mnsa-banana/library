<?php

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StreamingDiscoverNetflixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.netflix_kids.cookie' => 'NetflixId=abc',
            'services.netflix_kids.retry_sleep_ms' => 0,
            'services.netflix_kids.browse_genres' => [['id' => 34399, 'type' => 'movie']],
        ]);
        DB::table('streaming_services')->insert(['id' => 'netflix', 'name' => 'Netflix']);
    }

    private function title(string $id, string $title, string $show = 'movie'): void
    {
        DB::table('streaming_titles')->insert([
            'id' => $id, 'show_type' => $show, 'title' => $title,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function kidsHtml(): string
    {
        return '<body data-uia="container-kids">"currentCountry":"US","authURL":"auth",'
            .'"memberapi":{"hostname":"www.netflix.com","path":["/nq/website/memberapi/release"]},'
            .'"BUILD_IDENTIFIER":"v6"</body>';
    }

    /** Fake: /Kids html, then genre page (1 video), then title resolve, then empty page. */
    private function fakeBrowse(int $videoId, string $title): void
    {
        Http::fake([
            'www.netflix.com/Kids' => Http::response($this->kidsHtml(), 200),
            '*pathEvaluator*' => Http::sequence()
                ->push('{"jsonGraph":{"genres":{"34399":{"su":{"0":{"reference":{"$type":"ref","value":["videos","'.$videoId.'"]}}}}}}}', 200)
                ->push('{"jsonGraph":{"videos":{"'.$videoId.'":{"title":{"$type":"atom","value":"'.$title.'"}}}}}', 200)
                ->whenEmpty(Http::response('{"jsonGraph":{"videos":{}}}', 200)),
        ]);
    }

    public function test_creates_discovery_offer_for_matched_title_without_a_netflix_offer(): void
    {
        $this->title('m1', 'Percy Jackson Sea of Monsters', 'movie');
        $this->fakeBrowse(70243343, 'Percy Jackson Sea of Monsters');

        $this->artisan('streaming:discover-netflix')->assertExitCode(0);

        $this->assertDatabaseHas('streaming_title_offers', [
            'title_id' => 'm1', 'service_id' => 'netflix', 'region' => 'US',
            'type' => 'subscription', 'source' => 'discovery',
            'link' => 'https://www.netflix.com/title/70243343/',
        ]);
    }

    public function test_skips_title_that_already_has_a_netflix_offer(): void
    {
        $this->title('m2', 'Already Known', 'movie');
        DB::table('streaming_title_offers')->insert([
            'title_id' => 'm2', 'service_id' => 'netflix', 'region' => 'US', 'type' => 'subscription',
            'link' => 'https://www.netflix.com/title/1/', 'source' => 'motn', 'updated_at' => now(),
        ]);
        $this->fakeBrowse(999, 'Already Known');

        $this->artisan('streaming:discover-netflix')->assertExitCode(0);

        // No second netflix offer; the motn one is untouched.
        $this->assertSame(1, DB::table('streaming_title_offers')
            ->where('title_id', 'm2')->where('service_id', 'netflix')->count());
    }

    public function test_restamps_existing_discovery_offer_without_duplicating(): void
    {
        $this->title('m3', 'Surfaced Again', 'movie');
        $old = now()->subDays(30);
        DB::table('streaming_title_offers')->insert([
            'title_id' => 'm3', 'service_id' => 'netflix', 'region' => 'US', 'type' => 'subscription',
            'link' => 'https://www.netflix.com/title/111/', 'source' => 'discovery', 'updated_at' => $old,
        ]);
        $this->fakeBrowse(222, 'Surfaced Again');

        $this->artisan('streaming:discover-netflix')->assertExitCode(0);

        // Still exactly one netflix offer, still source='discovery', updated_at re-stamped.
        $offers = DB::table('streaming_title_offers')
            ->where('title_id', 'm3')->where('service_id', 'netflix')->get();
        $this->assertCount(1, $offers);
        $this->assertSame('discovery', $offers->first()->source);
        $this->assertTrue(
            Carbon::parse($offers->first()->updated_at)->gt($old),
            'updated_at should be newer than the old value'
        );

        $meta = json_decode(DB::table('streaming_sync_log')
            ->where('sync_type', 'discover_netflix')->latest('id')->value('metadata'), true);
        $this->assertSame(1, $meta['offers_restamped']);
        $this->assertSame(0, $meta['offers_created']);
    }

    public function test_does_not_reap_a_browse_present_title_marked_not_surfaced(): void
    {
        // Regression: a title carrying a STALE netflix_kids_surfaced=false flag that
        // reappears in the live browse this run. The old post-loop sweep would CREATE
        // its discovery offer in the loop and then DELETE it in the same run (discover
        // never updates the flag), making the title permanently invisible to verify-kids.
        // The sweep is gone, so the freshly written discovery offer must survive.
        DB::table('streaming_titles')->insert([
            'id' => 'stale', 'show_type' => 'movie', 'title' => 'Back In Kids',
            'netflix_kids_surfaced' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Browse surfaces this exact title this run, and it resolves to 'stale'.
        $this->fakeBrowse(900, 'Back In Kids');

        $this->artisan('streaming:discover-netflix')->assertExitCode(0);

        // The discovery offer created in the loop must NOT be reaped.
        $this->assertDatabaseHas('streaming_title_offers', [
            'title_id' => 'stale', 'service_id' => 'netflix', 'region' => 'US',
            'type' => 'subscription', 'source' => 'discovery',
            'link' => 'https://www.netflix.com/title/900/',
        ]);
    }

    public function test_logs_unmatched_title_without_creating_anything(): void
    {
        // No streaming_title matches "Brand New Original".
        $this->fakeBrowse(555, 'Brand New Original');

        $this->artisan('streaming:discover-netflix')->assertExitCode(0);

        $this->assertSame(0, DB::table('streaming_title_offers')->count());
        $meta = json_decode(DB::table('streaming_sync_log')
            ->where('sync_type', 'discover_netflix')->latest('id')->value('metadata'), true);
        $this->assertContains('Brand New Original', collect($meta['unmatched'] ?? [])->pluck('title')->all());
    }

    public function test_is_scheduled_weekly(): void
    {
        $schedule = app(Schedule::class);
        $found = collect($schedule->events())->first(
            fn ($e) => str_contains($e->command ?? '', 'streaming:discover-netflix'));
        $this->assertNotNull($found, 'streaming:discover-netflix should be scheduled');
        $this->assertSame('0 11 * * 6', $found->expression); // Saturday 11:00 UTC
    }
}
