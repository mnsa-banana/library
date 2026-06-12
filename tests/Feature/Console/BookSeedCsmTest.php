<?php

namespace Tests\Feature\Console;

use App\Models\BookLibraryTitle;
use App\Models\BookListMembership;
use App\Models\BookSyncLog;
use App\Services\BookLibrary\CsmIndexScraper;
use App\Services\BookLibrary\OpenLibraryClient;
use App\Services\BookLibrary\SyncRun;
use App\Services\BookLibrary\WorkResolver;
use Illuminate\Database\QueryException;
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

    public function test_review_page_meta_reads_age_from_review_node_in_graph(): void
    {
        // Live CSM pages (verified 2026-06-11) wrap the JSON-LD in @graph and
        // put typicalAgeRange on the REVIEW node — the Book node carries
        // none. The scraper must graft the review-node age onto the book.
        $json = json_encode([
            '@context' => 'https://schema.org',
            '@graph' => [[
                '@type' => 'Review',
                'name' => '1-2-3 Peas',
                'typicalAgeRange' => '3+',
                'itemReviewed' => [
                    '@type' => 'Book',
                    'name' => '1-2-3 Peas',
                    'author' => ['@type' => 'Person', 'name' => 'Keith Baker'],
                    'isbn' => '9781442445512',
                ],
            ]],
        ], JSON_UNESCAPED_SLASHES);
        Http::fake([
            self::BASE.'/book-reviews/1-2-3-peas' => Http::response(
                '<!DOCTYPE html><html><head>'
                ."<script type=\"application/ld+json\">{$json}</script>"
                .'</head><body></body></html>'
            ),
        ]);

        $meta = (new CsmIndexScraper(delayMs: 0))->reviewPageMeta(self::BASE.'/book-reviews/1-2-3-peas');

        $this->assertSame('1-2-3 Peas', $meta['title']);
        $this->assertSame('Keith Baker', $meta['author']);
        $this->assertSame(3, $meta['min_age']);
        $this->assertSame(['9781442445512'], $meta['isbn13s']);
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

    public function test_persistent_open_library_429_stops_run_cleanly_with_cursor_at_last_processed_url(): void
    {
        // Backoff injected to 0 — the OL client must not sleep between retries.
        $this->app->instance(OpenLibraryClient::class, new OpenLibraryClient(backoffBaseMs: 0));

        // Page 1 (a-wrinkle-in-time) ingests fine (its OL lookup 404s via the
        // default fake); page 2's OL resolution rate-limits persistently —
        // same contract as an NYT 429: cursor persisted, completed with
        // exhausted=false, exit 0 (NOT a failed run / exit 1).
        $this->fakeCsm([
            'openlibrary.org/isbn/9780060263850.json' => Http::response(['error' => 'rate limited'], 429),
        ]);

        $this->artisan('book:seed', ['--source' => 'csm'])->assertExitCode(0);

        // Page-1 work is kept; the rate-limited item's membership was never
        // written, so the cursor must stay at the last fully processed URL —
        // --resume re-fetches charlottes-web instead of skipping past it.
        $this->assertSame(1, BookLibraryTitle::count());

        $log = BookSyncLog::sole();
        $this->assertSame('completed', $log->status);
        $this->assertFalse($log->metadata['exhausted']);
        $this->assertSame(self::BASE.'/book-reviews/a-wrinkle-in-time', $log->last_cursor);
        $this->assertSame(2, $log->api_calls_used);
    }

    public function test_query_exception_during_ingest_is_skipped_and_cursor_advances(): void
    {
        // A poison row (e.g. a >255-char title hitting the Postgres varchar
        // limit) throws QueryException from inside ingest. The run must skip
        // it and advance the cursor — otherwise --resume re-processes the
        // same URL forever. WorkResolver is the real throw site
        // (BookLibraryTitle::create), so the partial mock throws from there.
        $resolver = \Mockery::mock(
            WorkResolver::class,
            [new OpenLibraryClient(backoffBaseMs: 0)]
        )->makePartial();
        $resolver->shouldReceive('resolve')
            ->withArgs(fn (array $item) => ($item['title'] ?? null) === "Charlotte's Web")
            ->andThrow(new QueryException(
                'sqlite',
                'insert into "book_library_titles" ...',
                [],
                new \RuntimeException('value too long for type character varying(255)'),
            ));
        $resolver->shouldReceive('resolve')->passthru();
        $this->app->instance(WorkResolver::class, $resolver);

        $this->fakeCsm();

        $this->artisan('book:seed', ['--source' => 'csm'])->assertExitCode(0);

        // The poison URL is skipped; the other two still land.
        $this->assertSame(2, BookLibraryTitle::count());
        $this->assertNull(BookLibraryTitle::where('title', "Charlotte's Web")->first());

        $log = BookSyncLog::sole();
        $this->assertSame('completed', $log->status);
        $this->assertTrue($log->metadata['exhausted']);
        $this->assertSame(2, $log->titles_processed);
        // The cursor advanced past the poison URL — --resume must not wedge.
        $this->assertSame(self::BASE.'/book-reviews/the-wild-robot', $log->last_cursor);
    }

    public function test_open_library_429_before_first_checkpoint_writes_sentinel_cursor_shielding_older_runs(): void
    {
        // Backoff injected to 0 — the OL client must not sleep between retries.
        $this->app->instance(OpenLibraryClient::class, new OpenLibraryClient(backoffBaseMs: 0));

        // An OLDER exhausted run left a late-alphabet cursor behind. If the
        // new run's 429 stop persisted nothing, lastCursor() would fall back
        // to it and --resume would silently no-op.
        $prior = SyncRun::start('seed_csm');
        $prior->cursor(self::BASE.'/book-reviews/zzz-last-book');
        $prior->complete(['exhausted' => true]);

        // OL rate-limits persistently on the FIRST item's ISBN (3 attempts =
        // one exhausted retry loop), then recovers for the resume run.
        $this->fakeCsm([
            'openlibrary.org/isbn/9780312367541.json' => Http::sequence()
                ->pushStatus(429)->pushStatus(429)->pushStatus(429)
                ->whenEmpty(Http::response(['error' => 'notfound'], 404)),
        ]);

        $this->artisan('book:seed', ['--source' => 'csm'])->assertExitCode(0);

        $this->assertSame(0, BookLibraryTitle::count());

        $log = BookSyncLog::orderByDesc('id')->first();
        $this->assertSame('completed', $log->status);
        $this->assertFalse($log->metadata['exhausted']);
        // The '' sentinel: non-null (shields the older run's cursor from
        // lastCursor) and <= every URL (resume starts from the beginning).
        $this->assertSame('', $log->last_cursor);

        // --resume must start from the first URL, not the older cursor.
        $this->artisan('book:seed', ['--source' => 'csm', '--resume' => true])->assertExitCode(0);

        $this->assertSame(3, BookLibraryTitle::count());
        $resumeLog = BookSyncLog::orderByDesc('id')->first();
        $this->assertTrue($resumeLog->metadata['exhausted']);
        $this->assertSame(3, $resumeLog->titles_processed);
    }

    public function test_open_library_429_on_first_new_url_of_resume_run_preserves_inherited_cursor(): void
    {
        // Backoff injected to 0 — the OL client must not sleep between retries.
        $this->app->instance(OpenLibraryClient::class, new OpenLibraryClient(backoffBaseMs: 0));

        // A prior interrupted run left a real cursor behind.
        $prior = SyncRun::start('seed_csm');
        $prior->cursor(self::BASE.'/book-reviews/charlottes-web');
        $prior->fail('interrupted');

        // OL rate-limits persistently on the FIRST new URL's ISBN (3 attempts
        // = one exhausted retry loop), then recovers for the follow-up resume.
        $this->fakeCsm([
            'openlibrary.org/isbn/9780316381994.json' => Http::sequence()
                ->pushStatus(429)->pushStatus(429)->pushStatus(429)
                ->whenEmpty(Http::response(['error' => 'notfound'], 404)),
        ]);

        $this->artisan('book:seed', ['--source' => 'csm', '--resume' => true])->assertExitCode(0);

        $this->assertSame(0, BookLibraryTitle::count());

        // The 429 hit before this run's first checkpoint ($lastProcessed is
        // null), but the run INHERITED a cursor via --resume: that position
        // must be preserved — persisting the '' sentinel here would wipe it
        // and make the next --resume restart from scratch.
        $log = BookSyncLog::orderByDesc('id')->first();
        $this->assertSame('completed', $log->status);
        $this->assertFalse($log->metadata['exhausted']);
        $this->assertSame(self::BASE.'/book-reviews/charlottes-web', $log->last_cursor);

        // A subsequent --resume continues from the same position: only the
        // one still-unprocessed URL is fetched and ingested.
        $this->artisan('book:seed', ['--source' => 'csm', '--resume' => true])->assertExitCode(0);

        $this->assertSame(1, BookLibraryTitle::count());
        $this->assertNotNull(BookLibraryTitle::where('title', 'The Wild Robot')->first());
        $resumeLog = BookSyncLog::orderByDesc('id')->first();
        $this->assertTrue($resumeLog->metadata['exhausted']);
        $this->assertSame(1, $resumeLog->titles_processed);
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

    public function test_delta_fetches_only_new_review_pages(): void
    {
        foreach (['a-wrinkle-in-time', 'the-wild-robot'] as $slug) {
            BookListMembership::factory()->create([
                'list_source' => 'csm_index',
                'review_url' => self::BASE."/book-reviews/{$slug}",
            ]);
        }
        $this->fakeCsm();

        $this->artisan('book:seed', ['--source' => 'csm', '--delta' => true])->assertExitCode(0);

        $pages = array_values(array_filter($this->csmPaths(), fn (string $p) => str_starts_with($p, '/book-reviews/')));
        $this->assertSame(['/book-reviews/charlottes-web'], $pages);
    }

    public function test_delta_with_no_new_urls_is_a_clean_no_op(): void
    {
        foreach (['a-wrinkle-in-time', 'charlottes-web', 'the-wild-robot'] as $slug) {
            BookListMembership::factory()->create([
                'list_source' => 'csm_index',
                'review_url' => self::BASE."/book-reviews/{$slug}",
            ]);
        }
        $this->fakeCsm();

        $this->artisan('book:seed', ['--source' => 'csm', '--delta' => true])->assertExitCode(0);

        $pages = array_filter($this->csmPaths(), fn (string $p) => str_starts_with($p, '/book-reviews/'));
        $this->assertSame([], $pages);

        $log = BookSyncLog::orderByDesc('id')->first();
        $this->assertSame('completed', $log->status);
        $this->assertTrue($log->metadata['delta']);
        $this->assertTrue($log->metadata['exhausted']);
    }
}
