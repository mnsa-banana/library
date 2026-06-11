<?php

namespace Tests\Feature\Console;

use App\Models\BookLibraryTitle;
use App\Models\BookListMembership;
use App\Models\BookSyncLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BookStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
    }

    private function ambiguousEntry(string $title, string $source): array
    {
        return [
            'step' => 'normalized_title',
            'incoming' => ['title' => $title, 'author' => 'Tui T. Sutherland', 'isbn13s' => []],
            'candidates' => [
                ['id' => 3, 'title' => $title, 'author' => 'Tui T. Sutherland'],
                ['id' => 7, 'title' => "{$title}: Legends", 'author' => 'Tui T. Sutherland'],
            ],
            'list_source' => $source,
            'list_key' => 'series-books',
        ];
    }

    public function test_status_reports_counts_coverage_and_recent_sync_logs(): void
    {
        $enrichedA = BookLibraryTitle::create(['title' => 'Book A', 'author' => 'A', 'enriched_at' => now()]);
        $enrichedB = BookLibraryTitle::create(['title' => 'Book B', 'author' => 'B', 'enriched_at' => now()]);
        $bare = BookLibraryTitle::create(['title' => 'Book C', 'author' => 'C']);

        foreach ([$enrichedA, $enrichedB] as $title) {
            BookListMembership::create([
                'library_title_id' => $title->id,
                'list_source' => 'nyt',
                'list_key' => 'picture-books',
            ]);
        }
        BookListMembership::create([
            'library_title_id' => $bare->id,
            'list_source' => 'award',
            'list_key' => 'newbery',
        ]);

        // Six logs — only the five most recent may appear.
        $oldest = BookSyncLog::create(['sync_type' => 'seed_csm', 'status' => 'completed', 'started_at' => now()->subDays(6)]);
        foreach (range(1, 5) as $i) {
            BookSyncLog::create([
                'sync_type' => 'enrich',
                'status' => $i === 5 ? 'failed' : 'completed',
                'started_at' => now()->subDays(6 - $i),
                'api_calls_used' => $i,
                'titles_processed' => $i * 2,
            ]);
        }

        $this->artisan('book:status')
            ->expectsOutputToContain('Titles: 3 (2 enriched, 66.7%)')
            ->expectsOutputToContain('award/newbery: 1')
            ->expectsOutputToContain('nyt/picture-books: 2')
            ->assertExitCode(0);

        Artisan::call('book:status');
        $output = Artisan::output();
        $this->assertStringContainsString('failed', $output);
        $this->assertStringNotContainsString("#{$oldest->id} ", $output);
        $this->assertSame(5, substr_count($output, ' enrich '));
    }

    public function test_status_with_an_empty_library_reports_zero_without_crashing(): void
    {
        $this->artisan('book:status')
            ->expectsOutputToContain('Titles: 0')
            ->assertExitCode(0);
    }

    public function test_ambiguous_aggregates_entries_across_runs_deduped_by_title_and_source(): void
    {
        // The same unresolved match logged by two different runs → printed once.
        BookSyncLog::create([
            'sync_type' => 'seed_csm',
            'status' => 'completed',
            'started_at' => now()->subDay(),
            'metadata' => ['ambiguous' => [$this->ambiguousEntry('Wings of Fire', 'nyt')]],
        ]);
        BookSyncLog::create([
            'sync_type' => 'weekly',
            'status' => 'completed',
            'started_at' => now(),
            'metadata' => [
                'exhausted' => true,
                'ambiguous' => [
                    $this->ambiguousEntry('Wings of Fire', 'nyt'),
                    $this->ambiguousEntry('Warriors', 'csm_index'),
                ],
            ],
        ]);

        Artisan::call('book:status', ['--ambiguous' => true]);
        $output = Artisan::output();

        $this->assertSame(1, substr_count($output, '"Wings of Fire"'));
        $this->assertSame(1, substr_count($output, '"Warriors"'));
        $this->assertStringContainsString('nyt', $output);
        $this->assertStringContainsString('csm_index', $output);
        // Candidates are printed for manual resolution.
        $this->assertStringContainsString('#3', $output);
        $this->assertStringContainsString('Wings of Fire: Legends', $output);
    }

    public function test_ambiguous_with_no_entries_reports_none(): void
    {
        BookSyncLog::create(['sync_type' => 'enrich', 'status' => 'completed', 'started_at' => now()]);

        $this->artisan('book:status', ['--ambiguous' => true])
            ->expectsOutputToContain('No ambiguous matches recorded.')
            ->assertExitCode(0);
    }
}
