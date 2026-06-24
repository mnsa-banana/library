<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StreamingReconcileNetflixDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.netflix_kids.cookie' => 'NetflixId=abc',
            'services.netflix_kids.retry_sleep_ms' => 0,
        ]);
        DB::table('streaming_services')->insert(['id' => 'netflix', 'name' => 'Netflix']);
    }

    private function title(string $id, string $title, ?string $imdb, string $show = 'movie', ?int $releaseYear = null, ?bool $surfaced = null): void
    {
        DB::table('streaming_titles')->insert([
            'id' => $id, 'show_type' => $show, 'title' => $title, 'imdb_id' => $imdb,
            'release_year' => $releaseYear, 'netflix_kids_surfaced' => $surfaced,
            'netflix_kids_checked_at' => $surfaced !== null ? now() : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function discoveryOffer(string $titleId, int $videoId): void
    {
        DB::table('streaming_title_offers')->insert([
            'title_id' => $titleId, 'service_id' => 'netflix', 'region' => 'US', 'type' => 'subscription',
            'link' => "https://www.netflix.com/title/{$videoId}/", 'source' => 'discovery', 'updated_at' => now(),
        ]);
    }

    private function kidsHtml(): string
    {
        return '<body data-uia="container-kids">"currentCountry":"US","authURL":"auth",'
            .'"memberapi":{"hostname":"www.netflix.com","path":["/nq/website/memberapi/release"]},'
            .'"BUILD_IDENTIFIER":"v6"</body>';
    }

    /** Fake: /Kids session html, then a pathEvaluator response resolving $videoId → {title, year}. */
    private function fakeSession(string $videosJson): void
    {
        Http::fake([
            'www.netflix.com/Kids' => Http::response($this->kidsHtml(), 200),
            '*pathEvaluator*' => Http::response('{"jsonGraph":{"videos":'.$videosJson.'}}', 200),
        ]);
    }

    public function test_moves_misassigned_offer_and_resets_orphaned_flag(): void
    {
        // Two "Fearless" movies; the offer (with the 2020 Kids videoId) was wrongly stamped
        // onto the 1993 row and that row carries a stale surfaced=true.
        $this->title('13144', 'Fearless', 'tt0106881', 'movie', 1993, surfaced: true);
        $this->title('62957', 'Fearless', 'tt8675288', 'movie', 2020);
        $this->discoveryOffer('13144', 80200000);
        $this->fakeSession('{"80200000":{"title":{"$type":"atom","value":"Fearless"},"releaseYear":{"$type":"atom","value":2020}}}');

        $this->artisan('streaming:reconcile-netflix-discovery', ['--apply' => true])->assertExitCode(0);

        // Offer moved off the 1993 row onto the 2020 row, same videoId.
        $this->assertDatabaseMissing('streaming_title_offers', ['title_id' => '13144', 'service_id' => 'netflix']);
        $this->assertDatabaseHas('streaming_title_offers', [
            'title_id' => '62957', 'service_id' => 'netflix', 'region' => 'US',
            'source' => 'discovery', 'link' => 'https://www.netflix.com/title/80200000/',
        ]);
        // Vacated row's stale flag cleared.
        $this->assertNull(DB::table('streaming_titles')->where('id', '13144')->value('netflix_kids_surfaced'));
        $this->assertNull(DB::table('streaming_titles')->where('id', '13144')->value('netflix_kids_checked_at'));
    }

    public function test_dry_run_makes_no_changes(): void
    {
        $this->title('13144', 'Fearless', 'tt0106881', 'movie', 1993, surfaced: true);
        $this->title('62957', 'Fearless', 'tt8675288', 'movie', 2020);
        $this->discoveryOffer('13144', 80200000);
        $this->fakeSession('{"80200000":{"title":{"$type":"atom","value":"Fearless"},"releaseYear":{"$type":"atom","value":2020}}}');

        $this->artisan('streaming:reconcile-netflix-discovery')->assertExitCode(0);

        // Untouched: offer still on the wrong row, flag still set.
        $this->assertDatabaseHas('streaming_title_offers', ['title_id' => '13144', 'service_id' => 'netflix']);
        $this->assertDatabaseMissing('streaming_title_offers', ['title_id' => '62957', 'service_id' => 'netflix']);
        $this->assertTrue((bool) DB::table('streaming_titles')->where('id', '13144')->value('netflix_kids_surfaced'));

        $meta = json_decode(DB::table('streaming_sync_log')
            ->where('sync_type', 'reconcile_netflix_discovery')->latest('id')->value('metadata'), true);
        $this->assertFalse($meta['applied']);
        $this->assertSame(1, $meta['moved']);
    }

    public function test_correctly_placed_offer_is_left_alone(): void
    {
        $this->title('13144', 'Fearless', 'tt0106881', 'movie', 1993);
        $this->title('62957', 'Fearless', 'tt8675288', 'movie', 2020);
        $this->discoveryOffer('62957', 80200000); // already correct
        $this->fakeSession('{"80200000":{"title":{"$type":"atom","value":"Fearless"},"releaseYear":{"$type":"atom","value":2020}}}');

        $this->artisan('streaming:reconcile-netflix-discovery', ['--apply' => true])->assertExitCode(0);

        $this->assertDatabaseHas('streaming_title_offers', ['title_id' => '62957', 'service_id' => 'netflix']);
        $meta = json_decode(DB::table('streaming_sync_log')
            ->where('sync_type', 'reconcile_netflix_discovery')->latest('id')->value('metadata'), true);
        $this->assertSame(1, $meta['ok']);
        $this->assertSame(0, $meta['moved']);
    }

    public function test_unconfident_reresolution_is_left_for_review_not_deleted(): void
    {
        // Collision, but Netflix returns no releaseYear → resolver can't disambiguate → null.
        // The offer must NOT be deleted; it goes to the review bucket.
        $this->title('13144', 'Fearless', 'tt0106881', 'movie', 1993);
        $this->title('62957', 'Fearless', 'tt8675288', 'movie', 2020);
        $this->discoveryOffer('13144', 80200000);
        $this->fakeSession('{"80200000":{"title":{"$type":"atom","value":"Fearless"}}}');

        $this->artisan('streaming:reconcile-netflix-discovery', ['--apply' => true])->assertExitCode(0);

        $this->assertDatabaseHas('streaming_title_offers', ['title_id' => '13144', 'service_id' => 'netflix']);
        $meta = json_decode(DB::table('streaming_sync_log')
            ->where('sync_type', 'reconcile_netflix_discovery')->latest('id')->value('metadata'), true);
        $this->assertSame(1, $meta['review']);
        $this->assertSame(0, $meta['moved']);
    }

    public function test_unresolvable_video_is_left_alone(): void
    {
        // Netflix returns no entity for the videoId (title left Netflix) → unresolved, untouched.
        $this->title('13144', 'Fearless', 'tt0106881', 'movie', 1993);
        $this->discoveryOffer('13144', 80200000);
        $this->fakeSession('{}');

        $this->artisan('streaming:reconcile-netflix-discovery', ['--apply' => true])->assertExitCode(0);

        $this->assertDatabaseHas('streaming_title_offers', ['title_id' => '13144', 'service_id' => 'netflix']);
        $meta = json_decode(DB::table('streaming_sync_log')
            ->where('sync_type', 'reconcile_netflix_discovery')->latest('id')->value('metadata'), true);
        $this->assertSame(1, $meta['unresolved']);
    }

    public function test_aborts_on_invalid_session(): void
    {
        Http::fake(['www.netflix.com/Kids' => Http::response('<body>not kids</body>', 200)]);
        $this->artisan('streaming:reconcile-netflix-discovery', ['--apply' => true])->assertExitCode(1);
    }
}
