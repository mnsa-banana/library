<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
