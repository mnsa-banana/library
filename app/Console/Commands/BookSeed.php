<?php

namespace App\Console\Commands;

use App\Services\BookLibrary\IngestService;
use App\Services\BookLibrary\NytClient;
use App\Services\BookLibrary\NytRateLimitedException;
use App\Services\BookLibrary\SyncRun;
use Illuminate\Console\Command;

/**
 * Book-library seed entry point. Each `--source` is an independent arm with
 * its own sync_type, cursor format, and resume semantics; further arms
 * (csm, pluggedin, wkar, award) land with later tasks.
 */
class BookSeed extends Command
{
    /** NYT allows ~500 calls/day — leave headroom for book:weekly. */
    private const NYT_MAX_CALLS_PER_RUN = 450;

    protected $signature = 'book:seed
        {--source= : Seed source (nyt-history)}
        {--limit= : Maximum list pages to fetch this run}
        {--resume : Continue from the last persisted cursor for this source}';

    protected $description = 'Seed the book library from a list source';

    public function handle(NytClient $nyt, IngestService $ingest): int
    {
        return match ($this->option('source')) {
            'nyt-history' => $this->seedNytHistory($nyt, $ingest),
            default => $this->invalidSource(),
        };
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

        $limit = $this->option('limit') !== null ? max(0, (int) $this->option('limit')) : null;
        [$resumeList, $resumeDate] = $this->nytResumePoint();

        $run = SyncRun::start('seed_nyt_history');

        try {
            try {
                $names = $nyt->listNames();
            } catch (NytRateLimitedException) {
                $run->bumpApiCalls();
                $run->complete(['exhausted' => false]);
                $this->warn('NYT rate limited on lists/names — rerun later.');

                return self::SUCCESS;
            }
            $run->bumpApiCalls();
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
        } catch (\Throwable $e) {
            $run->fail($e->getMessage());
            $this->error("book:seed nyt-history failed: {$e->getMessage()}");

            return self::FAILURE;
        }
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
        $this->error('Unknown --source. Available: nyt-history.');

        return self::INVALID;
    }
}
