<?php

namespace Tests\Unit\Services\BookLibrary;

use App\Models\BookLibraryTitle;
use App\Models\BookListMembership;
use App\Models\BookSyncLog;
use App\Services\BookLibrary\IngestService;
use App\Services\BookLibrary\OpenLibraryClient;
use App\Services\BookLibrary\SyncRun;
use App\Services\BookLibrary\WorkResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IngestServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Ingest tests never hit Open Library: items carry no isbn13s, so the
        // resolver's OL step is skipped entirely.
        Http::preventStrayRequests();
        Http::fake();
    }

    private function service(): IngestService
    {
        return new IngestService(new WorkResolver(new OpenLibraryClient));
    }

    // ── Create + membership ───────────────────────────────────────────────

    public function test_ingest_rejects_items_missing_list_source_or_list_key(): void
    {
        $run = SyncRun::start('weekly');

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->ingest(['title' => 'Frindle'], $run);
    }

    public function test_ingest_creates_title_and_membership_and_bumps_titles(): void
    {
        $run = SyncRun::start('seed_nyt_history');

        $title = $this->service()->ingest([
            'title' => 'Frindle',
            'author' => 'Andrew Clements',
            'year' => 1996,
            'list_source' => 'nyt',
            'list_key' => 'childrens-middle-grade',
            'rank' => 3,
            'weeks_on_list' => 12,
            'as_of_date' => '2014-05-04',
            'review_url' => 'https://example.com/frindle',
            'metadata' => ['publisher' => 'Simon & Schuster'],
        ], $run);

        $this->assertNotNull($title);
        $this->assertSame('Frindle', $title->title);
        $this->assertSame(1, BookLibraryTitle::count());

        $membership = BookListMembership::sole();
        $this->assertSame($title->id, $membership->library_title_id);
        $this->assertSame('nyt', $membership->list_source);
        $this->assertSame('childrens-middle-grade', $membership->list_key);
        $this->assertSame(3, $membership->rank);
        $this->assertSame(12, $membership->weeks_on_list);
        $this->assertSame('2014-05-04', $membership->as_of_date->toDateString());
        $this->assertSame('https://example.com/frindle', $membership->review_url);
        $this->assertSame(['publisher' => 'Simon & Schuster'], $membership->metadata);

        $run->complete();
        $log = BookSyncLog::sole();
        $this->assertSame(1, $log->titles_processed);
        $this->assertSame('completed', $log->status);

        Http::assertNothingSent();
    }

    public function test_reingest_updates_membership_in_place_without_duplicate_row(): void
    {
        $run = SyncRun::start('weekly');
        $service = $this->service();

        $first = $service->ingest([
            'title' => 'Holes',
            'author' => 'Louis Sachar',
            'list_source' => 'nyt',
            'list_key' => 'young-adult',
            'rank' => 5,
            'weeks_on_list' => 2,
            'as_of_date' => '2026-05-01',
        ], $run);

        $second = $service->ingest([
            'title' => 'Holes',
            'author' => 'Louis Sachar',
            'list_source' => 'nyt',
            'list_key' => 'young-adult',
            'rank' => 1,
            'weeks_on_list' => 3,
            'as_of_date' => '2026-05-08',
        ], $run);

        $this->assertTrue($second->is($first));
        $this->assertSame(1, BookLibraryTitle::count());
        $this->assertSame(1, BookListMembership::count());

        $membership = BookListMembership::sole();
        $this->assertSame(1, $membership->rank);
        $this->assertSame(3, $membership->weeks_on_list);
        $this->assertSame('2026-05-08', $membership->as_of_date->toDateString());

        $run->complete();
        $this->assertSame(2, BookSyncLog::sole()->titles_processed);
    }

    public function test_older_dated_ingest_does_not_clobber_newer_dated_membership(): void
    {
        // The NYT history backfill walks newest→oldest, so the second ingest
        // for a multi-week title carries OLDER stats — they must be dropped.
        $run = SyncRun::start('seed_nyt_history');
        $service = $this->service();

        $service->ingest([
            'title' => 'Holes',
            'author' => 'Louis Sachar',
            'list_source' => 'nyt',
            'list_key' => 'young-adult',
            'rank' => 3,
            'weeks_on_list' => 12,
            'as_of_date' => '2025-01-15',
            'review_url' => 'https://example.com/newer',
            'metadata' => ['publisher' => 'Newer House'],
        ], $run);

        $service->ingest([
            'title' => 'Holes',
            'author' => 'Louis Sachar',
            'list_source' => 'nyt',
            'list_key' => 'young-adult',
            'rank' => 9,
            'weeks_on_list' => 10,
            'as_of_date' => '2025-01-03',
            'review_url' => 'https://example.com/older',
            'metadata' => ['publisher' => 'Older House'],
        ], $run);

        $membership = BookListMembership::sole();
        $this->assertSame(3, $membership->rank);
        $this->assertSame(12, $membership->weeks_on_list);
        $this->assertSame('2025-01-15', $membership->as_of_date->toDateString());
        $this->assertSame('https://example.com/newer', $membership->review_url);
        $this->assertSame(['publisher' => 'Newer House'], $membership->metadata);
    }

    public function test_same_source_null_dated_reingest_still_updates_membership(): void
    {
        // csm/pluggedin/wkar/award memberships never carry as_of_date; a
        // re-seed (both sides null) must still refresh review_url/metadata.
        $run = SyncRun::start('seed_csm');
        $service = $this->service();

        $service->ingest([
            'title' => 'Holes',
            'author' => 'Louis Sachar',
            'list_source' => 'csm_index',
            'list_key' => 'index',
            'review_url' => 'https://example.com/old-review',
        ], $run);

        $service->ingest([
            'title' => 'Holes',
            'author' => 'Louis Sachar',
            'list_source' => 'csm_index',
            'list_key' => 'index',
            'review_url' => 'https://example.com/new-review',
        ], $run);

        $membership = BookListMembership::sole();
        $this->assertNull($membership->as_of_date);
        $this->assertSame('https://example.com/new-review', $membership->review_url);
    }

    public function test_distinct_list_keys_create_separate_memberships_for_same_title(): void
    {
        $run = SyncRun::start('seed_nyt_history');
        $service = $this->service();

        $service->ingest([
            'title' => 'Holes',
            'author' => 'Louis Sachar',
            'list_source' => 'nyt',
            'list_key' => 'young-adult',
        ], $run);
        $service->ingest([
            'title' => 'Holes',
            'author' => 'Louis Sachar',
            'list_source' => 'nyt',
            'list_key' => 'childrens-middle-grade',
        ], $run);

        $this->assertSame(1, BookLibraryTitle::count());
        $this->assertSame(2, BookListMembership::count());
    }

    // ── Ambiguous ─────────────────────────────────────────────────────────

    public function test_ambiguous_match_is_logged_to_sync_run_and_returns_null(): void
    {
        BookLibraryTitle::factory()->create(['title' => 'Holes', 'author' => null]);
        BookLibraryTitle::factory()->create(['title' => 'Holes', 'author' => null]);

        $run = SyncRun::start('seed_csm');

        $result = $this->service()->ingest([
            'title' => 'Holes',
            'author' => 'Louis Sachar',
            'list_source' => 'csm_index',
            'list_key' => 'index',
        ], $run);

        $this->assertNull($result);
        $this->assertSame(0, BookListMembership::count());

        $run->complete();
        $log = BookSyncLog::sole();
        $this->assertSame(0, $log->titles_processed);
        $this->assertCount(1, $log->metadata['ambiguous']);

        $entry = $log->metadata['ambiguous'][0];
        $this->assertSame('Holes', $entry['incoming']['title']);
        $this->assertSame('normalized_title', $entry['step']);
        $this->assertCount(2, $entry['candidates']);
        // List context rides along so `book:status --ambiguous` can dedupe
        // by incoming title + source.
        $this->assertSame('csm_index', $entry['list_source']);
        $this->assertSame('index', $entry['list_key']);
    }

    // ── Merge (fill-null) ─────────────────────────────────────────────────

    public function test_fill_null_merge_does_not_overwrite_stored_author(): void
    {
        $row = BookLibraryTitle::factory()->create([
            'title' => 'The Giver',
            'author' => 'Lois Lowry',
            'year' => 1993,
            'description' => null,
        ]);

        $run = SyncRun::start('seed_csm');

        $result = $this->service()->ingest([
            'title' => 'The Giver',
            'author' => 'L. Lowry',
            'year' => 2018,
            'description' => 'A boy discovers the dark secrets behind his community.',
            'list_source' => 'csm_index',
            'list_key' => 'index',
        ], $run);

        $this->assertTrue($result->is($row));
        $fresh = $row->fresh();
        $this->assertSame('Lois Lowry', $fresh->author);
        $this->assertSame(1993, $fresh->year);
        $this->assertSame('A boy discovers the dark secrets behind his community.', $fresh->description);
    }

    // ── min_age provenance ────────────────────────────────────────────────

    public function test_min_age_nyt_then_csm_csm_wins(): void
    {
        $run = SyncRun::start('seed_nyt_history');
        $service = $this->service();

        $service->ingest([
            'title' => 'Frindle',
            'author' => 'Andrew Clements',
            'min_age' => 8,
            'min_age_source' => 'nyt',
            'list_source' => 'nyt',
            'list_key' => 'childrens-middle-grade',
        ], $run);

        $title = $service->ingest([
            'title' => 'Frindle',
            'author' => 'Andrew Clements',
            'min_age' => 9,
            'min_age_source' => 'csm_index',
            'list_source' => 'csm_index',
            'list_key' => 'index',
        ], $run);

        $fresh = $title->fresh();
        $this->assertSame(9, $fresh->min_age);
        $this->assertSame('csm_index', $fresh->min_age_source);
    }

    public function test_min_age_csm_then_nyt_csm_retained(): void
    {
        $run = SyncRun::start('seed_csm');
        $service = $this->service();

        $service->ingest([
            'title' => 'Frindle',
            'author' => 'Andrew Clements',
            'min_age' => 9,
            'min_age_source' => 'csm_index',
            'list_source' => 'csm_index',
            'list_key' => 'index',
        ], $run);

        $title = $service->ingest([
            'title' => 'Frindle',
            'author' => 'Andrew Clements',
            'min_age' => 8,
            'min_age_source' => 'nyt',
            'list_source' => 'nyt',
            'list_key' => 'childrens-middle-grade',
        ], $run);

        $fresh = $title->fresh();
        $this->assertSame(9, $fresh->min_age);
        $this->assertSame('csm_index', $fresh->min_age_source);
    }

    public function test_min_age_equal_rank_resync_updates_value(): void
    {
        $run = SyncRun::start('seed_csm');
        $service = $this->service();

        $service->ingest([
            'title' => 'Frindle',
            'author' => 'Andrew Clements',
            'min_age' => 8,
            'min_age_source' => 'csm_index',
            'list_source' => 'csm_index',
            'list_key' => 'index',
        ], $run);

        $title = $service->ingest([
            'title' => 'Frindle',
            'author' => 'Andrew Clements',
            'min_age' => 9,
            'min_age_source' => 'csm_index',
            'list_source' => 'csm_index',
            'list_key' => 'index',
        ], $run);

        $this->assertSame(9, $title->fresh()->min_age);
    }

    public function test_min_age_written_when_stored_source_is_null(): void
    {
        BookLibraryTitle::factory()->create([
            'title' => 'Hatchet',
            'author' => 'Gary Paulsen',
            'min_age' => null,
            'min_age_source' => null,
        ]);

        $run = SyncRun::start('seed_nyt_history');

        $title = $this->service()->ingest([
            'title' => 'Hatchet',
            'author' => 'Gary Paulsen',
            'min_age' => 10,
            'min_age_source' => 'nyt',
            'list_source' => 'nyt',
            'list_key' => 'young-adult',
        ], $run);

        $fresh = $title->fresh();
        $this->assertSame(10, $fresh->min_age);
        $this->assertSame('nyt', $fresh->min_age_source);
    }

    // ── SyncRun lifecycle ─────────────────────────────────────────────────

    public function test_sync_run_start_creates_running_log_row(): void
    {
        SyncRun::start('seed_wkar');

        $log = BookSyncLog::sole();
        $this->assertSame('seed_wkar', $log->sync_type);
        $this->assertSame('running', $log->status);
        $this->assertNotNull($log->started_at);
        $this->assertNull($log->completed_at);
    }

    public function test_cursor_persists_immediately_for_resume_safety(): void
    {
        $run = SyncRun::start('seed_csm');
        $run->bumpApiCalls(2);
        $run->cursor('https://example.com/book-reviews/page-42');

        // No complete()/fail() — the row must already reflect the cursor and
        // any counters bumped so far.
        $log = BookSyncLog::sole();
        $this->assertSame('https://example.com/book-reviews/page-42', $log->last_cursor);
        $this->assertSame(2, $log->api_calls_used);
        $this->assertSame('running', $log->status);
    }

    public function test_last_cursor_returns_most_recent_non_null_cursor_for_sync_type(): void
    {
        $a = SyncRun::start('seed_csm');
        $a->cursor('cursor-a');
        $a->fail('interrupted');

        $b = SyncRun::start('seed_csm');
        $b->cursor('cursor-b');
        $b->fail('interrupted again');

        $c = SyncRun::start('seed_csm');
        $c->cursor(null);
        $c->fail('no progress');

        SyncRun::start('seed_pluggedin')->cursor('other-type-cursor');

        $this->assertSame('cursor-b', SyncRun::lastCursor('seed_csm'));
        $this->assertNull(SyncRun::lastCursor('seed_nyt_history'));
    }

    public function test_complete_merges_metadata_and_stamps_completed_at(): void
    {
        $run = SyncRun::start('seed_csm');
        $run->bumpApiCalls(5);
        $run->bumpTitles(3);
        $run->complete(['urls_total' => 100]);

        $log = BookSyncLog::sole();
        $this->assertSame('completed', $log->status);
        $this->assertNotNull($log->completed_at);
        $this->assertSame(5, $log->api_calls_used);
        $this->assertSame(3, $log->titles_processed);
        $this->assertSame(100, $log->metadata['urls_total']);
    }

    public function test_fail_records_status_and_error_message(): void
    {
        $run = SyncRun::start('weekly');
        $run->fail('NYT 429: rate limited');

        $log = BookSyncLog::sole();
        $this->assertSame('failed', $log->status);
        $this->assertSame('NYT 429: rate limited', $log->error_message);
        $this->assertNotNull($log->completed_at);
    }
}
