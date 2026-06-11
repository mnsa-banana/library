<?php

namespace Tests\Unit\Services\BookLibrary;

use App\Services\BookLibrary\OpenLibraryClient;
use App\Services\BookLibrary\OpenLibraryRateLimitedException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class OpenLibraryClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_resolve_isbn_parses_work_key_isbns_and_cover(): void
    {
        Http::fake([
            'openlibrary.org/isbn/9780064404990.json' => Http::response([
                'works' => [['key' => '/works/OL45804W']],
                'isbn_13' => ['978-0-06-440499-0'],
                'isbn_10' => ['0064404993'],
                'covers' => [8231856],
            ]),
        ]);

        $result = (new OpenLibraryClient)->resolveIsbn('9780064404990');

        $this->assertSame('OL45804W', $result['work_key']);
        // isbn_13 and isbn_10 normalize to the same ISBN-13: deduped.
        $this->assertSame(['9780064404990'], $result['isbn13s']);
        $this->assertSame('https://covers.openlibrary.org/b/id/8231856-L.jpg', $result['cover_url']);
    }

    public function test_resolve_isbn_returns_null_on_404(): void
    {
        Http::fake(['openlibrary.org/isbn/*' => Http::response(null, 404)]);

        $this->assertNull((new OpenLibraryClient)->resolveIsbn('9780000000002'));
    }

    public function test_resolve_isbn_returns_null_when_edition_has_no_work(): void
    {
        Http::fake([
            'openlibrary.org/isbn/*' => Http::response(['isbn_13' => ['9780064404990']]),
        ]);

        $this->assertNull((new OpenLibraryClient)->resolveIsbn('9780064404990'));
    }

    public function test_resolve_isbn_retries_429_then_succeeds(): void
    {
        Http::fake([
            'openlibrary.org/isbn/*' => Http::sequence()
                ->push('rate limited', 429)
                ->push([
                    'works' => [['key' => '/works/OL45804W']],
                    'isbn_13' => ['9780064404990'],
                ]),
        ]);

        $result = (new OpenLibraryClient(backoffBaseMs: 0))->resolveIsbn('9780064404990');

        $this->assertSame('OL45804W', $result['work_key']);
        Http::assertSentCount(2);
    }

    public function test_resolve_isbn_throws_typed_rate_limit_exception_after_persistent_429(): void
    {
        Http::fake(['openlibrary.org/isbn/*' => Http::response('rate limited', 429)]);

        try {
            (new OpenLibraryClient(backoffBaseMs: 0))->resolveIsbn('9780064404990');
            $this->fail('Expected OpenLibraryRateLimitedException after exhausting retries');
        } catch (OpenLibraryRateLimitedException $e) {
            $this->assertStringContainsString('429', $e->getMessage());
        }

        Http::assertSentCount(3);
    }

    public function test_resolve_isbn_throws_after_persistent_server_errors(): void
    {
        Http::fake(['openlibrary.org/isbn/*' => Http::response('upstream error', 503)]);

        try {
            (new OpenLibraryClient(backoffBaseMs: 0))->resolveIsbn('9780064404990');
            $this->fail('Expected RuntimeException after exhausting retries');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('503', $e->getMessage());
        }

        Http::assertSentCount(3);
    }

    public function test_resolve_isbn_retries_connection_exception_then_succeeds(): void
    {
        Http::fake([
            'openlibrary.org/isbn/*' => Http::sequence()
                ->pushFailedConnection('cURL error 28: timed out')
                ->push([
                    'works' => [['key' => '/works/OL45804W']],
                    'isbn_13' => ['9780064404990'],
                ]),
        ]);

        $result = (new OpenLibraryClient(backoffBaseMs: 0))->resolveIsbn('9780064404990');

        $this->assertSame('OL45804W', $result['work_key']);
        Http::assertSentCount(2);
    }

    public function test_resolve_isbn_throws_after_persistent_connection_failures(): void
    {
        Http::fake([
            'openlibrary.org/isbn/*' => Http::sequence()
                ->pushFailedConnection('cURL error 28: timed out')
                ->pushFailedConnection('cURL error 28: timed out')
                ->pushFailedConnection('cURL error 28: timed out'),
        ]);

        try {
            (new OpenLibraryClient(backoffBaseMs: 0))->resolveIsbn('9780064404990');
            $this->fail('Expected RuntimeException after exhausting retries');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('connection failed', $e->getMessage());
        }

        Http::assertSentCount(3);
    }

    public function test_resolve_isbn_fails_fast_on_non_retryable_4xx(): void
    {
        Http::fake(['openlibrary.org/isbn/*' => Http::response('bad request', 400)]);

        try {
            (new OpenLibraryClient(backoffBaseMs: 0))->resolveIsbn('9780064404990');
            $this->fail('Expected RuntimeException on 400');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('400', $e->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_work_editions_paginates_and_collects_isbns_and_cover(): void
    {
        Http::fake([
            'openlibrary.org/works/OL45804W/editions.json?offset=50' => Http::response([
                'entries' => [
                    ['isbn_13' => ['9780060234812'], 'covers' => [55]],
                ],
            ]),
            'openlibrary.org/works/OL45804W/editions.json' => Http::response([
                'links' => ['next' => '/works/OL45804W/editions.json?offset=50'],
                'entries' => [
                    // -1 is Open Library's "no cover" sentinel: must be skipped.
                    ['isbn_13' => ['978-0-06-440499-0'], 'covers' => [-1]],
                    ['isbn_10' => ['080442957X']],
                ],
            ]),
        ]);

        $result = (new OpenLibraryClient)->workEditions('OL45804W');

        $this->assertEqualsCanonicalizing(
            ['9780064404990', '9780804429573', '9780060234812'],
            $result['isbn13s']
        );
        $this->assertSame('https://covers.openlibrary.org/b/id/55-L.jpg', $result['cover_url']);
        Http::assertSentCount(2);
    }

    public function test_work_editions_respects_max_pages(): void
    {
        Http::fake([
            'openlibrary.org/works/OL45804W/editions.json' => Http::response([
                'links' => ['next' => '/works/OL45804W/editions.json?offset=50'],
                'entries' => [['isbn_13' => ['9780064404990']]],
            ]),
        ]);

        $result = (new OpenLibraryClient)->workEditions('OL45804W', maxPages: 1);

        $this->assertSame(['9780064404990'], $result['isbn13s']);
        $this->assertNull($result['cover_url']);
        Http::assertSentCount(1);
    }

    public function test_work_editions_stops_when_no_next_link(): void
    {
        Http::fake([
            'openlibrary.org/works/OL45804W/editions.json' => Http::response([
                'entries' => [['isbn_13' => ['9780064404990']]],
            ]),
        ]);

        $result = (new OpenLibraryClient)->workEditions('OL45804W');

        $this->assertSame(['9780064404990'], $result['isbn13s']);
        Http::assertSentCount(1);
    }
}
