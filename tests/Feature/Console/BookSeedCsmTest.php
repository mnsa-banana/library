<?php

namespace Tests\Feature\Console;

use App\Models\BookLibraryTitle;
use App\Models\BookListMembership;
use App\Models\BookSyncLog;
use App\Services\BookLibrary\CsmIndexScraper;
use App\Services\BookLibrary\SyncRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BookSeedCsmTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://www.commonsensemedia.org';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        // Politeness delay injected to 0 — the suite must never sleep 1s/page.
        $this->app->instance(CsmIndexScraper::class, new CsmIndexScraper(delayMs: 0));
    }

    private function fixture(string $name): string
    {
        return file_get_contents(base_path("tests/fixtures/book_library/{$name}"));
    }

    /** Minimal CSM review page: one schema.org Review JSON-LD block + og:title. */
    private function reviewHtml(string $title, string $author, string $isbn, string $ageRange): string
    {
        $json = json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'Review',
            'itemReviewed' => [
                '@type' => 'Book',
                'name' => $title,
                'author' => ['@type' => 'Person', 'name' => $author],
                'isbn' => $isbn,
                'typicalAgeRange' => $ageRange,
            ],
        ], JSON_UNESCAPED_SLASHES);

        return '<!DOCTYPE html><html><head>'
            ."<meta property=\"og:title\" content=\"{$title} Book Review | Common Sense Media\" />"
            ."<script type=\"application/ld+json\">{$json}</script>"
            .'</head><body></body></html>';
    }

    /**
     * Fixture walk: sitemap.xml (sitemapindex) → reviews/sitemap.xml (NESTED
     * sitemapindex) → reviews/sitemap.xml?page=1 (urlset, mixed sections) →
     * three /book-reviews/ pages.
     *
     * @param  array<string, mixed>  $overrides  pattern => response, matched before the defaults
     */
    private function fakeCsm(array $overrides = []): void
    {
        Http::fake($overrides + [
            self::BASE.'/sitemap.xml' => Http::response($this->fixture('csm_sitemap_index.xml')),
            self::BASE.'/reviews/sitemap.xml?page=1' => Http::response($this->fixture('csm_reviews_page1.xml')),
            self::BASE.'/reviews/sitemap.xml' => Http::response($this->fixture('csm_reviews_sitemapindex.xml')),
            self::BASE.'/book-reviews/a-wrinkle-in-time' => Http::response(
                $this->reviewHtml('A Wrinkle in Time', "Madeleine L'Engle", '978-0-312-36754-1', '10+')
            ),
            self::BASE.'/book-reviews/charlottes-web' => Http::response($this->fixture('csm_review_page.html')),
            self::BASE.'/book-reviews/the-wild-robot' => Http::response(
                $this->reviewHtml('The Wild Robot', 'Peter Brown', '978-0-316-38199-4', '8+')
            ),
            'openlibrary.org/*' => Http::response(['error' => 'notfound'], 404),
        ]);
    }

    /** @return array<string> CSM request paths (+query), in call order (Open Library noise excluded) */
    private function csmPaths(): array
    {
        return collect(Http::recorded())
            ->map(fn (array $pair) => $pair[0]->url())
            ->filter(fn (string $url) => str_contains($url, 'commonsensemedia.org'))
            ->map(fn (string $url) => substr($url, strlen(self::BASE)))
            ->values()
            ->all();
    }

    public function test_two_level_walk_returns_sorted_unique_book_review_urls(): void
    {
        $this->fakeCsm();

        $urls = (new CsmIndexScraper(delayMs: 0))->slugUrls();

        // Only /book-reviews/ survive the filter; duplicates collapse; sorted.
        $this->assertSame([
            self::BASE.'/book-reviews/a-wrinkle-in-time',
            self::BASE.'/book-reviews/charlottes-web',
            self::BASE.'/book-reviews/the-wild-robot',
        ], $urls);

        // Robots compliance: only the reviews section index and its
        // /reviews/sitemap.xml?page=N children are fetched — never the other
        // section sitemaps, never any listing `?page=` URL.
        $this->assertSame([
            '/sitemap.xml',
            '/reviews/sitemap.xml',
            '/reviews/sitemap.xml?page=1',
        ], $this->csmPaths());

        // UA regression insurance: CSM blocks AI-labeled agents site-wide —
        // every request must carry a plain generic-browser UA.
        $plainUa = fn (Request $request) => str_starts_with((string) ($request->header('User-Agent')[0] ?? ''), 'Mozilla/5.0');
        Http::assertSent($plainUa);
        Http::assertNotSent(fn (Request $request) => ! $plainUa($request));
    }

    public function test_review_page_meta_parses_json_ld_with_normalized_isbn_and_age(): void
    {
        Http::fake([
            self::BASE.'/book-reviews/charlottes-web' => Http::response($this->fixture('csm_review_page.html')),
        ]);

        $meta = (new CsmIndexScraper(delayMs: 0))->reviewPageMeta(self::BASE.'/book-reviews/charlottes-web');

        // Hyphenated single-edition ISBN normalized to digits-only; "7+" → 7.
        $this->assertSame([
            'title' => "Charlotte's Web",
            'author' => 'E. B. White',
            'min_age' => 7,
            'isbn13s' => ['9780060263850'],
        ], $meta);
    }

    public function test_review_page_meta_falls_back_to_og_title_when_json_ld_missing(): void
    {
        Http::fake([
            self::BASE.'/book-reviews/some-picture-book' => Http::response(
                '<html><head><meta property="og:title" content="Some Picture Book Book Review | Common Sense Media" /></head><body></body></html>'
            ),
        ]);

        $meta = (new CsmIndexScraper(delayMs: 0))->reviewPageMeta(self::BASE.'/book-reviews/some-picture-book');

        $this->assertSame([
            'title' => 'Some Picture Book',
            'author' => null,
            'min_age' => null,
            'isbn13s' => [],
        ], $meta);
    }

    public function test_review_page_meta_returns_null_on_non_200_and_on_title_less_pages(): void
    {
        Http::fake([
            self::BASE.'/book-reviews/gone' => Http::response('', 500),
            self::BASE.'/book-reviews/bare' => Http::response('<html><head></head><body></body></html>'),
        ]);

        $scraper = new CsmIndexScraper(delayMs: 0);

        $this->assertNull($scraper->reviewPageMeta(self::BASE.'/book-reviews/gone'));
        $this->assertNull($scraper->reviewPageMeta(self::BASE.'/book-reviews/bare'));
    }

    public function test_seed_creates_work_rows_each_carrying_review_url(): void
    {
        $this->fakeCsm();

        $this->artisan('book:seed', ['--source' => 'csm'])->assertExitCode(0);

        $this->assertSame(3, BookLibraryTitle::count());

        $web = BookLibraryTitle::where('title', "Charlotte's Web")->sole();
        $this->assertSame('E. B. White', $web->author);
        $this->assertSame(['9780060263850'], $web->isbn13s);
        $this->assertSame(7, $web->min_age);
        $this->assertSame('csm_index', $web->min_age_source);

        $membership = $web->memberships()->sole();
        $this->assertSame('csm_index', $membership->list_source);
        $this->assertSame('index', $membership->list_key);
        $this->assertSame(self::BASE.'/book-reviews/charlottes-web', $membership->review_url);

        // Spec verification item 1: every seeded membership carries its
        // review page URL.
        $this->assertSame(3, BookListMembership::whereNotNull('review_url')->count());

        $log = BookSyncLog::sole();
        $this->assertSame('seed_csm', $log->sync_type);
        $this->assertSame('completed', $log->status);
        $this->assertSame(3, $log->api_calls_used);
        $this->assertSame(3, $log->titles_processed);
        $this->assertTrue($log->metadata['exhausted']);
        $this->assertSame(self::BASE.'/book-reviews/the-wild-robot', $log->last_cursor);
    }

    public function test_limit_stops_after_n_pages_with_cursor_at_last_processed_url(): void
    {
        $this->fakeCsm();

        $this->artisan('book:seed', ['--source' => 'csm', '--limit' => 2])->assertExitCode(0);

        $this->assertSame(2, BookLibraryTitle::count());
        $this->assertNotContains('/book-reviews/the-wild-robot', $this->csmPaths());

        $log = BookSyncLog::sole();
        $this->assertSame('completed', $log->status);
        $this->assertFalse($log->metadata['exhausted']);
        $this->assertSame(2, $log->api_calls_used);
        $this->assertSame(self::BASE.'/book-reviews/charlottes-web', $log->last_cursor);
    }

    public function test_resume_skips_urls_at_or_before_the_cursor(): void
    {
        $prior = SyncRun::start('seed_csm');
        $prior->cursor(self::BASE.'/book-reviews/charlottes-web');
        $prior->fail('interrupted');

        $this->fakeCsm();

        $this->artisan('book:seed', ['--source' => 'csm', '--resume' => true])->assertExitCode(0);

        // The walk re-runs (cheap), but only the URL after the cursor is fetched.
        $this->assertSame([
            '/sitemap.xml',
            '/reviews/sitemap.xml',
            '/reviews/sitemap.xml?page=1',
            '/book-reviews/the-wild-robot',
        ], $this->csmPaths());

        $this->assertSame(1, BookLibraryTitle::count());

        $log = BookSyncLog::orderByDesc('id')->first();
        $this->assertSame('completed', $log->status);
        $this->assertTrue($log->metadata['exhausted']);
        $this->assertSame(self::BASE.'/book-reviews/the-wild-robot', $log->last_cursor);
    }

    public function test_non_200_review_page_is_skipped_not_fatal(): void
    {
        $this->fakeCsm([
            self::BASE.'/book-reviews/charlottes-web' => Http::response('', 500),
        ]);

        $this->artisan('book:seed', ['--source' => 'csm'])->assertExitCode(0);

        // The failed page is skipped; the other two still land.
        $this->assertSame(2, BookLibraryTitle::count());

        $log = BookSyncLog::sole();
        $this->assertSame('completed', $log->status);
        $this->assertTrue($log->metadata['exhausted']);
        $this->assertSame(3, $log->api_calls_used);
        $this->assertSame(2, $log->titles_processed);
        // The skipped URL still advances the cursor — --resume must not
        // re-grind a permanently broken page.
        $this->assertSame(self::BASE.'/book-reviews/the-wild-robot', $log->last_cursor);
    }

    public function test_connection_error_on_review_page_is_skipped_not_fatal(): void
    {
        $this->fakeCsm([
            self::BASE.'/book-reviews/charlottes-web' => fn () => throw new ConnectionException('cURL error 28: timed out'),
        ]);

        $this->artisan('book:seed', ['--source' => 'csm'])->assertExitCode(0);

        // The unreachable page is skipped; the other two still land.
        $this->assertSame(2, BookLibraryTitle::count());

        $log = BookSyncLog::sole();
        $this->assertSame('completed', $log->status);
        $this->assertTrue($log->metadata['exhausted']);
        $this->assertSame(3, $log->api_calls_used);
        $this->assertSame(2, $log->titles_processed);
        // Skipped pages advance the cursor, exactly like the non-200 path.
        $this->assertSame(self::BASE.'/book-reviews/the-wild-robot', $log->last_cursor);
    }

    public function test_empty_walk_fails_run_instead_of_completing_exhausted(): void
    {
        // A walk yielding zero URLs must NOT look like a finished seed.
        Http::fake([
            self::BASE.'/sitemap.xml' => Http::response(
                '<?xml version="1.0" encoding="UTF-8"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></sitemapindex>'
            ),
        ]);

        $this->artisan('book:seed', ['--source' => 'csm'])->assertExitCode(1);

        $this->assertSame(0, BookLibraryTitle::count());

        $log = BookSyncLog::sole();
        $this->assertSame('seed_csm', $log->sync_type);
        $this->assertSame('failed', $log->status);
        $this->assertSame('CSM sitemap walk returned no book-review URLs', $log->error_message);
    }
}
