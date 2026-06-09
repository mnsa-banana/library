<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
