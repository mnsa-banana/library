<?php

namespace Tests\Feature\Console;

use App\Models\BookLibraryTitle;
use App\Models\BookListMembership;
use App\Models\BookSyncLog;
use App\Services\BookLibrary\PluggedInIndexScraper;
use App\Services\BookLibrary\SyncRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BookSeedPluggedInTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://www.pluggedin.com';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        // Politeness delay injected to 0 — the suite must never sleep 1s/page.
        $this->app->instance(PluggedInIndexScraper::class, new PluggedInIndexScraper(delayMs: 0));
    }

    private function fixture(string $name): string
    {
        return file_get_contents(base_path("tests/fixtures/book_library/{$name}"));
    }

    /**
     * Minimal Plugged In review page: Elementor post header — "Book Review"
     * type-custom label, h1 post title, then the author byline as the next
     * type-custom item (verified live 2026-06-11; no JSON-LD book schema).
     */
    private function reviewHtml(string $title, string $author): string
    {
        return '<!DOCTYPE html><html><head>'
            ."<meta property=\"og:title\" content=\"{$title}\" />"
            .'</head><body>'
            .'<span class="elementor-icon-list-text elementor-post-info__item elementor-post-info__item--type-custom">Book Review</span>'
            ."<h1 class=\"elementor-heading-title elementor-size-default\">{$title}</h1>"
            ."<span class=\"elementor-icon-list-text elementor-post-info__item elementor-post-info__item--type-custom\">{$author}</span>"
            .'<span class="elementor-icon-list-text elementor-post-info__item elementor-post-info__item--type-custom">12 years old and up</span>'
            .'</body></html>';
    }

    /**
     * Fixture walk: sitemap_index.xml (Yoast sitemapindex, mixed post types)
     * → book-reviews-sitemap.xml + book-reviews-sitemap2.xml (urlsets) →
     * three /book-reviews/ pages. The other children (post-sitemap*, page-,
     * movie-reviews-, author-) are NOT faked — fetching any of them trips
     * preventStrayRequests, proving the child-name filter.
     *
     * @param  array<string, mixed>  $overrides  pattern => response, matched before the defaults
     */
    private function fakePluggedIn(array $overrides = []): void
    {
        Http::fake($overrides + [
            self::BASE.'/sitemap_index.xml' => Http::response($this->fixture('pluggedin_sitemap_index.xml')),
            self::BASE.'/book-reviews-sitemap.xml' => Http::response($this->fixture('pluggedin_book_reviews_sitemap.xml')),
            self::BASE.'/book-reviews-sitemap2.xml' => Http::response($this->fixture('pluggedin_book_reviews_sitemap2.xml')),
            self::BASE.'/book-reviews/bravely/' => Http::response($this->reviewHtml('Bravely', 'Maggie Stiefvater')),
            self::BASE.'/book-reviews/freedom-train-the-story-of-harriet-tubman/' => Http::response($this->fixture('pluggedin_review_page.html')),
            self::BASE.'/book-reviews/higher-power-of-lucky/' => Http::response($this->reviewHtml('Higher Power of Lucky', 'Susan Patron')),
        ]);
    }

    /** @return array<string> request paths (+query), in call order */
    private function pluggedInPaths(): array
    {
        return collect(Http::recorded())
            ->map(fn (array $pair) => $pair[0]->url())
            ->filter(fn (string $url) => str_contains($url, 'pluggedin.com'))
            ->map(fn (string $url) => substr($url, strlen(self::BASE)))
            ->values()
            ->all();
    }

    public function test_sitemap_walk_returns_sorted_unique_book_review_urls(): void
    {
        $this->fakePluggedIn();

        $urls = (new PluggedInIndexScraper(delayMs: 0))->reviewUrls();

        // Only slugged /book-reviews/ URLs survive: the /book-reviews/ archive
        // root (listed in the sitemap) is excluded; duplicates — within and
        // across child sitemaps — collapse; sorted.
        $this->assertSame([
            self::BASE.'/book-reviews/bravely/',
            self::BASE.'/book-reviews/freedom-train-the-story-of-harriet-tubman/',
            self::BASE.'/book-reviews/higher-power-of-lucky/',
        ], $urls);

        // Child-name filter: only the book-reviews-sitemap*.xml children are
        // fetched — never post-/page-/movie-reviews-/author- sitemaps (any of
        // those would trip preventStrayRequests).
        $this->assertSame([
            '/sitemap_index.xml',
            '/book-reviews-sitemap.xml',
            '/book-reviews-sitemap2.xml',
        ], $this->pluggedInPaths());

        // UA regression insurance: every request must carry a plain
        // generic-browser UA (never anything AI/bot-labeled).
        $plainUa = fn (Request $request) => str_starts_with((string) ($request->header('User-Agent')[0] ?? ''), 'Mozilla/5.0');
        Http::assertSent($plainUa);
        Http::assertNotSent(fn (Request $request) => ! $plainUa($request));
    }

    public function test_review_page_meta_parses_h1_title_and_byline_author(): void
    {
        Http::fake([
            self::BASE.'/book-reviews/freedom-train-the-story-of-harriet-tubman/' => Http::response($this->fixture('pluggedin_review_page.html')),
        ]);

        $meta = (new PluggedInIndexScraper(delayMs: 0))
            ->reviewPageMeta(self::BASE.'/book-reviews/freedom-train-the-story-of-harriet-tubman/');

        // Author is the type-custom item after the "Book Review" label; the
        // age band right after it supplies min_age (lower bound). Later
        // byline items (publisher, year) must not bleed into either.
        $this->assertSame([
            'title' => 'Freedom Train: The Story of Harriet Tubman',
            'author' => 'Dorothy Sterling',
            'min_age' => 8,
        ], $meta);
    }

    public function test_review_page_meta_skips_roundup_pages_with_placeholder_byline(): void
    {
        // Roundup/listicle posts ("10 Family-Friendly Picture Books from
        // 2008") live in the book-reviews sitemap but review no single book
        // — their post-info byline carries the literal placeholder "None"
        // (and "Unknown" for the later items) where a real review carries
        // the book's author. The page must be skipped entirely: its h1 is
        // an article headline, not a book title.
        Http::fake([
            self::BASE.'/book-reviews/0010-family-friendly-picture-books-2008/' => Http::response(
                '<!DOCTYPE html><html><head></head><body>'
                .'<span class="elementor-icon-list-text elementor-post-info__item elementor-post-info__item--type-custom">Book Review</span>'
                .'<span class="elementor-icon-list-text elementor-post-info__item elementor-post-info__item--type-custom">None</span>'
                .'<span class="elementor-icon-list-text elementor-post-info__item elementor-post-info__item--type-custom">Unknown</span>'
                .'<h1 class="elementor-heading-title elementor-size-default">10 Family-Friendly Picture Books from 2008</h1>'
                .'</body></html>'
            ),
        ]);

        $meta = (new PluggedInIndexScraper(delayMs: 0))
            ->reviewPageMeta(self::BASE.'/book-reviews/0010-family-friendly-picture-books-2008/');

        $this->assertNull($meta);
    }

    public function test_review_page_meta_falls_back_to_og_title_when_h1_missing(): void
    {
        Http::fake([
            self::BASE.'/book-reviews/some-book/' => Http::response(
                '<html><head><meta property="og:title" content="Some Book" /></head><body></body></html>'
            ),
        ]);

        $meta = (new PluggedInIndexScraper(delayMs: 0))->reviewPageMeta(self::BASE.'/book-reviews/some-book/');

        $this->assertSame([
            'title' => 'Some Book',
            'author' => null,
            'min_age' => null,
        ], $meta);
    }

    public function test_review_page_meta_rejects_age_band_when_author_item_is_missing(): void
    {
        // "Book Review" label followed directly by the age band — no author
        // item. The positional heuristic must NOT ingest "8 to 12" as the
        // author: a digit-leading author would survive normalization
        // ("8 to 12" → last name "12") and poison work resolution with a
        // permanent duplicate row, so it must come back null instead.
        Http::fake([
            self::BASE.'/book-reviews/authorless/' => Http::response(
                '<!DOCTYPE html><html><head></head><body>'
                .'<span class="elementor-icon-list-text elementor-post-info__item elementor-post-info__item--type-custom">Book Review</span>'
                .'<h1 class="elementor-heading-title elementor-size-default">Authorless Book</h1>'
                .'<span class="elementor-icon-list-text elementor-post-info__item elementor-post-info__item--type-custom">8 to 12</span>'
                .'</body></html>'
            ),
        ]);

        $meta = (new PluggedInIndexScraper(delayMs: 0))->reviewPageMeta(self::BASE.'/book-reviews/authorless/');

        $this->assertSame([
            'title' => 'Authorless Book',
            'author' => null,
            'min_age' => 8,
        ], $meta);
    }

    public function test_seed_stores_null_author_when_byline_carries_only_age_band(): void
    {
        $this->fakePluggedIn([
            self::BASE.'/book-reviews/bravely/' => Http::response(
                '<!DOCTYPE html><html><head></head><body>'
                .'<span class="elementor-icon-list-text elementor-post-info__item elementor-post-info__item--type-custom">Book Review</span>'
                .'<h1 class="elementor-heading-title elementor-size-default">Bravely</h1>'
                .'<span class="elementor-icon-list-text elementor-post-info__item elementor-post-info__item--type-custom">8 to 12</span>'
                .'</body></html>'
            ),
        ]);

        $this->artisan('book:seed', ['--source' => 'pluggedin'])->assertExitCode(0);

        // The row still seeds — with a null author, never the age band.
        $bravely = BookLibraryTitle::where('title', 'Bravely')->sole();
        $this->assertNull($bravely->author);
    }

    public function test_review_page_meta_returns_null_author_when_nothing_follows_the_label(): void
    {
        // Label present but it's the LAST type-custom item — nothing after it.
        Http::fake([
            self::BASE.'/book-reviews/label-only/' => Http::response(
                '<!DOCTYPE html><html><head></head><body>'
                .'<span class="elementor-icon-list-text elementor-post-info__item elementor-post-info__item--type-custom">Book Review</span>'
                .'<h1 class="elementor-heading-title elementor-size-default">Label Only</h1>'
                .'</body></html>'
            ),
        ]);

        $meta = (new PluggedInIndexScraper(delayMs: 0))->reviewPageMeta(self::BASE.'/book-reviews/label-only/');

        $this->assertSame([
            'title' => 'Label Only',
            'author' => null,
            'min_age' => null,
        ], $meta);
    }

    public function test_review_page_meta_returns_null_on_non_200_and_on_title_less_pages(): void
    {
        Http::fake([
            self::BASE.'/book-reviews/gone/' => Http::response('', 500),
            self::BASE.'/book-reviews/bare/' => Http::response('<html><head></head><body></body></html>'),
        ]);

        $scraper = new PluggedInIndexScraper(delayMs: 0);

        $this->assertNull($scraper->reviewPageMeta(self::BASE.'/book-reviews/gone/'));
        $this->assertNull($scraper->reviewPageMeta(self::BASE.'/book-reviews/bare/'));
    }

    public function test_seed_creates_work_rows_each_carrying_review_url(): void
    {
        $this->fakePluggedIn();

        $this->artisan('book:seed', ['--source' => 'pluggedin'])->assertExitCode(0);

        $this->assertSame(3, BookLibraryTitle::count());

        $train = BookLibraryTitle::where('title', 'Freedom Train: The Story of Harriet Tubman')->sole();
        $this->assertSame('Dorothy Sterling', $train->author);
        // min_age from the byline age band ("8 to 12"); no ISBN signal.
        $this->assertSame(8, $train->min_age);
        $this->assertSame('pluggedin_index', $train->min_age_source);
        $this->assertSame([], $train->isbn13s);

        $membership = $train->memberships()->sole();
        $this->assertSame('pluggedin_index', $membership->list_source);
        $this->assertSame('index', $membership->list_key);
        $this->assertSame(self::BASE.'/book-reviews/freedom-train-the-story-of-harriet-tubman/', $membership->review_url);

        // Spec verification item 1: every seeded membership carries its
        // review page URL.
        $this->assertSame(3, BookListMembership::whereNotNull('review_url')->count());

        $log = BookSyncLog::sole();
        $this->assertSame('seed_pluggedin', $log->sync_type);
        $this->assertSame('completed', $log->status);
        $this->assertSame(3, $log->api_calls_used);
        $this->assertSame(3, $log->titles_processed);
        $this->assertTrue($log->metadata['exhausted']);
        $this->assertSame(self::BASE.'/book-reviews/higher-power-of-lucky/', $log->last_cursor);
    }

    public function test_limit_stops_after_n_pages_with_cursor_at_last_processed_url(): void
    {
        $this->fakePluggedIn();

        $this->artisan('book:seed', ['--source' => 'pluggedin', '--limit' => 2])->assertExitCode(0);

        $this->assertSame(2, BookLibraryTitle::count());
        $this->assertNotContains('/book-reviews/higher-power-of-lucky/', $this->pluggedInPaths());

        $log = BookSyncLog::sole();
        $this->assertSame('completed', $log->status);
        $this->assertFalse($log->metadata['exhausted']);
        $this->assertSame(2, $log->api_calls_used);
        $this->assertSame(self::BASE.'/book-reviews/freedom-train-the-story-of-harriet-tubman/', $log->last_cursor);
    }

    public function test_resume_skips_urls_at_or_before_the_cursor(): void
    {
        $prior = SyncRun::start('seed_pluggedin');
        $prior->cursor(self::BASE.'/book-reviews/freedom-train-the-story-of-harriet-tubman/');
        $prior->fail('interrupted');

        $this->fakePluggedIn();

        $this->artisan('book:seed', ['--source' => 'pluggedin', '--resume' => true])->assertExitCode(0);

        // The walk re-runs (cheap), but only the URL after the cursor is fetched.
        $this->assertSame([
            '/sitemap_index.xml',
            '/book-reviews-sitemap.xml',
            '/book-reviews-sitemap2.xml',
            '/book-reviews/higher-power-of-lucky/',
        ], $this->pluggedInPaths());

        $this->assertSame(1, BookLibraryTitle::count());

        $log = BookSyncLog::orderByDesc('id')->first();
        $this->assertSame('completed', $log->status);
        $this->assertTrue($log->metadata['exhausted']);
        $this->assertSame(self::BASE.'/book-reviews/higher-power-of-lucky/', $log->last_cursor);
    }

    public function test_non_200_review_page_is_skipped_not_fatal(): void
    {
        $this->fakePluggedIn([
            self::BASE.'/book-reviews/freedom-train-the-story-of-harriet-tubman/' => Http::response('', 500),
        ]);

        $this->artisan('book:seed', ['--source' => 'pluggedin'])->assertExitCode(0);

        // The failed page is skipped; the other two still land.
        $this->assertSame(2, BookLibraryTitle::count());

        $log = BookSyncLog::sole();
        $this->assertSame('completed', $log->status);
        $this->assertTrue($log->metadata['exhausted']);
        $this->assertSame(3, $log->api_calls_used);
        $this->assertSame(2, $log->titles_processed);
        // The skipped URL still advances the cursor — --resume must not
        // re-grind a permanently broken page.
        $this->assertSame(self::BASE.'/book-reviews/higher-power-of-lucky/', $log->last_cursor);
    }

    public function test_connection_error_on_review_page_is_skipped_not_fatal(): void
    {
        $this->fakePluggedIn([
            self::BASE.'/book-reviews/freedom-train-the-story-of-harriet-tubman/' => fn () => throw new ConnectionException('cURL error 28: timed out'),
        ]);

        $this->artisan('book:seed', ['--source' => 'pluggedin'])->assertExitCode(0);

        // The unreachable page is skipped; the other two still land.
        $this->assertSame(2, BookLibraryTitle::count());

        $log = BookSyncLog::sole();
        $this->assertSame('completed', $log->status);
        $this->assertTrue($log->metadata['exhausted']);
        $this->assertSame(3, $log->api_calls_used);
        $this->assertSame(2, $log->titles_processed);
        // Skipped pages advance the cursor, exactly like the non-200 path.
        $this->assertSame(self::BASE.'/book-reviews/higher-power-of-lucky/', $log->last_cursor);
    }

    public function test_empty_walk_fails_run_instead_of_completing_exhausted(): void
    {
        // A walk yielding zero URLs must NOT look like a finished seed.
        Http::fake([
            self::BASE.'/sitemap_index.xml' => Http::response(
                '<?xml version="1.0" encoding="UTF-8"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></sitemapindex>'
            ),
        ]);

        $this->artisan('book:seed', ['--source' => 'pluggedin'])->assertExitCode(1);

        $this->assertSame(0, BookLibraryTitle::count());

        $log = BookSyncLog::sole();
        $this->assertSame('seed_pluggedin', $log->sync_type);
        $this->assertSame('failed', $log->status);
        $this->assertSame('Plugged In sitemap walk returned no book-review URLs', $log->error_message);
    }

    public function test_delta_fetches_only_new_review_pages(): void
    {
        foreach (['bravely', 'higher-power-of-lucky'] as $slug) {
            BookListMembership::factory()->create([
                'list_source' => 'pluggedin_index',
                'review_url' => self::BASE."/book-reviews/{$slug}/",
            ]);
        }
        $this->fakePluggedIn();

        $this->artisan('book:seed', ['--source' => 'pluggedin', '--delta' => true])->assertExitCode(0);

        $pages = array_values(array_filter($this->pluggedInPaths(), fn (string $p) => str_starts_with($p, '/book-reviews/')));
        $this->assertSame(['/book-reviews/freedom-train-the-story-of-harriet-tubman/'], $pages);
    }

    public function test_delta_with_no_new_urls_is_a_clean_no_op(): void
    {
        foreach (['bravely', 'freedom-train-the-story-of-harriet-tubman', 'higher-power-of-lucky'] as $slug) {
            BookListMembership::factory()->create([
                'list_source' => 'pluggedin_index',
                'review_url' => self::BASE."/book-reviews/{$slug}/",
            ]);
        }
        $this->fakePluggedIn();

        $this->artisan('book:seed', ['--source' => 'pluggedin', '--delta' => true])->assertExitCode(0);

        $pages = array_filter($this->pluggedInPaths(), fn (string $p) => str_starts_with($p, '/book-reviews/'));
        $this->assertSame([], $pages);
    }

    public function test_byline_min_age_ignores_bands_beyond_the_byline_window(): void
    {
        // An age band rendered by an unrelated widget far below the byline
        // (e.g. a related-reviews card) must not be grafted onto this book.
        $items = '<span class="elementor-icon-list-text elementor-post-info__item elementor-post-info__item--type-custom">Book Review</span>'
            .'<span class="elementor-icon-list-text elementor-post-info__item elementor-post-info__item--type-custom">Gary Paulsen</span>'
            .'<span class="elementor-icon-list-text elementor-post-info__item elementor-post-info__item--type-custom">Simon and Schuster</span>'
            .'<span class="elementor-icon-list-text elementor-post-info__item elementor-post-info__item--type-custom">Newbery Honor</span>'
            .'<span class="elementor-icon-list-text elementor-post-info__item elementor-post-info__item--type-custom">1987</span>'
            .'<span class="elementor-icon-list-text elementor-post-info__item elementor-post-info__item--type-custom">Adventure</span>'
            .'<span class="elementor-icon-list-text elementor-post-info__item elementor-post-info__item--type-custom">5 to 7</span>';
        Http::fake([
            self::BASE.'/book-reviews/windowed/' => Http::response(
                '<!DOCTYPE html><html><body>'.$items
                .'<h1 class="elementor-heading-title elementor-size-default">Windowed</h1>'
                .'</body></html>'
            ),
        ]);

        $meta = (new PluggedInIndexScraper(delayMs: 0))->reviewPageMeta(self::BASE.'/book-reviews/windowed/');

        $this->assertSame('Windowed', $meta['title']);
        $this->assertNull($meta['min_age']);
    }
}
