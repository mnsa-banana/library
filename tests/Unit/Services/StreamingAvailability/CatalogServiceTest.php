<?php

namespace Tests\Unit\Services\StreamingAvailability;

use App\Models\Report;
use App\Models\StreamingService;
use App\Models\StreamingTitle;
use App\Models\StreamingTitleOffer;
use App\Services\StreamingAvailability\CatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogServiceTest extends TestCase
{
    use RefreshDatabase;

    private function svc(string $id, string $name): StreamingService
    {
        return StreamingService::create(['id' => $id, 'name' => $name]);
    }

    private function title(array $attrs = []): StreamingTitle
    {
        return StreamingTitle::create(array_merge([
            'id' => uniqid('t_'),
            'show_type' => 'movie',
            'title' => 'Test Title',
        ], $attrs));
    }

    private function offer(StreamingTitle $t, StreamingService $s, string $type, ?string $quality, array $extra = []): StreamingTitleOffer
    {
        return StreamingTitleOffer::create(array_merge([
            'title_id' => $t->id,
            'service_id' => $s->id,
            'region' => 'US',
            'type' => $type,
            'video_quality' => $quality,
            'link' => 'https://example.com/' . $t->id,
        ], $extra));
    }

    private function report(array $attrs = []): Report
    {
        return Report::create(array_merge([
            'content_type' => 'movie',
            'title' => 'Test Report ' . uniqid(),
        ], $attrs));
    }

    public function test_returns_empty_when_no_streaming_title_matches_report(): void
    {
        $report = $this->report(['imdb_id' => 'tt9999999', 'tmdb_id' => null]);
        $result = (new CatalogService())->getStreamingOptions($report);
        $this->assertSame(['subscription'=>[], 'free'=>[], 'rent'=>[], 'buy'=>[]], $result);
    }

    public function test_finds_title_by_imdb_id(): void
    {
        $netflix = $this->svc('netflix', 'Netflix');
        $title = $this->title(['imdb_id' => 'tt12345', 'tmdb_id' => 5, 'tmdb_type' => 'movie']);
        $this->offer($title, $netflix, 'subscription', 'hd');
        $report = $this->report(['imdb_id' => 'tt12345', 'tmdb_id' => 5, 'content_type' => 'movie']);

        $result = (new CatalogService())->getStreamingOptions($report);
        $this->assertCount(1, $result['subscription']);
        $this->assertSame('Netflix', $result['subscription'][0]['name']);
    }

    public function test_falls_back_to_tmdb_id_when_imdb_misses(): void
    {
        $netflix = $this->svc('netflix', 'Netflix');
        $title = $this->title(['imdb_id' => 'tt55555', 'tmdb_id' => 42, 'tmdb_type' => 'tv']);
        $this->offer($title, $netflix, 'subscription', 'hd');
        $report = $this->report(['imdb_id' => 'tt99999', 'tmdb_id' => 42, 'content_type' => 'tv_series']);

        $result = (new CatalogService())->getStreamingOptions($report);
        $this->assertCount(1, $result['subscription']);
    }

    public function test_groups_by_type(): void
    {
        $netflix = $this->svc('netflix', 'Netflix');
        $apple = $this->svc('apple', 'Apple TV');
        $tubi = $this->svc('tubi', 'Tubi');
        $title = $this->title(['imdb_id' => 'tt1', 'tmdb_id' => 1, 'tmdb_type' => 'movie']);
        $this->offer($title, $netflix, 'subscription', 'hd');
        $this->offer($title, $apple, 'rent', 'hd', ['price_amount' => 3.99, 'price_currency' => 'USD']);
        $this->offer($title, $apple, 'buy', 'hd', ['price_amount' => 14.99, 'price_currency' => 'USD']);
        $this->offer($title, $tubi, 'free', 'sd');
        $report = $this->report(['imdb_id' => 'tt1', 'tmdb_id' => 1, 'content_type' => 'movie']);

        $result = (new CatalogService())->getStreamingOptions($report);
        $this->assertCount(1, $result['subscription']);
        $this->assertCount(1, $result['rent']);
        $this->assertCount(1, $result['buy']);
        $this->assertCount(1, $result['free']);
    }

    public function test_dedups_within_group_keeping_highest_quality(): void
    {
        $netflix = $this->svc('netflix', 'Netflix');
        $title = $this->title(['imdb_id' => 'tt1', 'tmdb_id' => 1, 'tmdb_type' => 'movie']);
        $this->offer($title, $netflix, 'subscription', 'sd');
        $this->offer($title, $netflix, 'subscription', 'hd');
        $this->offer($title, $netflix, 'subscription', 'uhd');
        $report = $this->report(['imdb_id' => 'tt1', 'tmdb_id' => 1, 'content_type' => 'movie']);

        $result = (new CatalogService())->getStreamingOptions($report);
        $this->assertCount(1, $result['subscription']);
        $this->assertSame('uhd', $result['subscription'][0]['quality']);
    }

    public function test_addon_collapses_into_subscription(): void
    {
        $hulu = $this->svc('hulu', 'Hulu');
        $title = $this->title(['imdb_id' => 'tt1', 'tmdb_id' => 1, 'tmdb_type' => 'movie']);
        $this->offer($title, $hulu, 'addon', 'hd');
        $report = $this->report(['imdb_id' => 'tt1', 'tmdb_id' => 1, 'content_type' => 'movie']);

        $result = (new CatalogService())->getStreamingOptions($report);
        $this->assertCount(1, $result['subscription']);
        $this->assertSame('addon', $result['subscription'][0]['type']);
    }

    public function test_rent_and_buy_sorted_by_price_ascending(): void
    {
        $apple = $this->svc('apple', 'Apple TV');
        $prime = $this->svc('prime', 'Prime Video');
        $title = $this->title(['imdb_id' => 'tt1', 'tmdb_id' => 1, 'tmdb_type' => 'movie']);
        $this->offer($title, $apple, 'rent', 'hd', ['price_amount' => 5.99, 'price_currency' => 'USD']);
        $this->offer($title, $prime, 'rent', 'hd', ['price_amount' => 3.99, 'price_currency' => 'USD']);
        $this->offer($title, $apple, 'buy', 'hd', ['price_amount' => 19.99, 'price_currency' => 'USD']);
        $this->offer($title, $prime, 'buy', 'hd', ['price_amount' => 14.99, 'price_currency' => 'USD']);
        $report = $this->report(['imdb_id' => 'tt1', 'tmdb_id' => 1, 'content_type' => 'movie']);

        $result = (new CatalogService())->getStreamingOptions($report);
        $this->assertSame('Prime Video', $result['rent'][0]['name']);
        $this->assertSame('Apple TV', $result['rent'][1]['name']);
        $this->assertEquals(14.99, $result['buy'][0]['price']);
        $this->assertEquals(19.99, $result['buy'][1]['price']);
    }

    public function test_only_returns_us_region_offers(): void
    {
        $netflix = $this->svc('netflix', 'Netflix');
        $title = $this->title(['imdb_id' => 'tt1', 'tmdb_id' => 1, 'tmdb_type' => 'movie']);
        $this->offer($title, $netflix, 'subscription', 'hd');
        // Non-US offer
        StreamingTitleOffer::create([
            'title_id' => $title->id, 'service_id' => 'netflix', 'region' => 'CA',
            'type' => 'subscription', 'video_quality' => 'uhd', 'link' => 'https://x',
        ]);
        $report = $this->report(['imdb_id' => 'tt1', 'tmdb_id' => 1, 'content_type' => 'movie']);

        $result = (new CatalogService())->getStreamingOptions($report);
        $this->assertCount(1, $result['subscription']);
        $this->assertSame('hd', $result['subscription'][0]['quality']);
    }
}
