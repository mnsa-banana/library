<?php

namespace Tests\Feature\Console;

use App\Models\BookLibraryTitle;
use App\Models\BookSyncLog;
use App\Services\BookLibrary\GoogleBooksClient;
use App\Services\BookLibrary\OpenLibraryClient;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BookEnrichTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        // Delays and backoff injected to 0 — the suite must never sleep.
        $this->app->instance(OpenLibraryClient::class, new OpenLibraryClient(delayMs: 0, backoffBaseMs: 0));
        $this->app->instance(GoogleBooksClient::class, new GoogleBooksClient(delayMs: 0, backoffBaseMs: 0));
        config(['services.google_books.key' => 'gb-test-key']);
    }

    /** One Google Books volume item echoing the given title. */
    private function gbVolume(string $title, string $viewability, array $volumeInfo = []): array
    {
        return [
            'totalItems' => 1,
            'items' => [[
                'id' => 'gb-'.md5($title),
                'volumeInfo' => $volumeInfo + [
                    'title' => $title,
                    'description' => "About {$title}.",
                    'categories' => ['Juvenile Fiction'],
                    'pageCount' => 224,
                    'imageLinks' => ['thumbnail' => 'http://books.google.com/thumb.jpg'],
                ],
                'accessInfo' => ['viewability' => $viewability],
            ]],
        ];
    }

    /** Decoded `q` param of a recorded Google Books request URL. */
    private function gbQuery(string $url): string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $params);

        return (string) ($params['q'] ?? '');
    }

    /** @return array<string> recorded request URLs, in order */
    private function recordedUrls(): array
    {
        return collect(Http::recorded())
            ->map(fn (array $pair) => $pair[0]->url())
            ->values()
            ->all();
    }

    public function test_enrich_fills_a_bare_row_via_open_library_then_google_books(): void
    {
        $row = BookLibraryTitle::create([
            'title' => 'Smile',
            'author' => 'Raina Telgemeier',
            'isbn13s' => ['9780545132060'],
        ]);

        Http::fake([
            'openlibrary.org/isbn/9780545132060.json' => Http::response([
                'works' => [['key' => '/works/OL13803124W']],
                'isbn_13' => ['9780545132060'],
                'covers' => [123],
            ]),
            'openlibrary.org/works/OL13803124W/editions.json*' => Http::response([
                'entries' => [['isbn_13' => ['9780545132053'], 'covers' => [456]]],
                'links' => [],
            ]),
            'www.googleapis.com/books/v1/volumes*' => Http::response(
                $this->gbVolume('Smile', 'PARTIAL', [
                    'industryIdentifiers' => [['type' => 'ISBN_13', 'identifier' => '9780545132060']],
                ])
            ),
        ]);

        $this->artisan('book:enrich')->assertExitCode(0);

        $row->refresh();
        $this->assertSame('OL13803124W', $row->work_key);
        $this->assertEqualsCanonicalizing(['9780545132060', '9780545132053'], $row->isbn13s);
        $this->assertSame('https://covers.openlibrary.org/b/id/123-L.jpg', $row->cover_url);
        $this->assertSame('About Smile.', $row->description);
        $this->assertSame(['Juvenile Fiction'], $row->categories);
        $this->assertSame(224, $row->page_count);
        $this->assertSame('gb-'.md5('Smile'), $row->google_books_id);
        $this->assertTrue($row->preview_available);
        $this->assertNotNull($row->enriched_at);

        // Google Books request carries the configured API key + an ISBN query.
        $gbUrl = collect($this->recordedUrls())->first(fn ($url) => str_contains($url, 'googleapis.com'));
        $this->assertStringContainsString('key=gb-test-key', $gbUrl);
        $this->assertSame('isbn:9780545132060', $this->gbQuery($gbUrl));

        $log = BookSyncLog::sole();
        $this->assertSame('enrich', $log->sync_type);
        $this->assertSame('completed', $log->status);
        $this->assertSame(1, $log->titles_processed);
        $this->assertTrue($log->metadata['exhausted']);
        $this->assertSame((string) $row->id, $log->last_cursor);
    }

    public function test_viewability_maps_partial_and_all_pages_to_true_no_pages_to_false_unknown_to_null(): void
    {
        $rows = collect([
            'PARTIAL' => 'Partial Book',
            'ALL_PAGES' => 'Full View Book',
            'NO_PAGES' => 'No Pages Book',
            'UNKNOWN' => 'Mystery Book',
        ])->map(fn ($title) => BookLibraryTitle::create(['title' => $title, 'author' => 'A. Writer']));

        // No ISBNs on any row → enrichment falls back to title+author queries.
        Http::fake(function ($request) {
            $q = $this->gbQuery($request->url());
            preg_match('/intitle:"([^"]+)"/', $q, $m);

            return Http::response($this->gbVolume($m[1], [
                'Partial Book' => 'PARTIAL',
                'Full View Book' => 'ALL_PAGES',
                'No Pages Book' => 'NO_PAGES',
                'Mystery Book' => 'UNKNOWN',
            ][$m[1]]));
        });

        $this->artisan('book:enrich')->assertExitCode(0);

        $this->assertTrue($rows['PARTIAL']->refresh()->preview_available);
        $this->assertTrue($rows['ALL_PAGES']->refresh()->preview_available);
        $this->assertFalse($rows['NO_PAGES']->refresh()->preview_available);
        $this->assertNull($rows['UNKNOWN']->refresh()->preview_available);
        // All four are stamped regardless of viewability.
        $this->assertSame(4, BookLibraryTitle::whereNotNull('enriched_at')->count());
    }

    public function test_isbn_query_miss_falls_back_to_title_author_query(): void
    {
        BookLibraryTitle::create([
            'title' => 'Hatchet',
            'author' => 'Gary Paulsen',
            'isbn13s' => ['9781416936473'],
            'work_key' => 'OL59896W', // already resolved → OL editions only, no /isbn lookup
        ]);

        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, 'openlibrary.org')) {
                return Http::response(['entries' => []]);
            }
            $q = $this->gbQuery($url);
            if (str_starts_with($q, 'isbn:')) {
                return Http::response(['totalItems' => 0]);
            }

            return Http::response($this->gbVolume('Hatchet', 'NO_PAGES'));
        });

        $this->artisan('book:enrich')->assertExitCode(0);

        $row = BookLibraryTitle::sole();
        $this->assertSame('gb-'.md5('Hatchet'), $row->google_books_id);
        $this->assertFalse($row->preview_available);

        $gbQueries = collect($this->recordedUrls())
            ->filter(fn ($url) => str_contains($url, 'googleapis.com'))
            ->map(fn ($url) => $this->gbQuery($url))
            ->values()->all();
        $this->assertSame('isbn:9781416936473', $gbQueries[0]);
        $this->assertStringContainsString('intitle:"Hatchet"', $gbQueries[1]);
        $this->assertStringContainsString('inauthor:"Gary Paulsen"', $gbQueries[1]);
        // work_key present → /isbn/ resolution must be skipped.
        $this->assertEmpty(collect($this->recordedUrls())->filter(fn ($url) => str_contains($url, '/isbn/')));
    }

    public function test_title_fallback_rejects_a_volume_whose_title_does_not_match(): void
    {
        BookLibraryTitle::create(['title' => 'Obscure Memoir', 'author' => 'Nobody Famous']);

        Http::fake([
            'www.googleapis.com/*' => Http::response($this->gbVolume('Totally Different Book', 'PARTIAL')),
        ]);

        $this->artisan('book:enrich')->assertExitCode(0);

        $row = BookLibraryTitle::sole();
        $this->assertNull($row->google_books_id);
        $this->assertNull($row->description);
        $this->assertNull($row->preview_available);
        $this->assertNotNull($row->enriched_at);
    }

    public function test_whiffed_lookups_still_stamp_enriched_at(): void
    {
        BookLibraryTitle::create([
            'title' => 'Unknown Book',
            'author' => 'Unknown Author',
            'isbn13s' => ['9781234567897'],
        ]);

        Http::fake([
            'openlibrary.org/*' => Http::response(['error' => 'notfound'], 404),
            'www.googleapis.com/*' => Http::response(['totalItems' => 0]),
        ]);

        $this->artisan('book:enrich')->assertExitCode(0);

        $row = BookLibraryTitle::sole();
        $this->assertNotNull($row->enriched_at);
        $this->assertNull($row->work_key);
        $this->assertNull($row->description);
        $this->assertNull($row->preview_available);
        $this->assertSame(['9781234567897'], $row->isbn13s);

        $log = BookSyncLog::sole();
        $this->assertSame('completed', $log->status);
        $this->assertSame(1, $log->titles_processed);
    }

    public function test_enrichment_fills_nulls_only_and_never_overwrites(): void
    {
        BookLibraryTitle::create([
            'title' => 'Holes',
            'author' => 'Louis Sachar',
            'description' => 'Original description.',
            'cover_url' => 'https://example.com/orig.jpg',
            'preview_available' => false, // non-null false must survive a PARTIAL response
        ]);

        Http::fake([
            'www.googleapis.com/*' => Http::response($this->gbVolume('Holes', 'PARTIAL')),
        ]);

        $this->artisan('book:enrich')->assertExitCode(0);

        $row = BookLibraryTitle::sole();
        $this->assertSame('Original description.', $row->description);
        $this->assertSame('https://example.com/orig.jpg', $row->cover_url);
        $this->assertFalse($row->preview_available);
        // Nulls are filled.
        $this->assertSame(['Juvenile Fiction'], $row->categories);
        $this->assertSame(224, $row->page_count);
        $this->assertSame('gb-'.md5('Holes'), $row->google_books_id);
    }

    public function test_enrich_fills_year_from_google_books_published_date(): void
    {
        BookLibraryTitle::create(['title' => 'Smile', 'author' => 'Raina Telgemeier']);

        Http::fake([
            'www.googleapis.com/*' => Http::response(
                $this->gbVolume('Smile', 'PARTIAL', ['publishedDate' => '2010-02-01'])
            ),
        ]);

        $this->artisan('book:enrich')->assertExitCode(0);

        $this->assertSame(2010, BookLibraryTitle::sole()->year);
    }

    public function test_enrich_never_overwrites_an_existing_year(): void
    {
        BookLibraryTitle::create([
            'title' => 'Smile',
            'author' => 'Raina Telgemeier',
            'year' => 1999,
        ]);

        Http::fake([
            'www.googleapis.com/*' => Http::response(
                $this->gbVolume('Smile', 'PARTIAL', ['publishedDate' => '2010-02-01'])
            ),
        ]);

        $this->artisan('book:enrich')->assertExitCode(0);

        $this->assertSame(1999, BookLibraryTitle::sole()->year);
    }

    public function test_rows_with_all_enrichable_fields_set_are_stamped_without_any_lookup(): void
    {
        BookLibraryTitle::create([
            'title' => 'Complete Row',
            'author' => 'Done Author',
            'description' => 'Done.',
            'categories' => ['Done'],
            'page_count' => 100,
            'cover_url' => 'https://example.com/done.jpg',
            'preview_available' => true,
            'google_books_id' => 'gb-done',
        ]);

        Http::fake();

        $this->artisan('book:enrich')->assertExitCode(0);

        $this->assertNotNull(BookLibraryTitle::sole()->enriched_at);
        Http::assertNothingSent();
    }

    public function test_limit_processes_n_rows_and_persists_the_cursor(): void
    {
        $first = BookLibraryTitle::create(['title' => 'First Book', 'author' => 'Author One']);
        $second = BookLibraryTitle::create(['title' => 'Second Book', 'author' => 'Author Two']);

        Http::fake(['www.googleapis.com/*' => Http::response(['totalItems' => 0])]);

        $this->artisan('book:enrich', ['--limit' => 1])->assertExitCode(0);

        $this->assertNotNull($first->refresh()->enriched_at);
        $this->assertNull($second->refresh()->enriched_at);

        $log = BookSyncLog::sole();
        $this->assertSame('completed', $log->status);
        $this->assertFalse($log->metadata['exhausted']);
        $this->assertSame((string) $first->id, $log->last_cursor);
    }

    public function test_force_reprocesses_rows_that_are_already_enriched(): void
    {
        $row = BookLibraryTitle::create([
            'title' => 'Frindle',
            'author' => 'Andrew Clements',
            'enriched_at' => now()->subWeek(),
        ]);

        Http::fake(['www.googleapis.com/*' => Http::response($this->gbVolume('Frindle', 'PARTIAL'))]);

        // Without --force the already-stamped row is not selected.
        $this->artisan('book:enrich')->assertExitCode(0);
        $this->assertNull($row->refresh()->description);
        Http::assertNothingSent();

        $this->artisan('book:enrich', ['--force' => true])->assertExitCode(0);
        $this->assertSame('About Frindle.', $row->refresh()->description);
    }

    public function test_google_books_429_stops_the_run_cleanly_without_stamping_the_row(): void
    {
        BookLibraryTitle::create(['title' => 'First Book', 'author' => 'Author One']);
        BookLibraryTitle::create(['title' => 'Second Book', 'author' => 'Author Two']);

        Http::fake(['www.googleapis.com/*' => Http::response(['error' => 'rate limited'], 429)]);

        $this->artisan('book:enrich')->assertExitCode(0);

        // Neither row stamped — the interrupted row must be retried next run.
        $this->assertSame(0, BookLibraryTitle::whereNotNull('enriched_at')->count());

        $log = BookSyncLog::sole();
        $this->assertSame('completed', $log->status);
        $this->assertFalse($log->metadata['exhausted']);
        $this->assertSame(0, $log->titles_processed);
        // The interrupted lookup's quota burn (3 attempts) must still be logged.
        $this->assertSame(3, $log->api_calls_used);
    }

    public function test_open_library_persistent_429_stops_the_run_cleanly_without_stamping_the_row(): void
    {
        BookLibraryTitle::create([
            'title' => 'First Book',
            'author' => 'Author One',
            'isbn13s' => ['9781234567897'],
        ]);
        BookLibraryTitle::create(['title' => 'Second Book', 'author' => 'Author Two']);

        Http::fake(['openlibrary.org/*' => Http::response('rate limited', 429)]);

        $this->artisan('book:enrich')->assertExitCode(0);

        // Neither row stamped — the interrupted row must be retried next run.
        $this->assertSame(0, BookLibraryTitle::whereNotNull('enriched_at')->count());

        $log = BookSyncLog::sole();
        $this->assertSame('completed', $log->status);
        $this->assertFalse($log->metadata['exhausted']);
        $this->assertSame(0, $log->titles_processed);
    }

    public function test_open_library_persistent_connection_failure_stops_the_run_cleanly_without_stamping_the_row(): void
    {
        BookLibraryTitle::create([
            'title' => 'First Book',
            'author' => 'Author One',
            'isbn13s' => ['9781234567897'],
        ]);
        BookLibraryTitle::create(['title' => 'Second Book', 'author' => 'Author Two']);

        // OL unreachable (cURL 28 connect timeout) on every retry — a transient
        // external blip must stop the run cleanly, not fail it loudly.
        Http::fake([
            'openlibrary.org/*' => Http::sequence()
                ->pushFailedConnection('cURL error 28: timed out')
                ->pushFailedConnection('cURL error 28: timed out')
                ->pushFailedConnection('cURL error 28: timed out'),
        ]);

        $this->artisan('book:enrich')->assertExitCode(0);

        // Neither row stamped — the interrupted row must be retried next run.
        $this->assertSame(0, BookLibraryTitle::whereNotNull('enriched_at')->count());

        $log = BookSyncLog::sole();
        $this->assertSame('completed', $log->status);
        $this->assertFalse($log->metadata['exhausted']);
        $this->assertSame(0, $log->titles_processed);
    }

    public function test_google_books_403_rate_limit_exceeded_stops_the_run_cleanly(): void
    {
        BookLibraryTitle::create(['title' => 'First Book', 'author' => 'Author One']);

        Http::fake([
            'www.googleapis.com/*' => Http::response([
                'error' => ['code' => 403, 'errors' => [['reason' => 'rateLimitExceeded', 'domain' => 'usageLimits']]],
            ], 403),
        ]);

        $this->artisan('book:enrich')->assertExitCode(0);

        $log = BookSyncLog::sole();
        $this->assertSame('completed', $log->status);
        $this->assertFalse($log->metadata['exhausted']);

        // Quota 403 is terminal for the run — exactly one request, no retries.
        $this->assertCount(1, $this->recordedUrls());
    }

    public function test_plain_403_fails_the_run_loudly(): void
    {
        BookLibraryTitle::create(['title' => 'First Book', 'author' => 'Author One']);

        Http::fake([
            'www.googleapis.com/*' => Http::response([
                'error' => ['code' => 403, 'errors' => [['reason' => 'forbidden']]],
            ], 403),
        ]);

        $this->artisan('book:enrich')->assertExitCode(1);

        $log = BookSyncLog::sole();
        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString('403', (string) $log->error_message);
    }

    public function test_run_stops_at_the_900_call_ceiling(): void
    {
        // 901 ISBN-less rows → exactly one GB title query each.
        $now = now();
        foreach (array_chunk(range(1, 901), 200) as $chunk) {
            DB::table('book_library_titles')->insert(array_map(fn ($i) => [
                'title' => "Ceiling Book {$i}",
                'author' => "Author {$i}",
                'normalized_title' => "ceiling book {$i}",
                'normalized_author' => "author {$i}",
                'isbn13s' => '[]',
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk));
        }

        Http::fake(['www.googleapis.com/*' => Http::response(['totalItems' => 0])]);

        $this->artisan('book:enrich')->assertExitCode(0);

        $this->assertSame(900, BookLibraryTitle::whereNotNull('enriched_at')->count());
        $this->assertNull(BookLibraryTitle::orderByDesc('id')->first()->enriched_at);

        $log = BookSyncLog::sole();
        $this->assertSame('completed', $log->status);
        $this->assertFalse($log->metadata['exhausted']);
        $this->assertSame(900, $log->api_calls_used);
        $this->assertSame(900, $log->titles_processed);
    }

    public function test_work_key_collision_skips_the_stamp_but_still_enriches(): void
    {
        BookLibraryTitle::create([
            'title' => 'Owner Book',
            'author' => 'Owner Author',
            'work_key' => 'OL1W',
            'enriched_at' => now(), // not selected this run
        ]);
        $row = BookLibraryTitle::create([
            'title' => 'Duplicate Book',
            'author' => 'Dup Author',
            'isbn13s' => ['9781234567897'],
        ]);

        Http::fake([
            'openlibrary.org/isbn/9781234567897.json' => Http::response([
                'works' => [['key' => '/works/OL1W']],
            ]),
            'www.googleapis.com/*' => Http::response(['totalItems' => 0]),
        ]);

        $this->artisan('book:enrich')->assertExitCode(0);

        $row->refresh();
        $this->assertNull($row->work_key); // unique work_key stays with the owner
        $this->assertNotNull($row->enriched_at);
        $this->assertSame('completed', BookSyncLog::sole()->status);
    }

    public function test_enrich_is_scheduled_weekly_an_hour_after_book_weekly_with_overlap_protection(): void
    {
        $events = collect($this->app->make(Schedule::class)->events())
            ->filter(fn (Event $event) => str_contains((string) $event->command, 'book:enrich'));

        // Two schedules during the backfill window: the permanent weekly run
        // and a TEMPORARY daily backfill (routes/console.php) removed once
        // book:status shows fully enriched. Both guard against overlap.
        $this->assertCount(2, $events);
        $this->assertTrue($events->every(fn (Event $event) => $event->withoutOverlapping));

        // Permanent contract: Thursdays 10:00, an hour after book:weekly (09:00).
        $weekly = $events->first(fn (Event $event) => $event->expression === '0 10 * * 4');
        $this->assertNotNull($weekly, 'expected the weekly Thursday 10:00 enrich schedule');

        // Temporary backfill: daily 10:00. When this assertion fails because the
        // cron was retired, drop it (and the count above) rather than reinstate.
        $dailyBackfill = $events->first(fn (Event $event) => $event->expression === '0 10 * * *');
        $this->assertNotNull($dailyBackfill, 'expected the temporary daily 10:00 backfill enrich schedule');
    }
}
