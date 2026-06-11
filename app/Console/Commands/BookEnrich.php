<?php

namespace App\Console\Commands;

use App\Models\BookLibraryTitle;
use App\Services\BookLibrary\GoogleBooksClient;
use App\Services\BookLibrary\GoogleBooksRateLimitedException;
use App\Services\BookLibrary\OpenLibraryClient;
use App\Services\BookLibrary\OpenLibraryRateLimitedException;
use App\Services\BookLibrary\SyncRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Metadata enrichment over book_library_titles: an Open Library pass (work
 * resolution + edition ISBN union + cover) followed by a Google Books pass
 * (description, categories, page_count, preview_available). Fill-null only —
 * seeds and earlier enrichments are never overwritten. `enriched_at` is
 * stamped for every fully processed row, even when both lookups whiff, so the
 * default `whereNull('enriched_at')` selection naturally resumes interrupted
 * runs.
 */
class BookEnrich extends Command
{
    /** Per-run ceiling on OL+GB calls combined (checked between rows). */
    private const MAX_CALLS_PER_RUN = 900;

    protected $signature = 'book:enrich
        {--limit= : Maximum rows to process this run}
        {--force : Re-process rows that already carry enriched_at}';

    protected $description = 'Enrich book library rows via Open Library and Google Books';

    public function handle(OpenLibraryClient $openLibrary, GoogleBooksClient $googleBooks): int
    {
        $limit = $this->option('limit') !== null ? max(0, (int) $this->option('limit')) : null;

        $run = SyncRun::start('enrich');
        $calls = 0;
        $processed = 0;

        try {
            // Snapshot ids up front: processing mutates enriched_at, the very
            // column the default selection filters on.
            $ids = BookLibraryTitle::query()
                ->when(! $this->option('force'), fn ($q) => $q->whereNull('enriched_at'))
                ->orderBy('id')
                ->pluck('id');

            foreach ($ids as $id) {
                if ($limit !== null && $processed >= $limit) {
                    return $this->stopRun($run, '--limit reached');
                }
                // Between-row ceiling check: a row may overshoot by its own
                // handful of calls, never by more.
                if ($calls >= self::MAX_CALLS_PER_RUN) {
                    return $this->stopRun($run, 'call ceiling reached');
                }

                $row = BookLibraryTitle::find($id);
                if ($row === null) {
                    continue;
                }

                try {
                    $calls += $this->openLibraryPass($row, $openLibrary, $run);
                    $calls += $this->googleBooksPass($row, $googleBooks, $run);
                } catch (GoogleBooksRateLimitedException|OpenLibraryRateLimitedException $e) {
                    // Clean stop: the interrupted row stays unstamped and is
                    // picked up by the next run's whereNull selection.
                    return $this->stopRun($run, $e->getMessage());
                }

                // Stamped ALWAYS — whiffed lookups must not wedge the row into
                // being re-queried every run.
                $row->enriched_at = now();
                $row->save();

                $processed++;
                $run->bumpTitles();
                $run->cursor((string) $row->id);
            }

            $run->complete(['exhausted' => true]);
            $this->info("Enriched {$processed} rows ({$calls} API calls).");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $run->fail($e->getMessage());
            $this->error("book:enrich failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    /**
     * Resolve a work for rows that have ISBNs but no work_key, then union
     * edition ISBNs + fill a missing cover from the work's editions feed.
     *
     * @return int upper-bound call count for the ceiling (workEditions may
     *             follow one pagination link → charged as 2)
     */
    private function openLibraryPass(BookLibraryTitle $row, OpenLibraryClient $openLibrary, SyncRun $run): int
    {
        $calls = 0;
        $isbns = $row->isbn13s ?? [];

        if ($row->work_key === null && $isbns !== []) {
            foreach ($isbns as $isbn) {
                $resolved = $openLibrary->resolveIsbn($isbn);
                $calls++;
                $run->bumpApiCalls();
                if ($resolved === null) {
                    continue;
                }

                // work_key is unique — when another row already carries it the
                // two rows are duplicates of one OL work; skip the stamp but
                // surface the corruption (same policy as WorkResolver::merge).
                $conflict = BookLibraryTitle::where('work_key', $resolved['work_key'])
                    ->where('id', '!=', $row->id)
                    ->first();
                if ($conflict === null) {
                    $row->work_key = $resolved['work_key'];
                } else {
                    Log::warning('book-library: enrich work_key collision, skipping stamp', [
                        'work_key' => $resolved['work_key'],
                        'row' => ['id' => $row->id, 'title' => $row->title],
                        'conflicting' => ['id' => $conflict->id, 'title' => $conflict->title],
                    ]);
                }

                $row->isbn13s = array_values(array_unique(array_merge($isbns, $resolved['isbn13s'])));
                $row->cover_url ??= $resolved['cover_url'];
                break;
            }
        }

        if ($row->work_key !== null) {
            $editions = $openLibrary->workEditions($row->work_key);
            $calls += 2;
            $run->bumpApiCalls(2);

            $row->isbn13s = array_values(array_unique(array_merge($row->isbn13s ?? [], $editions['isbn13s'])));
            $row->cover_url ??= $editions['cover_url'];
        }

        return $calls;
    }

    /**
     * Fill description/categories/page_count/preview_available/
     * google_books_id (+ a still-missing cover) from the best Google Books
     * volume. Skipped entirely when nothing is left to fill — quota is the
     * scarce resource here.
     *
     * @return int calls charged against the ceiling (actual request count)
     */
    private function googleBooksPass(BookLibraryTitle $row, GoogleBooksClient $googleBooks, SyncRun $run): int
    {
        $unfilled = $row->description === null
            || $row->categories === null
            || $row->page_count === null
            || $row->preview_available === null
            || $row->google_books_id === null
            || $row->cover_url === null;
        if (! $unfilled) {
            return 0;
        }

        $before = $googleBooks->callsUsed();
        try {
            $result = $googleBooks->lookup($row->isbn13s ?? [], $row->title, $row->author);
        } finally {
            // Charged even when lookup throws (rate limit): the interrupted
            // call's quota burn must still land in the run log.
            $calls = $googleBooks->callsUsed() - $before;
            $run->bumpApiCalls($calls);
        }

        if ($result !== null) {
            $row->description ??= $result['description'];
            $row->categories ??= $result['categories'];
            $row->page_count ??= $result['page_count'];
            $row->preview_available ??= $result['preview_available'];
            $row->google_books_id ??= $result['google_books_id'];
            $row->cover_url ??= $result['cover_url'];
        }

        return $calls;
    }

    /** Clean stop (limit / ceiling / rate limit): completed, exhausted=false. */
    private function stopRun(SyncRun $run, string $reason): int
    {
        $run->complete(['exhausted' => false]);
        $this->warn("Stopped ({$reason}); remaining rows stay unstamped — rerun later.");

        return self::SUCCESS;
    }
}
