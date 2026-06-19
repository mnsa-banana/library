<?php

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StreamingTmdbBackstopTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.tmdb.api_key' => 'tmdb-test',
            'services.netflix_kids.cookie' => 'NetflixId=abc',
            'services.netflix_kids.persisted_query_id' => 'pq',
            'services.netflix_kids.persisted_query_version' => 102,
            'services.netflix_kids.retry_sleep_ms' => 0,
        ]);
        DB::table('streaming_services')->insert(['id' => 'netflix', 'name' => 'Netflix']);
    }

    private function title(string $id, int $tmdbId, string $title, string $type = 'movie'): void
    {
        DB::table('streaming_titles')->insert([
            'id' => $id, 'show_type' => $type, 'title' => $title,
            'tmdb_id' => $tmdbId, 'tmdb_type' => $type, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function kidsHtml(): string
    {
        return '<body data-uia="container-kids">"currentCountry":"US","authURL":"auth",'
            .'"memberapi":{"hostname":"www.netflix.com","path":["/nq/website/memberapi/release"]},'
            .'"BUILD_IDENTIFIER":"v6"</body>';
    }

    private function searchEntity(string $title, string $tn, int $id, int $mat = 70): string
    {
        return '{"displayString":"'.$title.'","unifiedEntity":{"__typename":"'.$tn.'",'
            .'"unifiedEntityId":"Video:'.$id.'","contentAdvisory":{"maturityLevel":'.$mat.'},"videoId":'.$id.'}}';
    }

    public function test_creates_discovery_offer_for_netflix_title_resolvable_in_kids(): void
    {
        $this->title('m1', 814255, 'Percy Jackson');
        Http::fake([
            'api.themoviedb.org/3/movie/814255/watch/providers*' => Http::response(
                ['results' => ['US' => ['flatrate' => [['provider_id' => 8, 'provider_name' => 'Netflix']]]]], 200),
            'www.netflix.com/Kids' => Http::response($this->kidsHtml(), 200),
            'web.prod.cloud.netflix.com/graphql' => Http::response(
                '{"data":{"search":{"edges":['.$this->searchEntity('Percy Jackson', 'Movie', 70120525).']}}}', 200),
        ]);

        $this->artisan('streaming:tmdb-backstop')->assertExitCode(0);

        $this->assertDatabaseHas('streaming_title_offers', [
            'title_id' => 'm1', 'service_id' => 'netflix', 'region' => 'US',
            'type' => 'subscription', 'source' => 'discovery',
            'link' => 'https://www.netflix.com/title/70120525/',
        ]);
    }

    public function test_creates_no_offer_when_netflix_but_not_in_kids_catalog(): void
    {
        $this->title('m2', 999, 'Prince');
        Http::fake([
            'api.themoviedb.org/3/movie/999/watch/providers*' => Http::response(
                ['results' => ['US' => ['flatrate' => [['provider_id' => 8, 'provider_name' => 'Netflix']]]]], 200),
            'www.netflix.com/Kids' => Http::response($this->kidsHtml(), 200),
            'web.prod.cloud.netflix.com/graphql' => Http::response(
                '{"data":{"search":{"edges":['.$this->searchEntity('The Swan Princess', 'Movie', 555).']}}}', 200),
        ]);

        $this->artisan('streaming:tmdb-backstop')->assertExitCode(0);

        $this->assertDatabaseMissing('streaming_title_offers', ['title_id' => 'm2', 'service_id' => 'netflix']);
    }

    public function test_creates_no_offer_when_not_on_netflix(): void
    {
        $this->title('m3', 321, 'Disney Only');
        Http::fake([
            'api.themoviedb.org/3/movie/321/watch/providers*' => Http::response(
                ['results' => ['US' => ['flatrate' => [['provider_id' => 337, 'provider_name' => 'Disney Plus']]]]], 200),
            'www.netflix.com/Kids' => Http::response($this->kidsHtml(), 200),
        ]);

        $this->artisan('streaming:tmdb-backstop')->assertExitCode(0);

        $this->assertDatabaseMissing('streaming_title_offers', ['title_id' => 'm3', 'service_id' => 'netflix']);
    }

    public function test_is_scheduled_monthly(): void
    {
        $schedule = app(Schedule::class);
        $found = collect($schedule->events())->first(
            fn ($e) => str_contains($e->command ?? '', 'streaming:tmdb-backstop'));
        $this->assertNotNull($found, 'streaming:tmdb-backstop should be scheduled');
        $this->assertSame('0 11 1 * *', $found->expression); // monthly, 1st, 11:00 UTC
    }

    public function test_skips_titles_that_already_have_a_netflix_offer(): void
    {
        $this->title('m4', 444, 'Already Known');
        DB::table('streaming_title_offers')->insert([
            'title_id' => 'm4', 'service_id' => 'netflix', 'region' => 'US', 'type' => 'subscription',
            'link' => 'https://www.netflix.com/title/1/', 'source' => 'motn', 'updated_at' => now(),
        ]);
        Http::preventStrayRequests();
        Http::fake(['www.netflix.com/Kids' => Http::response($this->kidsHtml(), 200)]);

        $this->artisan('streaming:tmdb-backstop')->assertExitCode(0);

        $this->assertSame(1, DB::table('streaming_title_offers')
            ->where('title_id', 'm4')->where('service_id', 'netflix')->count());
    }
}
