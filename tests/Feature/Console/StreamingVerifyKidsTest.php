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
            '*pathEvaluator*' => Http::response(['jsonGraph' => ['videos' => $maturity]], 200),
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
            .'"memberapi":{"protocol":"https","hostname":"www.netflix.com",'
            .'"path":["/nq/website/memberapi/release"],"isNodequark":true},'
            .'"BUILD_IDENTIFIER":"v6c030968"</body>';
    }

    /** jsonGraph atom shape returned by the member API pathEvaluator. */
    private static function maturityAtom(int $level): array
    {
        return ['maturity' => ['$type' => 'atom', 'value' => ['rating' => ['maturityLevel' => $level]]]];
    }

    private function anchorMaturity(): array
    {
        return [
            '81009946' => self::maturityAtom(41),
            '81474560' => self::maturityAtom(50),
            '70153373' => self::maturityAtom(70),
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
                '683101' => self::maturityAtom(50),
                '70153373' => self::maturityAtom(70),
                '70143836' => self::maturityAtom(110),
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
            '*pathEvaluator*' => Http::response(['jsonGraph' => ['videos' => [
                '81009946' => self::maturityAtom(41),
                '81474560' => self::maturityAtom(50),
                '70153373' => self::maturityAtom(70),
                '555' => self::maturityAtom(41),
                '666' => self::maturityAtom(41),
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

    public function test_writes_completed_verify_kids_log_row_with_counts(): void
    {
        $this->cfg();
        // One real candidate beyond the anchors: surfaced (maturity under ceiling + search hit).
        $this->seedTitle('t-pingu', 'Pingu', '12345');
        $maturity = $this->anchorMaturity() + ['12345' => self::maturityAtom(40)];
        $this->fakeNetflix($this->goodKidsHtml(), $maturity, [
            "gabby's dollhouse" => [81009946],
            'storybots' => [81474560],
            'seinfeld' => [],
            'Pingu' => [12345],
        ]);

        $this->artisan('streaming:verify-kids', ['--force' => true])->assertExitCode(0);

        $row = DB::table('streaming_sync_log')->where('sync_type', 'verify_kids')->latest('id')->first();
        $this->assertNotNull($row, 'verify_kids row should be written');
        $this->assertSame('completed', $row->status);
        $this->assertNotNull($row->completed_at);
        $meta = json_decode($row->metadata, true);
        $this->assertSame(1, $meta['candidates']);   // anchors are gate-only, not candidates
        $this->assertSame(1, $meta['surfaced']);
        $this->assertSame(0, $meta['pruned']);
    }

    public function test_writes_failed_verify_kids_row_when_session_gate_aborts(): void
    {
        $this->cfg();
        $this->seedTitle('t-pingu', 'Pingu', '12345');
        // Anchors NOT surfaced in search → gateSession aborts before any candidate work.
        $this->fakeNetflix($this->goodKidsHtml(), $this->anchorMaturity(), [
            "gabby's dollhouse" => [],
            'storybots' => [],
            'seinfeld' => [],
        ]);

        $this->artisan('streaming:verify-kids', ['--force' => true])->assertExitCode(1);

        $row = DB::table('streaming_sync_log')->where('sync_type', 'verify_kids')->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertSame('failed', $row->status);
        $this->assertNotNull($row->error_message);
        // The specific abort reason (not a generic fallback) must be persisted.
        $this->assertStringContainsString('not surfaced', $row->error_message);
    }

    public function test_deletes_discovery_offer_when_title_no_longer_surfaces_but_keeps_motn(): void
    {
        $this->cfg();
        // A title with a Netflix offer whose maturity is under the ceiling but which
        // does NOT surface in the Kids search → verify-kids marks it not-surfaced.
        $this->seedTitle('t-disc', 'Vanished Kids Show', '55555');
        DB::table('streaming_title_offers')->where('title_id', 't-disc')
            ->where('service_id', 'netflix')->update(['source' => 'discovery']);

        $maturity = $this->anchorMaturity() + ['55555' => self::maturityAtom(40)]; // under ceiling
        $this->fakeNetflix($this->goodKidsHtml(), $maturity, [
            "gabby's dollhouse" => [81009946],
            'storybots' => [81474560],
            'seinfeld' => [],
            'Vanished Kids Show' => [], // does NOT surface
        ]);

        $this->artisan('streaming:verify-kids', ['--force' => true])->assertExitCode(0);

        $this->assertDatabaseMissing('streaming_title_offers',
            ['title_id' => 't-disc', 'service_id' => 'netflix']);
        $this->assertSame(false, (bool) DB::table('streaming_titles')->where('id', 't-disc')->value('netflix_kids_surfaced'));
    }

    public function test_keeps_discovery_offer_when_title_still_surfaces(): void
    {
        $this->cfg();
        $this->seedTitle('t-live', 'Still On Kids', '66666');
        DB::table('streaming_title_offers')->where('title_id', 't-live')
            ->where('service_id', 'netflix')->update(['source' => 'discovery']);

        $maturity = $this->anchorMaturity() + ['66666' => self::maturityAtom(40)];
        $this->fakeNetflix($this->goodKidsHtml(), $maturity, [
            "gabby's dollhouse" => [81009946],
            'storybots' => [81474560],
            'seinfeld' => [],
            'Still On Kids' => [66666], // surfaces
        ]);

        $this->artisan('streaming:verify-kids', ['--force' => true])->assertExitCode(0);

        $this->assertDatabaseHas('streaming_title_offers',
            ['title_id' => 't-live', 'service_id' => 'netflix', 'source' => 'discovery']);
        $this->assertSame(true, (bool) DB::table('streaming_titles')->where('id', 't-live')->value('netflix_kids_surfaced'));
    }

    public function test_writes_failed_row_and_rethrows_on_uncaught_exception(): void
    {
        // Covers the outer catch (\Throwable $e) in handle() — the branch that writes
        // status='failed' then re-throws.  gateSession() has its own inner try/catch so
        // client exceptions can't bubble out of it; resetOrphans() is the first post-gate
        // call with NO local catch, making it the cleanest trigger.
        // We pass the session gate via Http::fake (purely HTTP), then drop
        // streaming_title_offers so the DB::table() call inside resetOrphans() throws a
        // QueryException that reaches the outer catch.
        $this->cfg();
        $this->fakeNetflix($this->goodKidsHtml(), $this->anchorMaturity(), $this->anchorSearch());

        // Drop the table AFTER Http::fake — the gate makes only HTTP calls and still passes,
        // but resetOrphans()'s DB query immediately fails with a QueryException.
        DB::statement('DROP TABLE IF EXISTS streaming_title_offers');

        // The re-thrown exception propagates through PendingCommand all the way to PHPUnit,
        // so we must catch it here.  The outer catch in handle() writes the failed log row
        // BEFORE re-throwing, so the row is visible even though the command never returned.
        $thrown = null;
        try {
            $this->artisan('streaming:verify-kids', ['--force' => true]);
        } catch (\Throwable $e) {
            $thrown = $e;
        }
        $this->assertNotNull($thrown, 'expected the re-thrown exception to reach PHPUnit');

        $row = DB::table('streaming_sync_log')->where('sync_type', 'verify_kids')->latest('id')->first();
        $this->assertNotNull($row, 'a verify_kids log row must be written even on uncaught exception');
        $this->assertSame('failed', $row->status);
        $this->assertNotNull($row->error_message, 'error_message must capture the exception message');
    }
}
