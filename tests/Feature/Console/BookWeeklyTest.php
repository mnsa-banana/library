<?php

namespace Tests\Feature\Console;

use App\Models\BookLibraryTitle;
use App\Models\BookListMembership;
use App\Models\BookSyncLog;
use App\Services\BookLibrary\NytClient;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BookWeeklyTest extends TestCase
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

    /** @param  array<string, mixed>  $overrides  pattern => response, matched before the defaults */
    private function fakeCurrentLists(array $overrides = []): void
    {
        Http::fake($overrides + [
            'api.nytimes.com/svc/books/v3/lists/current/picture-books.json*' => Http::response($this->fixture('nyt_picture_books_current')),
            'api.nytimes.com/svc/books/v3/lists/current/childrens-middle-grade-hardcover.json*' => Http::response($this->fixture('nyt_childrens_middle_grade_hardcover_current')),
            'api.nytimes.com/svc/books/v3/lists/current/young-adult-hardcover.json*' => Http::response($this->fixture('nyt_young_adult_hardcover_current')),
            'api.nytimes.com/svc/books/v3/lists/current/series-books.json*' => Http::response($this->fixture('nyt_series_books_current')),
            // WorkResolver step 2 probes Open Library per ISBN — unknown everywhere.
            'openlibrary.org/*' => Http::response(['error' => 'notfound'], 404),
        ]);
    }

    public function test_weekly_creates_titles_and_memberships_from_current_lists(): void
    {
        $this->fakeCurrentLists();

        $this->artisan('book:weekly')->assertExitCode(0);

        $this->assertSame(5, BookLibraryTitle::count());
        $this->assertSame(5, BookListMembership::count());

        // NYT is ALL-CAPS — titles must be Str::title'd; mapping per spec §NYT.
        $dogMan = BookLibraryTitle::where('title', 'Dog Man')->sole();
        $this->assertSame('Dav Pilkey', $dogMan->author);
        $this->assertSame(['9781338236590', '9781338236583'], $dogMan->isbn13s);
        $this->assertSame('https://storage.googleapis.com/du-prd/books/images/9781338236590.jpg', $dogMan->cover_url);
        $this->assertSame('A dog-headed police officer fights crime.', $dogMan->description);
        $this->assertSame(4, $dogMan->min_age);
        $this->assertSame('nyt', $dogMan->min_age_source);

        $membership = $dogMan->memberships()->sole();
        $this->assertSame('nyt', $membership->list_source);
        $this->assertSame('picture-books', $membership->list_key);
        $this->assertSame(1, $membership->rank);
        $this->assertSame(98, $membership->weeks_on_list);
        $this->assertSame('2026-06-14', $membership->as_of_date->toDateString());

        // Empty-string book_image/description map to null, not ''.
        $goodEgg = BookLibraryTitle::where('title', 'The Good Egg')->sole();
        $this->assertNull($goodEgg->cover_url);
        $this->assertNull($goodEgg->description);

        // min_age bands: cmg*→8, ya*→12, series-books→null.
        $this->assertSame(8, BookLibraryTitle::where('title', 'Wonder')->sole()->min_age);
        $this->assertSame(12, BookLibraryTitle::where('title', 'Divine Rivals')->sole()->min_age);
        $wings = BookLibraryTitle::where('title', 'Wings Of Fire')->sole();
        $this->assertNull($wings->min_age);
        $this->assertNull($wings->min_age_source);

        $log = BookSyncLog::sole();
        $this->assertSame('weekly', $log->sync_type);
        $this->assertSame('completed', $log->status);
        $this->assertSame(4, $log->api_calls_used);
        $this->assertSame(5, $log->titles_processed);
        $this->assertTrue($log->metadata['exhausted']);
    }

    public function test_weekly_rerun_updates_memberships_without_duplicating_rows(): void
    {
        // Next week's snapshot: Dog Man slips to rank 2 with one more week on list.
        $updated = $this->fixture('nyt_picture_books_current');
        $updated['results']['published_date'] = '2026-06-21';
        $updated['results']['books'][0]['rank'] = 2;
        $updated['results']['books'][0]['weeks_on_list'] = 99;
        $this->fakeCurrentLists([
            'api.nytimes.com/svc/books/v3/lists/current/picture-books.json*' => Http::sequence()
                ->push($this->fixture('nyt_picture_books_current'))
                ->push($updated),
        ]);

        $this->artisan('book:weekly')->assertExitCode(0);
        $this->artisan('book:weekly')->assertExitCode(0);

        $this->assertSame(5, BookLibraryTitle::count());
        $this->assertSame(5, BookListMembership::count());

        $membership = BookLibraryTitle::where('title', 'Dog Man')->sole()->memberships()->sole();
        $this->assertSame(2, $membership->rank);
        $this->assertSame(99, $membership->weeks_on_list);
        $this->assertSame('2026-06-21', $membership->as_of_date->toDateString());

        $this->assertSame(2, BookSyncLog::count());
    }

    public function test_weekly_429_stops_run_cleanly_with_cursor_and_unexhausted_metadata(): void
    {
        // First list succeeds, every later list is rate limited.
        $this->fakeCurrentLists([
            'api.nytimes.com/svc/books/v3/lists/current/picture-books.json*' => Http::response($this->fixture('nyt_picture_books_current')),
            'api.nytimes.com/*' => Http::response(['fault' => 'Rate limit quota violation'], 429),
        ]);

        $this->artisan('book:weekly')->assertExitCode(0);

        // Work from the list that succeeded before the 429 is kept.
        $this->assertSame(2, BookLibraryTitle::count());

        $log = BookSyncLog::sole();
        $this->assertSame('completed', $log->status);
        $this->assertFalse($log->metadata['exhausted']);
        $this->assertSame('childrens-middle-grade-hardcover|current', $log->last_cursor);
        $this->assertSame(2, $log->api_calls_used);
    }

    public function test_weekly_non_429_failure_exits_one_and_keeps_prior_list_work(): void
    {
        // First list succeeds, the second hits a server error.
        $this->fakeCurrentLists([
            'api.nytimes.com/svc/books/v3/lists/current/childrens-middle-grade-hardcover.json*' => Http::response([], 500),
        ]);

        $this->artisan('book:weekly')->assertExitCode(1);

        // Titles from the list that succeeded before the failure are kept.
        $this->assertSame(2, BookLibraryTitle::count());

        $log = BookSyncLog::sole();
        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString('NYT request failed (500)', (string) $log->error_message);
    }

    public function test_weekly_without_api_key_exits_zero_and_logs_failed_run(): void
    {
        config(['services.nyt.books_key' => null]);
        Http::fake();

        $this->artisan('book:weekly')->assertExitCode(0);

        $log = BookSyncLog::sole();
        $this->assertSame('weekly', $log->sync_type);
        $this->assertSame('failed', $log->status);
        Http::assertNothingSent();
    }

    public function test_weekly_is_scheduled_weekly_with_overlap_protection(): void
    {
        $events = collect($this->app->make(Schedule::class)->events())
            ->filter(fn (Event $event) => str_contains((string) $event->command, 'book:weekly'));

        $this->assertCount(1, $events);
        $this->assertSame('0 9 * * 4', $events->first()->expression);
        $this->assertTrue($events->first()->withoutOverlapping);
    }
}
