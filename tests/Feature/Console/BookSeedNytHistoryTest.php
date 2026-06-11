<?php

namespace Tests\Feature\Console;

use App\Models\BookLibraryTitle;
use App\Models\BookListMembership;
use App\Models\BookSyncLog;
use App\Services\BookLibrary\NytClient;
use App\Services\BookLibrary\SyncRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BookSeedNytHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        // Throttle injected to 0 — the suite must never sleep 12s between calls.
        $this->app->instance(NytClient::class, new NytClient(delayMs: 0));
        config(['services.nyt.books_key' => 'test-key']);
    }

    private function fixture(string $name): array
    {
        return json_decode(
            file_get_contents(base_path("tests/fixtures/book_library/{$name}.json")),
            true
        );
    }

    /**
     * Names fixture covers picture-books (newest 2025-01-15, oldest 2024-12-15)
     * and chapter-books (single page); the other five history lists are absent
     * and must be skipped without any page fetch.
     *
     * @param  array<string, mixed>  $overrides  pattern => response, matched before the defaults
     */
    private function fakeHistory(array $overrides = []): void
    {
        Http::fake($overrides + [
            'api.nytimes.com/svc/books/v3/lists/names.json*' => Http::response($this->fixture('nyt_names')),
            'api.nytimes.com/svc/books/v3/lists/2025-01-15/picture-books.json*' => Http::response($this->fixture('nyt_picture_books_2025_01_15')),
            'api.nytimes.com/svc/books/v3/lists/2025-01-03/picture-books.json*' => Http::response($this->fixture('nyt_picture_books_2025_01_03')),
            'api.nytimes.com/svc/books/v3/lists/2014-06-08/chapter-books.json*' => Http::response($this->fixture('nyt_chapter_books_2014_06_08')),
            'openlibrary.org/*' => Http::response(['error' => 'notfound'], 404),
        ]);
    }

    /** @return array<string> NYT request paths, in call order (Open Library noise excluded) */
    private function nytPaths(): array
    {
        return collect(Http::recorded())
            ->map(fn (array $pair) => $pair[0]->url())
            ->filter(fn (string $url) => str_contains($url, 'api.nytimes.com'))
            ->map(fn (string $url) => parse_url($url, PHP_URL_PATH))
            ->values()
            ->all();
    }

    public function test_history_walks_previous_published_date_and_stops_at_each_lists_oldest_date(): void
    {
        $this->fakeHistory();

        $this->artisan('book:seed', ['--source' => 'nyt-history'])->assertExitCode(0);

        // Walk order proves: start at lists/names newest, follow the response's
        // previous_published_date (2025-01-15 → 2025-01-03), do NOT fetch
        // 2024-11-20 (older than picture-books' oldest 2024-12-15), and stop
        // chapter-books on its empty previous_published_date.
        $this->assertSame([
            '/svc/books/v3/lists/names.json',
            '/svc/books/v3/lists/2025-01-15/picture-books.json',
            '/svc/books/v3/lists/2025-01-03/picture-books.json',
            '/svc/books/v3/lists/2014-06-08/chapter-books.json',
        ], $this->nytPaths());

        $this->assertSame(4, BookLibraryTitle::count());

        $truck = BookLibraryTitle::where('title', 'Little Blue Truck')->sole();
        $membership = $truck->memberships()->sole();
        $this->assertSame('nyt', $membership->list_source);
        $this->assertSame('picture-books', $membership->list_key);
        $this->assertSame(150, $membership->weeks_on_list);
        $this->assertSame('2025-01-03', $membership->as_of_date->toDateString());

        // chapter-books band → min_age 4.
        $mth = BookLibraryTitle::where('title', 'Magic Tree House')->sole();
        $this->assertSame(4, $mth->min_age);
        $this->assertSame('nyt', $mth->min_age_source);

        $log = BookSyncLog::sole();
        $this->assertSame('seed_nyt_history', $log->sync_type);
        $this->assertSame('completed', $log->status);
        $this->assertSame(4, $log->api_calls_used);
        $this->assertSame(4, $log->titles_processed);
        $this->assertTrue($log->metadata['exhausted']);
        $this->assertSame('chapter-books|2014-06-08', $log->last_cursor);
    }

    public function test_backfill_keeps_newest_week_stats_for_titles_charting_multiple_weeks(): void
    {
        // Same work (same ISBN) charts in both picture-books weeks. The walk
        // is forced newest→oldest by previous_published_date, so without a
        // newest-wins guard the older week would overwrite the membership
        // with debut-era stats (weeks_on_list 10, rank 9, older as_of_date).
        $bigJim = fn (int $rank, int $weeks) => [
            'rank' => $rank,
            'weeks_on_list' => $weeks,
            'primary_isbn13' => '9781338846621',
            'title' => 'BIG JIM BEGINS',
            'author' => 'Dav Pilkey',
            'isbns' => [['isbn13' => '9781338846621']],
        ];

        $this->fakeHistory([
            'api.nytimes.com/svc/books/v3/lists/2025-01-15/picture-books.json*' => Http::response(['results' => [
                'published_date' => '2025-01-15',
                'previous_published_date' => '2025-01-03',
                'books' => [$bigJim(3, 12)],
            ]]),
            'api.nytimes.com/svc/books/v3/lists/2025-01-03/picture-books.json*' => Http::response(['results' => [
                'published_date' => '2025-01-03',
                'previous_published_date' => '2024-11-20',
                'books' => [$bigJim(9, 10)],
            ]]),
        ]);

        $this->artisan('book:seed', ['--source' => 'nyt-history'])->assertExitCode(0);

        $title = BookLibraryTitle::where('title', 'Big Jim Begins')->sole();
        $membership = $title->memberships()->sole();
        $this->assertSame(3, $membership->rank);
        $this->assertSame(12, $membership->weeks_on_list);
        $this->assertSame('2025-01-15', $membership->as_of_date->toDateString());
    }

    public function test_limit_stops_after_n_pages_with_cursor_for_the_next_unfetched_page(): void
    {
        $this->fakeHistory();

        $this->artisan('book:seed', ['--source' => 'nyt-history', '--limit' => 1])->assertExitCode(0);

        $this->assertSame([
            '/svc/books/v3/lists/names.json',
            '/svc/books/v3/lists/2025-01-15/picture-books.json',
        ], $this->nytPaths());

        $this->assertSame(2, BookLibraryTitle::count());

        $log = BookSyncLog::sole();
        $this->assertSame('completed', $log->status);
        $this->assertFalse($log->metadata['exhausted']);
        $this->assertSame('picture-books|2025-01-03', $log->last_cursor);
    }

    public function test_resume_continues_from_persisted_cursor_date(): void
    {
        $prior = SyncRun::start('seed_nyt_history');
        $prior->cursor('picture-books|2025-01-03');
        $prior->fail('interrupted');

        $this->fakeHistory();

        $this->artisan('book:seed', ['--source' => 'nyt-history', '--resume' => true])->assertExitCode(0);

        // No fetch of the already-walked newest page (2025-01-15).
        $this->assertSame([
            '/svc/books/v3/lists/names.json',
            '/svc/books/v3/lists/2025-01-03/picture-books.json',
            '/svc/books/v3/lists/2014-06-08/chapter-books.json',
        ], $this->nytPaths());

        $log = BookSyncLog::orderByDesc('id')->first();
        $this->assertSame('completed', $log->status);
        $this->assertTrue($log->metadata['exhausted']);
    }

    public function test_resume_skips_lists_before_the_cursor_list(): void
    {
        $prior = SyncRun::start('seed_nyt_history');
        $prior->cursor('chapter-books|2014-06-08');
        $prior->fail('interrupted');

        $this->fakeHistory();

        $this->artisan('book:seed', ['--source' => 'nyt-history', '--resume' => true])->assertExitCode(0);

        $this->assertSame([
            '/svc/books/v3/lists/names.json',
            '/svc/books/v3/lists/2014-06-08/chapter-books.json',
        ], $this->nytPaths());
    }

    public function test_run_stops_at_450_call_budget_with_cursor_persisted(): void
    {
        // Programmatic deep history: every page returns the prior week as
        // previous_published_date (computed in the FAKE — the command itself
        // must only ever follow the field).
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/lists/names.json')) {
                return Http::response(['results' => [[
                    'list_name_encoded' => 'picture-books',
                    'oldest_published_date' => '2000-01-01',
                    'newest_published_date' => '2025-12-28',
                ]]]);
            }
            preg_match('#/lists/(\d{4}-\d{2}-\d{2})/picture-books\.json#', $url, $m);

            return Http::response(['results' => [
                'published_date' => $m[1],
                'previous_published_date' => Carbon::parse($m[1])->subDays(7)->toDateString(),
                'books' => [],
            ]]);
        });

        $this->artisan('book:seed', ['--source' => 'nyt-history'])->assertExitCode(0);

        $log = BookSyncLog::sole();
        $this->assertSame('completed', $log->status);
        $this->assertFalse($log->metadata['exhausted']);
        $this->assertSame(450, $log->api_calls_used);
        $this->assertStringStartsWith('picture-books|', (string) $log->last_cursor);
        $this->assertCount(450, $this->nytPaths());
    }

    public function test_429_stops_run_cleanly_with_cursor_and_unexhausted_metadata(): void
    {
        // names + first page succeed, the second page is rate limited.
        $this->fakeHistory([
            'api.nytimes.com/svc/books/v3/lists/2025-01-03/picture-books.json*' => Http::response(['fault' => 'Rate limit quota violation'], 429),
        ]);

        $this->artisan('book:seed', ['--source' => 'nyt-history'])->assertExitCode(0);

        // Page-1 work is kept; the 429'd page is the resume point.
        $this->assertSame(2, BookLibraryTitle::count());

        $log = BookSyncLog::sole();
        $this->assertSame('completed', $log->status);
        $this->assertFalse($log->metadata['exhausted']);
        $this->assertSame('picture-books|2025-01-03', $log->last_cursor);
        $this->assertSame(3, $log->api_calls_used);
    }

    public function test_non_429_failure_mid_run_fails_log_and_keeps_prior_page_work(): void
    {
        // names + page 1 succeed; the page-2 fetch hits a server error.
        $this->fakeHistory([
            'api.nytimes.com/svc/books/v3/lists/2025-01-03/picture-books.json*' => Http::response([], 500),
        ]);

        $this->artisan('book:seed', ['--source' => 'nyt-history'])->assertExitCode(1);

        // Page-1 titles and memberships are kept.
        $this->assertSame(2, BookLibraryTitle::count());
        $this->assertSame(2, BookListMembership::count());

        $log = BookSyncLog::sole();
        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString('NYT request failed (500)', (string) $log->error_message);
        // Last checkpoint is the processed page 1 — --resume re-fetches it
        // (harmless membership upsert) and walks on from its previous date.
        $this->assertSame('picture-books|2025-01-15', $log->last_cursor);
    }

    public function test_empty_lists_names_fails_run_instead_of_completing_exhausted(): void
    {
        // NYT 200 with no results array — must NOT look like a finished backfill.
        Http::fake([
            'api.nytimes.com/svc/books/v3/lists/names.json*' => Http::response(['status' => 'ERROR']),
        ]);

        $this->artisan('book:seed', ['--source' => 'nyt-history'])->assertExitCode(1);

        $this->assertSame(0, BookLibraryTitle::count());

        $log = BookSyncLog::sole();
        $this->assertSame('failed', $log->status);
        $this->assertSame('NYT lists/names returned no lists', $log->error_message);
        $this->assertSame(['/svc/books/v3/lists/names.json'], $this->nytPaths());
    }

    public function test_missing_api_key_exits_one_and_logs_failed_run(): void
    {
        config(['services.nyt.books_key' => null]);
        Http::fake();

        $this->artisan('book:seed', ['--source' => 'nyt-history'])->assertExitCode(1);

        $log = BookSyncLog::sole();
        $this->assertSame('seed_nyt_history', $log->sync_type);
        $this->assertSame('failed', $log->status);
        Http::assertNothingSent();
    }

    public function test_unknown_or_missing_source_is_invalid(): void
    {
        Http::fake();

        $this->artisan('book:seed', ['--source' => 'bogus'])->assertExitCode(2);
        $this->artisan('book:seed')->assertExitCode(2);

        $this->assertSame(0, BookSyncLog::count());
        Http::assertNothingSent();
    }
}
