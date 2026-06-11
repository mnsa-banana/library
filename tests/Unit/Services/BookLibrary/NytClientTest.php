<?php

namespace Tests\Unit\Services\BookLibrary;

use App\Services\BookLibrary\NytClient;
use App\Services\BookLibrary\NytListNotFoundException;
use App\Services\BookLibrary\NytRateLimitedException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class NytClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['services.nyt.books_key' => 'nyt-test-key']);
    }

    private function client(): NytClient
    {
        return new NytClient(delayMs: 0, backoffBaseMs: 0);
    }

    private function successBody(): array
    {
        return [
            'results' => [
                'published_date' => '2026-01-01',
                'previous_published_date' => '2025-12-25',
                'books' => [],
            ],
        ];
    }

    public function test_transient_5xx_is_retried_then_succeeds(): void
    {
        Http::fake([
            'api.nytimes.com/*' => Http::sequence()
                ->pushStatus(502)
                ->push($this->successBody()),
        ]);

        $results = $this->client()->listForDate('picture-books', '2026-01-01');

        $this->assertSame('2026-01-01', $results['published_date']);
        $this->assertCount(2, Http::recorded());
    }

    public function test_persistent_5xx_throws_runtime_exception_after_three_attempts(): void
    {
        Http::fake(['api.nytimes.com/*' => Http::response([], 503)]);

        try {
            $this->client()->listForDate('picture-books', '2026-01-01');
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('NYT request failed (503)', $e->getMessage());
        }

        $this->assertCount(3, Http::recorded());
    }

    public function test_connection_exception_is_retried_then_succeeds(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            if ($calls++ === 0) {
                throw new ConnectionException('cURL error 28: timed out');
            }

            return Http::response($this->successBody());
        });

        $results = $this->client()->listForDate('picture-books', '2026-01-01');

        $this->assertSame('2026-01-01', $results['published_date']);
        $this->assertSame(2, $calls);
    }

    public function test_429_throws_typed_exception_immediately_without_retry(): void
    {
        // The budget contract: a 429 means the day's quota is gone — callers
        // stop their run cleanly; retrying would only burn more of it.
        Http::fake(['api.nytimes.com/*' => Http::response(['fault' => 'rate limit'], 429)]);

        try {
            $this->client()->listForDate('picture-books', '2026-01-01');
            $this->fail('Expected NytRateLimitedException');
        } catch (NytRateLimitedException) {
        }

        $this->assertCount(1, Http::recorded());
    }

    public function test_404_throws_typed_list_not_found_without_retry(): void
    {
        Http::fake(['api.nytimes.com/*' => Http::response(['status' => 'ERROR', 'errors' => ['list not found']], 404)]);

        try {
            $this->client()->listForDate('chapter-books', '2026-01-01');
            $this->fail('Expected NytListNotFoundException');
        } catch (NytListNotFoundException $e) {
            $this->assertStringContainsString('NYT list not found', $e->getMessage());
        }

        $this->assertCount(1, Http::recorded());
    }

    public function test_other_4xx_fails_fast_without_retry(): void
    {
        Http::fake(['api.nytimes.com/*' => Http::response([], 403)]);

        try {
            $this->client()->listForDate('picture-books', '2026-01-01');
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('NYT request failed (403)', $e->getMessage());
        }

        $this->assertCount(1, Http::recorded());
    }
}
