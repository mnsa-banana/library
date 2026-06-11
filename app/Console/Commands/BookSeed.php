<?php

namespace App\Console\Commands;

use App\Services\BookLibrary\CsmIndexScraper;
use App\Services\BookLibrary\IngestService;
use App\Services\BookLibrary\NytClient;
use App\Services\BookLibrary\NytRateLimitedException;
use App\Services\BookLibrary\PluggedInIndexScraper;
use App\Services\BookLibrary\SyncRun;
use Illuminate\Console\Command;

/**
 * Book-library seed entry point. Each `--source` is an independent arm with
 * its own sync_type, cursor format, and resume semantics; further arms
 * (wkar, award) land with later tasks.
 */
class BookSeed extends Command
{
    /** NYT allows ~500 calls/day — leave headroom for book:weekly. */
    private const NYT_MAX_CALLS_PER_RUN = 450;

    protected $signature = 'book:seed
        {--source= : Seed source (csm|pluggedin|nyt-history)}
        {--limit= : Maximum pages to fetch this run}
        {--resume : Continue from the last persisted cursor for this source}';

    protected $description = 'Seed the book library from a list source';

    public function handle(NytClient $nyt, CsmIndexScraper $csm, PluggedInIndexScraper $pluggedin, IngestService $ingest): int
    {
        return match ($this->option('source')) {
            'csm' => $this->seedCsm($csm, $ingest),
            'pluggedin' => $this->seedPluggedIn($pluggedin, $ingest),
            'nyt-history' => $this->seedNytHistory($nyt, $ingest),
            default => $this->invalidSource(),
        };
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

            $processed = 0;
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
                }

                // Resume-safety checkpoint: persists immediately. Skipped
                // pages advance it too — --resume must not re-grind a
                // permanently broken page.
                $run->cursor($url);
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

            $processed = 0;
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
                    // Title/author/list fields only — Plugged In exposes no
                    // ISBN and no machine-readable age (spec: no min_age).
                    $ingest->ingest([
                        'title' => $meta['title'],
                        'author' => $meta['author'],
                        'list_source' => 'pluggedin_index',
                        'list_key' => 'index',
                        'review_url' => $url,
                    ], $run);
                }

                // Resume-safety checkpoint: persists immediately. Skipped
                // pages advance it too — --resume must not re-grind a
                // permanently broken page.
                $run->cursor($url);
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
            try {
                $names = $nyt->listNames();
            } catch (NytRateLimitedException) {
                $run->bumpApiCalls();
                $run->complete(['exhausted' => false]);
                $this->warn('NYT rate limited on lists/names — rerun later.');

                return self::SUCCESS;
            }
            $run->bumpApiCalls();

            // NYT can 200 with no results; completing here would be
            // indistinguishable from a finished backfill (exhausted=true).
            if ($names === []) {
                $run->fail('NYT lists/names returned no lists');
                $this->error('NYT lists/names returned no lists.');

                return self::FAILURE;
            }

            $calls = 1;
            $pages = 0;

            $lists = NytClient::HISTORY_LISTS;
            $start = $resumeList !== null ? array_search($resumeList, $lists, true) : 0;
            if ($start === false) {
                [$start, $resumeDate] = [0, null];
            }

            foreach (array_slice($lists, (int) $start) as $offset => $list) {
                $bounds = $names[$list] ?? null;
                if ($bounds === null) {
                    $this->warn("NYT lists/names has no entry for {$list}; skipping.");

                    continue;
                }

                $oldest = $bounds['oldest_published_date'];
                $date = $offset === 0 && $resumeDate !== null ? $resumeDate : $bounds['newest_published_date'];

                while (is_string($date) && $date !== '' && $date >= $oldest) {
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
                    }
                    $run->bumpApiCalls();
                    $calls++;
                    $pages++;

                    $asOfDate = $results['published_date'] ?? $date;
                    foreach ($results['books'] ?? [] as $book) {
                        $item = NytClient::ingestItem($book, $list, $asOfDate);
                        if ($item['title'] === '') {
                            continue;
                        }
                        $ingest->ingest($item, $run);
                    }

                    // Resume-safety checkpoint after every page; re-fetching
                    // one already-processed page on --resume is harmless
                    // (memberships upsert).
                    $run->cursor("{$list}|{$date}");
                    $this->info("Seeded {$list} {$date}.");

                    $previous = $results['previous_published_date'] ?? null;
                    $date = is_string($previous) && $previous !== '' ? $previous : null;
                }
            }

            $run->complete(['exhausted' => true]);
            $this->info("NYT history backfill exhausted after {$calls} calls.");

            return self::SUCCESS;
        });
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

        return explode('|', $cursor, 2);
    }

    /** Clean stop (budget / --limit / 429): cursor persisted, completed, exhausted=false. */
    private function stopNytRun(SyncRun $run, string $cursor, string $reason): int
    {
        $run->cursor($cursor);
        $run->complete(['exhausted' => false]);
        $this->warn("Stopped ({$reason}); cursor {$cursor} persisted — rerun with --resume.");

        return self::SUCCESS;
    }

    private function invalidSource(): int
    {
        $this->error('Unknown --source. Available: csm, pluggedin, nyt-history.');

        return self::INVALID;
    }
}
