<?php

namespace App\Console\Commands;

use App\Models\BookListMembership;
use App\Services\BookLibrary\CsmIndexScraper;
use App\Services\BookLibrary\IngestService;
use App\Services\BookLibrary\NytClient;
use App\Services\BookLibrary\NytListNotFoundException;
use App\Services\BookLibrary\NytRateLimitedException;
use App\Services\BookLibrary\OpenLibraryRateLimitedException;
use App\Services\BookLibrary\PluggedInIndexScraper;
use App\Services\BookLibrary\SyncRun;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Book-library seed entry point. Each `--source` is an independent arm with
 * its own sync_type, cursor format, and resume semantics. The wkar/award arms
 * are local-file imports (no HTTP of their own; small files, no cursor —
 * re-runs upsert).
 */
class BookSeed extends Command
{
    /** NYT allows ~500 calls/day — leave headroom for book:weekly. */
    private const NYT_MAX_CALLS_PER_RUN = 450;

    /** WKAR grade band → min_age (spec §Seed sources; min_age_source='wkar'). */
    private const WKAR_GRADE_BAND_MIN_AGE = ['K-2' => 5, '3-5' => 8, '6-8' => 11, '9-12' => 14];

    protected $signature = 'book:seed
        {--source= : Seed source (csm|pluggedin|nyt-history|wkar|award)}
        {--file= : Path to a structured JSON import file (wkar|award)}
        {--limit= : Maximum pages/entries to process this run}
        {--resume : Continue from the last persisted cursor for this source}
        {--delta : csm|pluggedin only — fetch review pages for NEW urls only (incremental update)}';

    protected $description = 'Seed the book library from a list source';

    public function handle(NytClient $nyt, CsmIndexScraper $csm, PluggedInIndexScraper $pluggedin, IngestService $ingest): int
    {
        if ($this->option('delta') && $this->option('resume')) {
            // A delta recomputes "new" from the DB on every run — resuming
            // one is meaningless, and the inherited cursor would silently
            // skip every new URL sorting at or below it.
            $this->error('--delta and --resume are mutually exclusive (a delta re-derives newness each run).');

            return self::INVALID;
        }

        return match ($this->option('source')) {
            'csm' => $this->seedCsm($csm, $ingest),
            'pluggedin' => $this->seedPluggedIn($pluggedin, $ingest),
            'nyt-history' => $this->seedNytHistory($nyt, $ingest),
            'wkar' => $this->seedWkar($ingest),
            'award' => $this->seedAward($ingest),
            default => $this->invalidSource(),
        };
    }

    /**
     * Delta mode (--delta): drop URLs that already carry a membership row for
     * this source, so the per-page fetch — the expensive, politeness-paced
     * part — runs only for genuinely NEW reviews. A weekly delta is minutes;
     * metadata refreshes for already-known pages (e.g. after changing what
     * gets extracted) still require a full walk without the flag.
     *
     * Known cost: pages that never yield a membership (roundup posts, parse
     * misses, resolver-ambiguous titles) have no row to key on and are
     * re-fetched every delta run — dozens of URLs ≈ seconds at the
     * politeness rate, accepted in lieu of a per-URL skip ledger.
     *
     * Delta runs never persist a cursor (see the guards at the call sites):
     * SyncRun::lastCursor is per sync_type, so a delta cursor on a newer row
     * would shadow an interrupted FULL walk's resume point and make
     * --resume skip the unwalked gap.
     */
    private function deltaFilter(array $urls, string $listSource): array
    {
        if (! $this->option('delta')) {
            return $urls;
        }

        $known = BookListMembership::query()
            ->where('list_source', $listSource)
            ->whereNotNull('review_url')
            ->pluck('review_url')
            ->flip();

        return array_values(array_filter($urls, fn (string $url) => ! isset($known[$url])));
    }

