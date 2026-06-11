<?php

namespace App\Services\BookLibrary;

use App\Models\BookSyncLog;

/**
 * Lifecycle wrapper around a book_sync_log row (spec §book_sync_log).
 *
 * Counters and ambiguous entries accumulate in memory and hit the DB on the
 * next cursor()/complete()/fail(); cursor() saves the whole row immediately
 * because it is the resume-safety checkpoint.
 */
final class SyncRun
{
    private function __construct(private BookSyncLog $log) {}

    public static function start(string $syncType): self
    {
        return new self(BookSyncLog::create([
            'sync_type' => $syncType,
            'status' => 'running',
            'started_at' => now(),
        ]));
    }

    public function bumpApiCalls(int $n = 1): void
    {
        $this->log->api_calls_used += $n;
    }

    public function bumpTitles(int $n = 1): void
    {
        $this->log->titles_processed += $n;
    }

    /** Persists immediately — an interrupted run must resume from this point. */
    public function cursor(?string $cursor): void
    {
        $this->log->last_cursor = $cursor;
        $this->log->save();
    }

    /** Appends to metadata['ambiguous'] (persisted on next cursor/complete/fail). */
    public function logAmbiguous(array $entry): void
    {
        $metadata = $this->log->metadata ?? [];
        $metadata['ambiguous'][] = $entry;
        $this->log->metadata = $metadata;
    }

    public function complete(array $metadata = []): void
    {
        $this->log->metadata = array_merge($this->log->metadata ?? [], $metadata) ?: null;
        $this->log->status = 'completed';
        $this->log->completed_at = now();
        $this->log->save();
    }

    public function fail(string $message): void
    {
        $this->log->status = 'failed';
        $this->log->error_message = $message;
        $this->log->completed_at = now();
        $this->log->save();
    }

    /** Most recent persisted cursor for a sync type (for `--resume`). */
    public static function lastCursor(string $syncType): ?string
    {
        return BookSyncLog::where('sync_type', $syncType)
            ->whereNotNull('last_cursor')
            ->orderByDesc('id')
            ->value('last_cursor');
    }
}
