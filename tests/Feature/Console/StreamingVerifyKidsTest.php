<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StreamingVerifyKidsTest extends TestCase
{
    use RefreshDatabase;

    private function cfg(): void
    {
        config()->set('services.netflix_kids.cookie', 'NetflixId=abc');
        config()->set('services.netflix_kids.persisted_query_id', 'pq');
        config()->set('services.netflix_kids.persisted_query_version', 102);
        config()->set('services.netflix_kids.maturity_ceiling', 70);
        config()->set('services.netflix_kids.search_delay', 0);
        config()->set('services.netflix_kids.retry_sleep_ms', 0);
    }

    private function seedTitle(string $id, string $title, string $nfid, ?int $availFromDays = null): void
    {
        DB::table('streaming_services')->updateOrInsert(['id' => 'netflix'], ['name' => 'Netflix']);
        DB::table('streaming_titles')->insert([
            'id' => $id, 'show_type' => 'movie', 'title' => $title,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('streaming_title_offers')->insert([
            'title_id' => $id, 'service_id' => 'netflix', 'region' => 'US', 'type' => 'subscription',
            'link' => "https://www.netflix.com/title/{$nfid}/",
            'available_from' => $availFromDays === null ? null : now()->addDays($availFromDays),
            'updated_at' => now(),
        ]);
    }

    private function fakeNetflix(string $kidsHtml, array $maturity, array $searchByTerm): void
    {
        Http::fake([
            'www.netflix.com/Kids' => Http::response($kidsHtml, 200),
            '*pathEvaluator*' => Http::response(['value' => ['videos' => $maturity]], 200),
            'web.prod.cloud.netflix.com/graphql' => function ($request) use ($searchByTerm) {
                $term = json_decode($request->body(), true)['variables']['searchTerm'] ?? '';
                $ids = $searchByTerm[$term] ?? [];
                $nodes = array_map(fn ($i) => '{"node":{"videoId":'.$i.'}}', $ids);

                return Http::response('{"data":{"search":{"edges":['.implode(',', $nodes).']}}}', 200);
            },
        ]);
    }

    private function goodKidsHtml(): string
    {
        return '<body data-uia="container-kids">"currentCountry":"US",'
            .'"authURL":"auth","apiUrl":"https:\x2F\x2Fwww.netflix.com\x2Fapi\x2Fshakti\x2Fmre",'
            .'"BUILD_IDENTIFIER":"v6c030968"</body>';
    }

    private function anchorMaturity(): array
    {
        return [
            '81009946' => ['maturity' => ['rating' => ['maturityLevel' => 41]]],
            '81474560' => ['maturity' => ['rating' => ['maturityLevel' => 50]]],
            '70153373' => ['maturity' => ['rating' => ['maturityLevel' => 70]]],
        ];
    }

    private function anchorSearch(): array
    {
        return [
            "gabby's dollhouse" => [81009946],
            'storybots' => [81474560],
            'seinfeld' => [],   // control: not in kids
        ];
    }

    public function test_marks_surfaced_true_false_and_prunes_above_ceiling(): void
    {
        $this->cfg();
        $this->seedTitle('t-lbt', 'Land Before Time', '683101');
        $this->seedTitle('t-sei', 'Seinfeld', '70153373');     // playable, maturity 70, not surfaced
        $this->seedTitle('t-bb', 'Breaking Bad', '70143836');  // maturity 110 -> pruned to false

        $this->fakeNetflix(
            $this->goodKidsHtml(),
            $this->anchorMaturity() + [
                '683101' => ['maturity' => ['rating' => ['maturityLevel' => 50]]],
                '70153373' => ['maturity' => ['rating' => ['maturityLevel' => 70]]],
                '70143836' => ['maturity' => ['rating' => ['maturityLevel' => 110]]],
            ],
            $this->anchorSearch() + [
                'Land Before Time' => [683101],
                'Seinfeld' => [],
            ],
        );

        $this->artisan('streaming:verify-kids')->assertSuccessful();

        $this->assertTrue((bool) DB::table('streaming_titles')->where('id', 't-lbt')->value('netflix_kids_surfaced'));
        $this->assertFalse((bool) DB::table('streaming_titles')->where('id', 't-sei')->value('netflix_kids_surfaced'));
        $this->assertFalse((bool) DB::table('streaming_titles')->where('id', 't-bb')->value('netflix_kids_surfaced'));
        $this->assertNotNull(DB::table('streaming_titles')->where('id', 't-lbt')->value('netflix_kids_checked_at'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'graphql')
            && str_contains($r->body(), '"searchTerm":"Breaking Bad"'));
    }

    public function test_aborts_without_writes_on_non_us_session(): void
    {
        $this->cfg();
        $this->seedTitle('t-lbt', 'Land Before Time', '683101');
        $caHtml = str_replace('"currentCountry":"US"', '"currentCountry":"CA"', $this->goodKidsHtml());
        $this->fakeNetflix($caHtml, $this->anchorMaturity(), $this->anchorSearch());

        $this->artisan('streaming:verify-kids')->assertFailed();

        $this->assertNull(DB::table('streaming_titles')->where('id', 't-lbt')->value('netflix_kids_checked_at'));
    }

    public function test_skips_upcoming_offers_leaving_them_null(): void
    {
        $this->cfg();
        $this->seedTitle('t-up', 'Future Toon', '888', availFromDays: 30); // upcoming
        $this->fakeNetflix($this->goodKidsHtml(), $this->anchorMaturity(), $this->anchorSearch());

        $this->artisan('streaming:verify-kids')->assertSuccessful();

        $this->assertNull(DB::table('streaming_titles')->where('id', 't-up')->value('netflix_kids_checked_at'));
    }

    public function test_resets_previously_verified_title_when_no_playable_offer(): void
    {
        $this->cfg();
        $this->seedTitle('t-orphan', 'Gone Kids Show', '424242', availFromDays: 30); // only offer is upcoming
        DB::table('streaming_titles')->where('id', 't-orphan')
            ->update(['netflix_kids_surfaced' => true, 'netflix_kids_checked_at' => now()->subDays(1)]);
        $this->fakeNetflix($this->goodKidsHtml(), $this->anchorMaturity(), $this->anchorSearch());

        $this->artisan('streaming:verify-kids')->assertSuccessful();

        $this->assertNull(DB::table('streaming_titles')->where('id', 't-orphan')->value('netflix_kids_surfaced'));
        $this->assertNull(DB::table('streaming_titles')->where('id', 't-orphan')->value('netflix_kids_checked_at'));
    }

    public function test_resumes_skipping_recently_checked(): void
    {
        $this->cfg();
        $this->seedTitle('t-done', 'Already Done', '683101');
        DB::table('streaming_titles')->where('id', 't-done')
            ->update(['netflix_kids_surfaced' => true, 'netflix_kids_checked_at' => now()]);
        $this->fakeNetflix($this->goodKidsHtml(), $this->anchorMaturity(), $this->anchorSearch());

        // default floor = now - default_stale_days; a title checked just now is skipped.
        $this->artisan('streaming:verify-kids')->assertSuccessful();
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'graphql')
            && str_contains($r->body(), '"searchTerm":"Already Done"'));
    }

    public function test_skips_title_whose_search_persistently_fails(): void
    {
        $this->cfg();
        $this->seedTitle('t-ok', 'Good Toon', '555');
        $this->seedTitle('t-bad', 'Broken Toon', '666');
        Http::fake([
            'www.netflix.com/Kids' => Http::response($this->goodKidsHtml(), 200),
            '*pathEvaluator*' => Http::response(['value' => ['videos' => [
                '81009946' => ['maturity' => ['rating' => ['maturityLevel' => 41]]],
                '81474560' => ['maturity' => ['rating' => ['maturityLevel' => 50]]],
                '70153373' => ['maturity' => ['rating' => ['maturityLevel' => 70]]],
                '555' => ['maturity' => ['rating' => ['maturityLevel' => 41]]],
                '666' => ['maturity' => ['rating' => ['maturityLevel' => 41]]],
            ]]], 200),
            'web.prod.cloud.netflix.com/graphql' => function ($request) {
                $term = json_decode($request->body(), true)['variables']['searchTerm'] ?? '';
                if ($term === 'Broken Toon') {
                    throw new ConnectionException('TLS eof');
                }
                $ids = ["gabby's dollhouse" => [81009946], 'storybots' => [81474560],
                    'seinfeld' => [], 'Good Toon' => [555]];
                $nodes = array_map(fn ($i) => '{"node":{"videoId":'.$i.'}}', $ids[$term] ?? []);

                return Http::response('{"data":{"search":{"edges":['.implode(',', $nodes).']}}}', 200);
            },
        ]);

        $this->artisan('streaming:verify-kids')->assertSuccessful();

        // sibling processed, surfaced
        $this->assertTrue((bool) DB::table('streaming_titles')->where('id', 't-ok')->value('netflix_kids_surfaced'));
        // failing title left unchecked for a later run
        $this->assertNull(DB::table('streaming_titles')->where('id', 't-bad')->value('netflix_kids_checked_at'));
    }

    public function test_leaves_null_when_maturity_unknown(): void
    {
        $this->cfg();
        $this->seedTitle('t-unk', 'Unknown Maturity', '999'); // 999 not present in maturity fake
        $this->fakeNetflix($this->goodKidsHtml(), $this->anchorMaturity(), $this->anchorSearch());

        $this->artisan('streaming:verify-kids')->assertSuccessful();

        // unknown maturity -> surfaced stays null (heuristic-eligible) and is NEVER searched,
        // but checked_at IS stamped so the title converges (skipped until the stale window).
        $this->assertNull(DB::table('streaming_titles')->where('id', 't-unk')->value('netflix_kids_surfaced'));
        $this->assertNotNull(DB::table('streaming_titles')->where('id', 't-unk')->value('netflix_kids_checked_at'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'graphql')
            && str_contains($r->body(), '"searchTerm":"Unknown Maturity"'));
    }

    public function test_aborts_when_maturity_endpoint_returns_no_anchor_data(): void
    {
        $this->cfg();
        $this->seedTitle('t-x', 'Some Kid Show', '555');
        // search anchors pass, but the maturity endpoint returns nothing for the anchors
        $this->fakeNetflix($this->goodKidsHtml(), [], $this->anchorSearch());

        $this->artisan('streaming:verify-kids')->assertFailed();

        $this->assertNull(DB::table('streaming_titles')->where('id', 't-x')->value('netflix_kids_checked_at'));
    }

    public function test_excludes_expired_offer(): void
    {
        $this->cfg();
        DB::table('streaming_services')->updateOrInsert(['id' => 'netflix'], ['name' => 'Netflix']);
        DB::table('streaming_titles')->insert([
            'id' => 't-exp', 'show_type' => 'movie', 'title' => 'Expired Toon',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('streaming_title_offers')->insert([
            'title_id' => 't-exp', 'service_id' => 'netflix', 'region' => 'US', 'type' => 'subscription',
            'link' => 'https://www.netflix.com/title/333/',
            'available_from' => null, 'expires_on' => now()->subDay(), 'updated_at' => now(),
        ]);
        $this->fakeNetflix($this->goodKidsHtml(), $this->anchorMaturity(), $this->anchorSearch());

        $this->artisan('streaming:verify-kids')->assertSuccessful();

        // offer already expired -> not a candidate -> never verified
        $this->assertNull(DB::table('streaming_titles')->where('id', 't-exp')->value('netflix_kids_checked_at'));
    }
}
