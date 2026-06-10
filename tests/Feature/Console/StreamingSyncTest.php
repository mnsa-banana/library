<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StreamingSyncTest extends TestCase
{
    use RefreshDatabase;

    private function cfg(): void
    {
        config([
            'services.streaming_availability.api_key' => 'test-key',
            'services.streaming_availability.base_url' => 'https://api.streaming.test/v4',
            'services.streaming_availability.qps' => 0,
            'services.streaming_availability.timeout' => 5,
            'services.mnsa.base_url' => 'https://mnsa.test',
            'services.mnsa.service_token' => 'test-token',
        ]);
    }

    /**
     * One show whose US options span a tracked service (netflix) and an untracked
     * one (roku, The Roku Channel) — the shape the live /changes feed returns.
     */
    private function showPayload(): array
    {
        return [
            'id' => 'show-1',
            'title' => 'Some Kids Show',
            'showType' => 'series',
            'streamingOptions' => [
                'us' => [
                    [
                        'service' => ['id' => 'netflix', 'name' => 'Netflix'],
                        'type' => 'subscription',
                        'quality' => 'hd',
                        'link' => 'https://www.netflix.com/title/1',
                    ],
                    [
                        'service' => [
                            'id' => 'roku',
                            'name' => 'The Roku Channel',
                            'themeColorCode' => '#6f1ab1',
                            'imageSet' => [
                                'lightThemeImage' => 'https://logo/roku-light.png',
                                'darkThemeImage' => 'https://logo/roku-dark.png',
                            ],
                        ],
                        'type' => 'free',
                        'quality' => 'hd',
                        'link' => 'https://therokuchannel.roku.com/details/abc',
                    ],
                ],
            ],
        ];
    }

    private function fakeChanges(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/changes')) {
                parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $q);
                $isTarget = ($q['catalogs'] ?? '') === 'netflix.subscription'
                    && ($q['change_type'] ?? '') === 'new';

                return Http::response($isTarget
                    ? ['changes' => [['showId' => 'show-1']], 'shows' => ['show-1' => $this->showPayload()], 'hasMore' => false]
                    : ['changes' => [], 'shows' => [], 'hasMore' => false], 200);
            }

            // push-availability (MNSA) and anything else — benign success.
            return Http::response(['matched' => 0, 'marked_true' => 0, 'marked_false' => 0,
                'kids_marked_true' => 0, 'kids_marked_false' => 0], 200);
        });
    }

    public function test_auto_seeds_untracked_service_and_persists_its_offer(): void
    {
        $this->cfg();
        // netflix is the only pre-seeded (tracked) service, as in production.
        DB::table('streaming_services')->insert(['id' => 'netflix', 'name' => 'Netflix']);
        $this->fakeChanges();

        $this->artisan('streaming:sync', ['--hours' => 72])->assertSuccessful();

        // roku was created from the payload's service metadata...
        $roku = DB::table('streaming_services')->where('id', 'roku')->first();
        $this->assertNotNull($roku, 'roku service should be auto-seeded from the show payload');
        $this->assertSame('The Roku Channel', $roku->name);
        $this->assertSame('https://logo/roku-light.png', $roku->logo_light);

        // ...and BOTH offers persisted (no FK violation dropping the roku row).
        $this->assertDatabaseHas('streaming_title_offers',
            ['title_id' => 'show-1', 'service_id' => 'roku', 'type' => 'free']);
        $this->assertDatabaseHas('streaming_title_offers',
            ['title_id' => 'show-1', 'service_id' => 'netflix', 'type' => 'subscription']);

        // The run reports zero failures (the FK error path is gone).
        $meta = DB::table('streaming_sync_log')->where('sync_type', 'changes')
            ->orderByDesc('id')->value('metadata');
        $this->assertSame(0, json_decode($meta, true)['failed']);
    }

    public function test_clamps_far_future_expiry_sentinel_without_failing_the_change(): void
    {
        $this->cfg();
        DB::table('streaming_services')->insert(['id' => 'netflix', 'name' => 'Netflix']);

        $payload = [
            'id' => 'show-2',
            'title' => 'Perpetual Catalog Show',
            'showType' => 'series',
            'streamingOptions' => ['us' => [[
                'service' => ['id' => 'netflix', 'name' => 'Netflix'],
                'type' => 'subscription',
                'quality' => 'hd',
                'link' => 'https://www.netflix.com/title/2',
                // "Never expires" sentinel from the API — large enough to land in
                // year 10000, which PHP can format but not re-parse (the cast
                // round-trip throws "Double time specification").
                'expiresOn' => 253402318799,
            ]]],
        ];

        Http::fake(function ($request) use ($payload) {
            $url = $request->url();
            if (str_contains($url, '/changes')) {
                parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $q);
                $isTarget = ($q['catalogs'] ?? '') === 'netflix.subscription'
                    && ($q['change_type'] ?? '') === 'new';

                return Http::response($isTarget
                    ? ['changes' => [['showId' => 'show-2']], 'shows' => ['show-2' => $payload], 'hasMore' => false]
                    : ['changes' => [], 'shows' => [], 'hasMore' => false], 200);
            }

            return Http::response(['matched' => 0, 'marked_true' => 0, 'marked_false' => 0,
                'kids_marked_true' => 0, 'kids_marked_false' => 0], 200);
        });

        $this->artisan('streaming:sync', ['--hours' => 72])->assertSuccessful();

        // The far-future sentinel must not blow up the change...
        $meta = DB::table('streaming_sync_log')->where('sync_type', 'changes')
            ->orderByDesc('id')->value('metadata');
        $this->assertSame(0, json_decode($meta, true)['failed'],
            'far-future expiry sentinel must not fail the change');

        // ...and the offer must persist with a parseable, clamped expiry.
        $offer = DB::table('streaming_title_offers')
            ->where('title_id', 'show-2')->where('service_id', 'netflix')->first();
        $this->assertNotNull($offer, 'offer with sentinel expiry should persist');
        $this->assertNotNull($offer->expires_on);
        $this->assertSame(9999, Carbon::parse($offer->expires_on)->year,
            'sentinel expiry should be clamped to the max parseable year');
    }

    public function test_does_not_push_availability_itself(): void
    {
        $this->cfg();
        DB::table('streaming_services')->insert(['id' => 'netflix', 'name' => 'Netflix']);
        $this->fakeChanges();

        $this->artisan('streaming:sync', ['--hours' => 72])->assertSuccessful();

        // The MNSA push belongs to streaming:update as its final pipeline step;
        // sync pushing too would hit MNSA twice per run.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'mnsa.test'));
    }

    public function test_rejects_invalid_hours_before_doing_any_work(): void
    {
        Http::fake();

        $this->artisan('streaming:sync', ['--hours' => 'abc'])->assertExitCode(2);
        $this->artisan('streaming:sync', ['--hours' => 0])->assertExitCode(2);
        $this->artisan('streaming:sync', ['--hours' => 999999])->assertExitCode(2);

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('streaming_sync_log')->count());
    }
}
