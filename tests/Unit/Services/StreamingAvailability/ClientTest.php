<?php

namespace Tests\Unit\Services\StreamingAvailability;

use App\Models\StreamingSyncLog;
use App\Services\StreamingAvailability\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class ClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.streaming_availability.api_key' => 'test-key']);
        config(['services.streaming_availability.base_url' => 'https://api.example.test/v4']);
        config(['services.streaming_availability.qps' => 1000]); // disable throttle in unit tests
    }

    public function test_get_returns_decoded_json_on_success(): void
    {
        Http::fake([
            'api.example.test/v4/countries/us' => Http::response(['countryCode' => 'us', 'name' => 'United States'], 200),
        ]);

        $result = (new Client)->get('/countries/us');

        $this->assertSame('us', $result['countryCode']);
        Http::assertSent(fn ($r) => $r->hasHeader('x-api-key', 'test-key'));
    }

    public function test_get_passes_query_params(): void
    {
        Http::fake([
            'api.example.test/v4/shows/search/filters*' => Http::response(['shows' => [], 'hasMore' => false], 200),
        ]);

        (new Client)->get('/shows/search/filters', ['country' => 'us', 'catalogs' => 'netflix.subscription']);

        Http::assertSent(fn ($r) => $r->url() === 'https://api.example.test/v4/shows/search/filters?country=us&catalogs=netflix.subscription'
        );
    }

    public function test_get_retries_on_429_then_succeeds(): void
    {
        Http::fake([
            'api.example.test/v4/x' => Http::sequence()
                ->push('rate limit', 429)
                ->push(['ok' => true], 200),
        ]);

        $result = (new Client)->get('/x');
        $this->assertTrue($result['ok']);
    }

    public function test_get_retries_on_5xx_then_succeeds(): void
    {
        Http::fake([
            'api.example.test/v4/x' => Http::sequence()
                ->push('upstream', 502)
                ->push('upstream', 503)
                ->push(['ok' => true], 200),
        ]);

        $result = (new Client)->get('/x');
        $this->assertTrue($result['ok']);
    }

    public function test_get_throws_after_max_retries_exhausted(): void
    {
        Http::fake([
            'api.example.test/v4/x' => Http::response('still down', 503),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Streaming Availability API error 503/');
        (new Client)->get('/x');
    }

    public function test_get_retries_on_connection_exception_then_succeeds(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                throw new ConnectionException('cURL error 28: Operation timed out after 30002 milliseconds with 0 bytes received');
            }

            return Http::response(['ok' => true], 200);
        });

        $result = (new Client)->get('/x');

        $this->assertTrue($result['ok']);
        $this->assertSame(2, $calls);
    }

    public function test_get_throws_after_connection_exception_retries_exhausted(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        try {
            (new Client)->get('/x');
            $this->fail('Expected RuntimeException after exhausting connection retries');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('connection failed', $e->getMessage());
        }

        $this->assertSame(3, $calls);
    }

    public function test_get_does_not_retry_on_4xx_other_than_429(): void
    {
        Http::fake([
            'api.example.test/v4/x' => Http::response('bad request', 400),
        ]);

        $this->expectException(RuntimeException::class);
        (new Client)->get('/x');
        Http::assertSentCount(1);
    }

    public function test_get_increments_sync_log_api_calls(): void
    {
        Http::fake([
            'api.example.test/v4/x' => Http::response(['ok' => true], 200),
        ]);

        $log = StreamingSyncLog::create(['sync_type' => 'changes', 'status' => 'running']);
        $client = new Client($log);
        $client->get('/x');
        $client->get('/x');

        $this->assertSame(2, $log->fresh()->api_calls_used);
    }

    public function test_get_throws_when_api_key_missing(): void
    {
        config(['services.streaming_availability.api_key' => null]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/STREAMING_AVAILABILITY_API_KEY/');
        new Client;
    }
}
