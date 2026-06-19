<?php

namespace Tests\Unit\Services\NetflixKids;

use App\Services\NetflixKids\NetflixKidsClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NetflixSearchResolveTest extends TestCase
{
    private function cfg(): void
    {
        config()->set('services.netflix_kids.cookie', 'NetflixId=abc');
        config()->set('services.netflix_kids.persisted_query_id', 'pq');
        config()->set('services.netflix_kids.persisted_query_version', 102);
        config()->set('services.netflix_kids.retry_sleep_ms', 0);
    }

    private function fakeSearch(array $entities): void
    {
        $blobs = array_map(fn ($e) => '{"__typename":"PinotSuggestionEntityTreatment",'
            .'"displayString":"'.$e['title'].'",'
            .'"unifiedEntity":{"__typename":"'.$e['type'].'","unifiedEntityId":"Video:'.$e['id'].'",'
            .'"contentAdvisory":{"__typename":"ContentAdvisory","maturityLevel":'.$e['mat'].'},'
            .'"videoId":'.$e['id'].'}}', $entities);

        Http::fake([
            'www.netflix.com/Kids' => Http::response($this->goodKidsHtml(), 200),
            'web.prod.cloud.netflix.com/graphql' => Http::response(
                '{"data":{"search":{"edges":['.implode(',', $blobs).']}}}', 200),
        ]);
    }

    private function goodKidsHtml(): string
    {
        return '<body data-uia="container-kids">"currentCountry":"US",'
            .'"authURL":"auth","memberapi":{"hostname":"www.netflix.com",'
            .'"path":["/nq/website/memberapi/release"]},"BUILD_IDENTIFIER":"v6c030968"</body>';
    }

    public function test_search_results_parses_video_id_title_type_maturity(): void
    {
        $this->cfg();
        $this->fakeSearch([
            ['title' => 'Percy Jackson: Sea of Monsters', 'type' => 'Movie', 'id' => 70243343, 'mat' => 70],
            ['title' => 'Raising Dion', 'type' => 'Show', 'id' => 80117803, 'mat' => 70],
        ]);

        $results = (new NetflixKidsClient)->searchResults('Percy Jackson', 'v1');

        $this->assertCount(2, $results);
        $this->assertSame(70243343, $results[0]['videoId']);
        $this->assertSame('Percy Jackson: Sea of Monsters', $results[0]['title']);
        $this->assertSame('movie', $results[0]['type']);
        $this->assertSame(70, $results[0]['maturity']);
        $this->assertSame('series', $results[1]['type']);
    }

    /** Fake a search response from raw entity blobs (lets a test control contentAdvisory shape). */
    private function fakeSearchRaw(array $blobs): void
    {
        Http::fake([
            'www.netflix.com/Kids' => Http::response($this->goodKidsHtml(), 200),
            'web.prod.cloud.netflix.com/graphql' => Http::response(
                '{"data":{"search":{"edges":['.implode(',', $blobs).']}}}', 200),
        ]);
    }

    public function test_search_captures_entity_despite_nested_content_advisory(): void
    {
        $this->cfg();
        // contentAdvisory nests an object ("reason") BEFORE maturityLevel — the old
        // [^{}]* regex couldn't cross the nested '{' and would drop the whole entity.
        $this->fakeSearchRaw([
            '{"__typename":"PinotSuggestionEntityTreatment",'
            .'"displayString":"Nested Advisory Movie",'
            .'"unifiedEntity":{"__typename":"Movie","unifiedEntityId":"Video:555",'
            .'"contentAdvisory":{"reason":{"id":9},"maturityLevel":70},'
            .'"videoId":555}}',
        ]);

        $results = (new NetflixKidsClient)->searchResults('Nested Advisory', 'v1');

        $this->assertCount(1, $results);
        $this->assertSame(555, $results[0]['videoId']);
        $this->assertSame('Nested Advisory Movie', $results[0]['title']);
        $this->assertSame('movie', $results[0]['type']);
        $this->assertSame(70, $results[0]['maturity']);
    }

    public function test_search_captures_entity_with_no_maturity_level(): void
    {
        $this->cfg();
        // No maturityLevel at all → entity still captured, maturity null (not dropped).
        $this->fakeSearchRaw([
            '{"__typename":"PinotSuggestionEntityTreatment",'
            .'"displayString":"No Advisory Show",'
            .'"unifiedEntity":{"__typename":"Show","unifiedEntityId":"Video:666",'
            .'"videoId":666}}',
        ]);

        $results = (new NetflixKidsClient)->searchResults('No Advisory', 'v1');

        $this->assertCount(1, $results);
        $this->assertSame(666, $results[0]['videoId']);
        $this->assertSame('No Advisory Show', $results[0]['title']);
        $this->assertSame('series', $results[0]['type']);
        $this->assertNull($results[0]['maturity']);
    }

    public function test_resolve_returns_video_id_for_exact_normalized_title_match(): void
    {
        $this->cfg();
        $this->fakeSearch([
            ['title' => 'Percy Jackson: Sea of Monsters', 'type' => 'Movie', 'id' => 70243343, 'mat' => 70],
        ]);

        $id = (new NetflixKidsClient)->resolveKidsVideoId('percy jackson sea of monsters', 'movie', 'v1');
        $this->assertSame(70243343, $id);
    }

    public function test_resolve_returns_null_when_no_title_matches(): void
    {
        $this->cfg();
        $this->fakeSearch([
            ['title' => 'Completely Different Show', 'type' => 'Show', 'id' => 111, 'mat' => 70],
        ]);

        $this->assertNull((new NetflixKidsClient)->resolveKidsVideoId('Prince', 'movie', 'v1'));
    }

    public function test_resolve_rejects_sequel_for_base_title_lookup(): void
    {
        $this->cfg();
        $this->fakeSearch([
            ['title' => 'Frozen 2', 'type' => 'Movie', 'id' => 222, 'mat' => 70],
        ]);
        // "Frozen" must NOT resolve to "Frozen 2".
        $this->assertNull((new NetflixKidsClient)->resolveKidsVideoId('Frozen', 'movie', 'v1'));
    }

    public function test_resolve_rejects_exact_title_of_wrong_type(): void
    {
        $this->cfg();
        $this->fakeSearch([
            ['title' => 'Matilda', 'type' => 'Show', 'id' => 333, 'mat' => 70],
        ]);
        // A MOVIE lookup must not return a SHOW with the same title.
        $this->assertNull((new NetflixKidsClient)->resolveKidsVideoId('Matilda', 'movie', 'v1'));
    }

    public function test_resolve_still_matches_legit_prefix_variant_same_type(): void
    {
        $this->cfg();
        $this->fakeSearch([
            ['title' => "We're Lalaloopsy", 'type' => 'Show', 'id' => 444, 'mat' => 70],
        ]);
        // want 'lalaloopsy' is a (suffix) substring of 'werelalaloopsy', same type → still matches.
        $this->assertSame(444, (new NetflixKidsClient)->resolveKidsVideoId('Lalaloopsy', 'series', 'v1'));
    }
}