    /**
     * Shared per-arm run boilerplate: start the sync-log row, delegate to the
     * arm body, and convert any uncaught throwable into a failed run +
     * FAILURE exit. Key checks and resume parsing stay arm-local (they may
     * need to fail a run before any body work starts).
     */
    private function runSeed(string $syncType, string $label, \Closure $body): int
    {
        $run = SyncRun::start($syncType);

        try {
            return $body($run);
        } catch (\Throwable $e) {
            $run->fail($e->getMessage());
            $this->error("book:seed {$label} failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    /** Normalized --limit: null when absent, never negative. */
    private function limitOption(): ?int
    {
        $limit = $this->option('limit');

        return $limit !== null ? max(0, (int) $limit) : null;
    }

    /**
     * CSM index seed: two-level sitemap walk for the sorted /book-reviews/
     * URL list, then one polite fetch per review page for JSON-LD metadata
     * (CsmIndexScraper owns the robots constraints — sitemap-only `?page=`
     * pagination, plain UA, 1 req/s). Cursor = last processed URL within the
     * sorted list; `--resume` skips every URL ≤ cursor (string order matches
     * the sorted walk). A failed page fetch/parse is logged and skipped —
     * never fatal.
     */
    private function seedCsm(CsmIndexScraper $csm, IngestService $ingest): int
    {
        $limit = $this->limitOption();
        $cursor = $this->option('resume') ? SyncRun::lastCursor('seed_csm') : null;

        return $this->runSeed('seed_csm', 'csm', function (SyncRun $run) use ($csm, $ingest, $limit, $cursor) {
            $urls = $csm->slugUrls();

            // A zero-URL walk would otherwise complete exhausted=true and be
            // indistinguishable from a finished seed.
            if ($urls === []) {
                $run->fail('CSM sitemap walk returned no book-review URLs');
                $this->error('CSM sitemap walk returned no book-review URLs.');

                return self::FAILURE;
            }

            // Delta runs AFTER the zero-walk check: an empty raw walk is a
            // broken sitemap (fail), an empty delta is a healthy no-op.
            $urls = $this->deltaFilter($urls, 'csm_index');
            if ($urls === []) {
                $run->complete(['exhausted' => true, 'delta' => true]);
                $this->info('CSM delta: no new review pages.');

                return self::SUCCESS;
            }

            $processed = 0;
            $lastProcessed = null;
            foreach ($urls as $url) {
                if ($cursor !== null && $url <= $cursor) {
                    continue;
                }
                if ($limit !== null && $processed >= $limit) {
                    $run->complete(['exhausted' => false]);
                    $this->warn('Stopped (--limit reached); cursor persisted — rerun with --resume.');

                    return self::SUCCESS;
                }

                // api_calls counts review-page fetches; the (cheap, fixed)
                // sitemap walk is untracked.
                $meta = $csm->reviewPageMeta($url);
                $run->bumpApiCalls();
                $processed++;

                if ($meta === null) {
                    // Already logged with detail by the scraper.
                    $this->warn("CSM review page skipped (fetch/parse miss): {$url}");
                } else {
                    try {
                        $ingest->ingest([
                            'title' => $meta['title'],
                            'author' => $meta['author'],
                            'isbn13s' => $meta['isbn13s'],
                            'min_age' => $meta['min_age'],
                            'min_age_source' => $meta['min_age'] === null ? null : 'csm_index',
                            'list_source' => 'csm_index',
                            'list_key' => 'index',
                            'review_url' => $url,
                        ], $run);
                    } catch (OpenLibraryRateLimitedException) {
                        return $this->stopOnOpenLibraryRateLimit($run, $url, $lastProcessed, $cursor);
                    } catch (QueryException $e) {
                        // A poison row (bad scraped data the DB rejects) is
                        // skipped like a parse miss — the cursor still
                        // advances below, so --resume cannot wedge on this
                        // URL forever. Safe even for a persistent DB outage:
                        // the subsequent $run->cursor() save throws OUTSIDE
                        // this catch and fails the run fast.
                        Log::warning('book-library: csm ingest skipped (query exception)', [
                            'url' => $url,
                            'message' => $e->getMessage(),
                        ]);
                        $this->warn("CSM review page skipped (query exception): {$url}");
                    }
                }

                // Resume-safety checkpoint: persists immediately. Skipped
                // pages advance it too — --resume must not re-grind a
                // permanently broken page. Delta runs persist NO cursor:
                // it would shadow a full walk's resume point (lastCursor is
                // per sync_type) and a delta needs no resume anyway.
                if (! $this->option('delta')) {
                    $run->cursor($url);
                }
                $lastProcessed = $url;
            }

            $run->complete(['exhausted' => true]);
            $this->info("CSM seed exhausted after {$processed} review pages.");

            return self::SUCCESS;
        });
    }

    /**
     * Plugged In index seed — deliberately parallel to seedCsm (arm
     * duplication over premature per-source restructuring): sitemap walk for
     * the sorted /book-reviews/ URL list, then one polite fetch per review
     * page for title/author (Plugged In's listing pages carry no authors and
     * its pages no JSON-LD — title/author is all there is; dedup leans on the
     * resolver). Cursor = last processed URL within the sorted list;
     * `--resume` skips every URL ≤ cursor. A failed page fetch/parse is
     * logged and skipped — never fatal.
     */
    private function seedPluggedIn(PluggedInIndexScraper $pluggedin, IngestService $ingest): int
    {
        $limit = $this->limitOption();
        $cursor = $this->option('resume') ? SyncRun::lastCursor('seed_pluggedin') : null;

        return $this->runSeed('seed_pluggedin', 'pluggedin', function (SyncRun $run) use ($pluggedin, $ingest, $limit, $cursor) {
            $urls = $pluggedin->reviewUrls();

            // A zero-URL walk would otherwise complete exhausted=true and be
            // indistinguishable from a finished seed.
            if ($urls === []) {
                $run->fail('Plugged In sitemap walk returned no book-review URLs');
                $this->error('Plugged In sitemap walk returned no book-review URLs.');

                return self::FAILURE;
            }

            // Delta runs AFTER the zero-walk check: an empty raw walk is a
            // broken sitemap (fail), an empty delta is a healthy no-op.
            $urls = $this->deltaFilter($urls, 'pluggedin_index');
            if ($urls === []) {
                $run->complete(['exhausted' => true, 'delta' => true]);
                $this->info('Plugged In delta: no new review pages.');

                return self::SUCCESS;
            }

            $processed = 0;
            $lastProcessed = null;
            foreach ($urls as $url) {
                if ($cursor !== null && $url <= $cursor) {
                    continue;
                }
                if ($limit !== null && $processed >= $limit) {
                    $run->complete(['exhausted' => false]);
                    $this->warn('Stopped (--limit reached); cursor persisted — rerun with --resume.');

                    return self::SUCCESS;
                }

                // api_calls counts review-page fetches; the (cheap, fixed)
                // sitemap walk is untracked.
                $meta = $pluggedin->reviewPageMeta($url);
                $run->bumpApiCalls();
                $processed++;

                if ($meta === null) {
                    // Already logged with detail by the scraper.
                    $this->warn("Plugged In review page skipped (fetch/parse miss): {$url}");
                } else {
                    // No ISBN on Plugged In pages; min_age comes from the
                    // review byline's age band when present.
                    try {
                        $ingest->ingest([
                            'title' => $meta['title'],
                            'author' => $meta['author'],
                            'min_age' => $meta['min_age'],
                            'min_age_source' => $meta['min_age'] === null ? null : 'pluggedin_index',
                            'list_source' => 'pluggedin_index',
                            'list_key' => 'index',
                            'review_url' => $url,
                        ], $run);
                    } catch (OpenLibraryRateLimitedException) {
                        return $this->stopOnOpenLibraryRateLimit($run, $url, $lastProcessed, $cursor);
                    } catch (QueryException $e) {
                        // A poison row (bad scraped data the DB rejects) is
                        // skipped like a parse miss — the cursor still
                        // advances below, so --resume cannot wedge on this
                        // URL forever. Safe even for a persistent DB outage:
                        // the subsequent $run->cursor() save throws OUTSIDE
                        // this catch and fails the run fast.
                        Log::warning('book-library: pluggedin ingest skipped (query exception)', [
                            'url' => $url,
                            'message' => $e->getMessage(),
                        ]);
                        $this->warn("Plugged In review page skipped (query exception): {$url}");
                    }
                }

                // Resume-safety checkpoint: persists immediately. Skipped
                // pages advance it too — --resume must not re-grind a
                // permanently broken page. Delta runs persist NO cursor:
                // it would shadow a full walk's resume point (lastCursor is
                // per sync_type) and a delta needs no resume anyway.
                if (! $this->option('delta')) {
                    $run->cursor($url);
                }
                $lastProcessed = $url;
            }

            $run->complete(['exhausted' => true]);
            $this->info("Plugged In seed exhausted after {$processed} review pages.");

            return self::SUCCESS;
        });
    }

    /**
     * Backfill the NYT children's lists: per HISTORY_LISTS slug, start from
     * the lists/names newest date (or the cursor on --resume) and walk each
     * response's previous_published_date — never date arithmetic — until it
     * is empty or older than that list's oldest_published_date.
     */
    private function seedNytHistory(NytClient $nyt, IngestService $ingest): int
    {
        if (! config('services.nyt.books_key')) {
            SyncRun::start('seed_nyt_history')->fail('NYT_BOOKS_API_KEY is not configured');
            $this->error('book:seed --source=nyt-history requires NYT_BOOKS_API_KEY.');

            return self::FAILURE;
        }

        $limit = $this->limitOption();
        [$resumeList, $resumeDate] = $this->nytResumePoint();

        return $this->runSeed('seed_nyt_history', 'nyt-history', function (SyncRun $run) use ($nyt, $ingest, $limit, $resumeList, $resumeDate) {
            // No discovery call: NYT removed /lists/names.json (mid-2026).
            // Each list's walk starts at 'current' and follows the
            // previous_published_date chain; the chain ending (empty
            // previous_published_date) is the history floor, and a 404
            // ("list not found") marks a retired slug to skip.
            $calls = 0;
            $pages = 0;

            $lists = NytClient::HISTORY_LISTS;
            $start = $resumeList !== null ? array_search($resumeList, $lists, true) : 0;
            if ($start === false) {
                [$start, $resumeDate] = [0, null];
            }

            foreach (array_slice($lists, (int) $start) as $offset => $list) {
                $date = $offset === 0 && $resumeDate !== null ? $resumeDate : 'current';

                while (is_string($date) && $date !== '') {
                    if ($calls >= self::NYT_MAX_CALLS_PER_RUN) {
                        return $this->stopNytRun($run, "{$list}|{$date}", 'call budget reached');
                    }
                    if ($limit !== null && $pages >= $limit) {
                        return $this->stopNytRun($run, "{$list}|{$date}", '--limit reached');
                    }

                    try {
                        $results = $nyt->listForDate($list, $date);
                    } catch (NytRateLimitedException) {
                        $run->bumpApiCalls();

                        return $this->stopNytRun($run, "{$list}|{$date}", 'NYT 429');
                    } catch (NytListNotFoundException) {
                        // Retired slug (pre-2015 split lists are gone from
                        // the API) or a chain that walked past NYT's history
                        // floor — skip to the next list.
                        $run->bumpApiCalls();
                        $calls++;
                        $this->warn("NYT has no list '{$list}' at {$date}; skipping.");

                        break;
                    }
                    $run->bumpApiCalls();
                    $calls++;
                    $pages++;

                    $pd = $results['published_date'] ?? null;
                    // Guard '' as well as null: cursoring "{list}|" would
                    // make a later --resume silently skip the whole list
                    // (nytResumePoint would hand back an empty resume date).
                    $asOfDate = is_string($pd) && $pd !== '' ? $pd : $date;
                    try {
                        foreach ($results['books'] ?? [] as $book) {
                            $item = NytClient::ingestItem($book, $list, $asOfDate);
                            if ($item['title'] === '') {
                                continue;
                            }
                            $ingest->ingest($item, $run);
                        }
                    } catch (OpenLibraryRateLimitedException) {
                        // Cursor at the current page: --resume re-fetches it,
                        // so the partially ingested page is re-processed
                        // (memberships upsert — harmless).
                        return $this->stopNytRun($run, "{$list}|{$date}", 'Open Library 429');
                    }

                    // Resume-safety checkpoint after every page; re-fetching
                    // one already-processed page on --resume is harmless
                    // (memberships upsert). Cursor uses the page's REAL
                    // published_date so a run stopped on the 'current' entry
                    // page resumes from a stable date, not a moving alias.
                    $run->cursor("{$list}|{$asOfDate}");
                    $this->info("Seeded {$list} {$asOfDate}.");

                    $previous = $results['previous_published_date'] ?? null;
                    $date = is_string($previous) && $previous !== '' ? $previous : null;
                }
            }

            $run->complete(['exhausted' => true]);
            $this->info("NYT history backfill exhausted after {$calls} calls.");

            return self::SUCCESS;
        });
    }

    /**
     * WKAR ("What Kids Are Reading") import: structured JSON extracted by hand
     * from the annual Renaissance report (no AR BookFinder scraping — see
     * database/data/book_library/wkar/README.md for the shape and the
     * extraction procedure). One list per report year (`list_key` = year);
     * grade band rides in membership metadata and drives min_age with
     * min_age_source='wkar' (provenance: csm_index outranks it).
     */
    private function seedWkar(IngestService $ingest): int
    {
        $limit = $this->limitOption();

        return $this->runSeed('seed_wkar', 'wkar', function (SyncRun $run) use ($ingest, $limit) {
            $entries = $this->loadImportFile();
            $processed = 0;

            foreach ($entries as $entry) {
                if ($limit !== null && $processed >= $limit) {
                    $run->complete(['exhausted' => false]);
                    $this->warn('Stopped (--limit reached); re-run without --limit to finish (upserts are safe).');

                    return self::SUCCESS;
                }

                $title = $entry['title'] ?? null;
                $year = $entry['year'] ?? null;
                if (! is_string($title) || trim($title) === '' || ! is_int($year)) {
                    $this->warn('WKAR entry skipped (title and integer year are required): '.json_encode($entry));

                    continue;
                }
                $processed++;

                $gradeBand = $entry['grade_band'] ?? null;
                $minAge = is_string($gradeBand) ? (self::WKAR_GRADE_BAND_MIN_AGE[$gradeBand] ?? null) : null;

                $ingest->ingest([
                    'title' => $title,
                    'author' => $entry['author'] ?? null,
                    'min_age' => $minAge,
                    'min_age_source' => $minAge === null ? null : 'wkar',
                    'list_source' => 'wkar',
                    'list_key' => (string) $year,
                    'rank' => $entry['rank'] ?? null,
                    'metadata' => $gradeBand !== null ? ['grade_band' => $gradeBand] : null,
                ], $run);
            }

            $run->complete(['exhausted' => true]);
            $this->info("WKAR import finished: {$processed} entries.");

            return self::SUCCESS;
        });
    }

    /**
     * Award canon import (Newbery/Caldecott/Printz winners + honors authored
     * from ALA primary sources into database/data/book_library/awards/).
     * `list_key` = the file's slug, so one membership per (title, award);
     * year + winner|honor ride in membership metadata.
     */
    private function seedAward(IngestService $ingest): int
    {
        $limit = $this->limitOption();

        return $this->runSeed('seed_award', 'award', function (SyncRun $run) use ($ingest, $limit) {
            $slug = pathinfo((string) $this->option('file'), PATHINFO_FILENAME);
            $entries = $this->loadImportFile();
            $processed = 0;

            foreach ($entries as $entry) {
                if ($limit !== null && $processed >= $limit) {
                    $run->complete(['exhausted' => false]);
                    $this->warn('Stopped (--limit reached); re-run without --limit to finish (upserts are safe).');

                    return self::SUCCESS;
                }

                $title = $entry['title'] ?? null;
                $year = $entry['year'] ?? null;
                $type = $entry['type'] ?? null;
                if (! is_string($title) || trim($title) === '' || ! is_int($year) || ! in_array($type, ['winner', 'honor'], true)) {
                    $this->warn('Award entry skipped (title, integer year, and type winner|honor are required): '.json_encode($entry));

                    continue;
                }
                $processed++;

                $ingest->ingest([
                    'title' => $title,
                    'author' => $entry['author'] ?? null,
                    'list_source' => 'award',
                    'list_key' => $slug,
                    'metadata' => ['year' => $year, 'type' => $type],
                ], $run);
            }

            $run->complete(['exhausted' => true]);
            $this->info("Award import ({$slug}) finished: {$processed} entries.");

            return self::SUCCESS;
        });
    }

    /**
     * Resolve --file (as given, or relative to base_path) and decode its JSON
     * array. Throws into runSeed's catch — a missing/garbled import file must
     * fail the run before any ingest happens.
     *
     * @return array<int, mixed>
     */
    private function loadImportFile(): array
    {
        $file = $this->option('file');
        if (! is_string($file) || $file === '') {
            throw new \RuntimeException('--file is required for this source');
        }

        $path = file_exists($file) ? $file : base_path($file);
        if (! is_file($path)) {
            throw new \RuntimeException("import file not found: {$file}");
        }

        $entries = json_decode((string) file_get_contents($path), true);
        if (! is_array($entries) || ($entries !== [] && ! array_is_list($entries))) {
            throw new \RuntimeException("import file is not a JSON array: {$file}");
        }

        return $entries;
    }

    /** @return array{0: ?string, 1: ?string} [list, date] from the persisted `{list}|{date}` cursor */
    private function nytResumePoint(): array
    {
        if (! $this->option('resume')) {
            return [null, null];
        }

        $cursor = SyncRun::lastCursor('seed_nyt_history');
        if (! is_string($cursor) || ! str_contains($cursor, '|')) {
            return [null, null];
        }

        [$list, $date] = explode('|', $cursor, 2);

        // An empty resume date must mean "start the list from 'current'",
        // never "skip the list" (the walk's while-guard treats '' as
        // chain-end).
        return [$list, $date === '' ? null : $date];
    }

    /** Clean stop (budget / --limit / 429): cursor persisted, completed, exhausted=false. */
    private function stopNytRun(SyncRun $run, string $cursor, string $reason): int
    {
        $run->cursor($cursor);
        $run->complete(['exhausted' => false]);
        $this->warn("Stopped ({$reason}); cursor {$cursor} persisted — rerun with --resume.");

        return self::SUCCESS;
    }

    /**
     * Clean stop for an Open Library 429 inside a scraper arm's ingest
     * (compass §rate limits: typed rate-limit exceptions never fail a run).
     * The rate-limited item's membership was never written and `--resume`
     * skips every URL ≤ cursor, so the cursor must stay at the last fully
     * processed URL — the current page is re-fetched next run.
     *
     * When the 429 hits BEFORE the run's first checkpoint ($lastProcessed is
     * null), fall back to the cursor this run INHERITED via --resume — the
     * prior position must survive a stop that processed nothing, or the next
     * --resume restarts from scratch. Only when there is no inherited cursor
     * either (fresh run) does the '' sentinel apply: it is non-null, so
     * SyncRun::lastCursor cannot fall back to an OLDER run's cursor (which
     * would make --resume silently no-op), and `$url <= ''` is false for
     * every URL, so a resume starts from the beginning.
     */
    private function stopOnOpenLibraryRateLimit(SyncRun $run, string $url, ?string $lastProcessed, ?string $inheritedCursor): int
    {
        if ($this->option('delta')) {
            // No cursor for delta runs (see deltaFilter docblock) — the next
            // delta recomputes the remaining new URLs from the DB.
            $run->complete(['exhausted' => false]);
            $this->warn("Stopped (Open Library 429) before {$url}; rerun the delta later.");

            return self::SUCCESS;
        }

        $run->cursor($lastProcessed ?? $inheritedCursor ?? '');
        $run->complete(['exhausted' => false]);
        $this->warn("Stopped (Open Library 429) before {$url}; cursor persisted — rerun with --resume.");

        return self::SUCCESS;
    }

    private function invalidSource(): int
    {
        $this->error('Unknown --source. Available: csm, pluggedin, nyt-history, wkar, award.');

        return self::INVALID;
    }
}
