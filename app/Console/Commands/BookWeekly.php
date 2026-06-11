<?php

namespace App\Console\Commands;

use App\Services\BookLibrary\IngestService;
use App\Services\BookLibrary\NytClient;
use App\Services\BookLibrary\NytRateLimitedException;
use App\Services\BookLibrary\OpenLibraryRateLimitedException;
use App\Services\BookLibrary\SyncRun;
use Illuminate\Console\Command;

class BookWeekly extends Command
{
    protected $signature = 'book:weekly';

    protected $description = "Sync the current NYT children's bestseller lists into the book library";

    public function handle(NytClient $nyt, IngestService $ingest): int
    {
        // Scheduled command: a missing key must no-op gracefully (exit 0),
        // but leave a failed sync_log row so the gap is visible.
        if (! config('services.nyt.books_key')) {
            SyncRun::start('weekly')->fail('NYT_BOOKS_API_KEY is not configured');
            $this->warn('book:weekly skipped — NYT_BOOKS_API_KEY is not configured.');

            return self::SUCCESS;
        }

        $run = SyncRun::start('weekly');

        try {
            foreach (NytClient::CURRENT_LISTS as $list) {
                try {
                    $results = $nyt->listForDate($list, 'current');
                } catch (NytRateLimitedException) {
                    $run->bumpApiCalls();
                    $run->cursor("{$list}|current");
                    $run->complete(['exhausted' => false]);
                    $this->warn("NYT rate limited on {$list}; stopping — remaining lists sync next run.");

                    return self::SUCCESS;
                }
                $run->bumpApiCalls();

                $asOfDate = $results['published_date'] ?? null;
                try {
                    foreach ($results['books'] ?? [] as $book) {
                        $item = NytClient::ingestItem($book, $list, $asOfDate);
                        if ($item['title'] === '') {
                            continue;
                        }
                        $ingest->ingest($item, $run);
                    }
                } catch (OpenLibraryRateLimitedException) {
                    // Same contract as the NYT 429 above: a typed rate limit
                    // never fails the run. The partially synced list is
                    // re-synced in full next run (memberships upsert).
                    $run->cursor("{$list}|current");
                    $run->complete(['exhausted' => false]);
                    $this->warn("Open Library rate limited during {$list}; stopping — remaining lists sync next run.");

                    return self::SUCCESS;
                }
                $this->info("Synced {$list} ({$asOfDate}).");
            }

            $run->complete(['exhausted' => true]);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $run->fail($e->getMessage());
            $this->error("book:weekly failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
