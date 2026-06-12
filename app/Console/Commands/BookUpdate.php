<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * One-command incremental book-library refresh — the book-side analogue of
 * streaming:update. Composes the cheap recurring steps (index deltas + the
 * weekly NYT sync) so nobody has to remember the per-source flags; full
 * walks, history backfills, and the annual wkar/award file imports stay
 * explicit `book:seed` invocations.
 */
class BookUpdate extends Command
{
    /**
     * Held for the lifetime of a run so scheduled and manual invocations
     * cannot overlap. The TTL only matters when the process dies without
     * reaching the finally block; deltas are minutes, so 6h clears any
     * stale lock well before the next daily slot.
     */
    private const LOCK_NAME = 'book:update';

    private const LOCK_TTL_SECONDS = 60 * 60 * 6;

    protected $signature = 'book:update';

    protected $description = 'Incremental book-library refresh (CSM delta → Plugged In delta → NYT weekly), fail-fast.';

    public function handle(): int
    {
        $lock = Cache::lock(self::LOCK_NAME, self::LOCK_TTL_SECONDS);
        if (! $lock->get()) {
            Log::error('book:update skipped — another run still holds the lock');
            $this->error('Another book:update run is in progress; aborting.');

            return self::FAILURE;
        }

        try {
            return $this->runPipeline();
        } finally {
            $lock->release();
        }
    }

    private function runPipeline(): int
    {
        $startedAt = microtime(true);

        $steps = [
            ['book:seed', ['--source' => 'csm', '--delta' => true]],
            ['book:seed', ['--source' => 'pluggedin', '--delta' => true]],
            ['book:weekly', []],
        ];

        foreach ($steps as [$signature, $params]) {
            $label = $signature.($params === [] ? '' : ' '.implode(' ', array_keys($params)));
            $this->info("book:update → {$label}");

            $exit = $this->call($signature, $params);
            if ($exit !== self::SUCCESS) {
                // Sub-commands already wrote their own failed sync_log rows;
                // fail-fast mirrors streaming:update so a broken source is
                // loud instead of half-refreshing behind a green exit code.
                $this->error("book:update aborted — {$signature} exited {$exit}.");

                return self::FAILURE;
            }
        }

        $this->info(sprintf('book:update finished in %.1fs.', microtime(true) - $startedAt));

        return self::SUCCESS;
    }
}
