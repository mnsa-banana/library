<?php

namespace App\Console\Commands;

use App\Models\BookLibraryTitle;
use App\Models\BookListMembership;
use App\Models\BookSyncLog;
use Illuminate\Console\Command;

/**
 * Read-only book-library health report: per-(list_source, list_key) counts,
 * enrichment coverage, recent sync runs. `--ambiguous` is the manual review
 * surface for the resolver's skipped matches (resolution = manual data edit in
 * v1 — there is no admin UI).
 */
class BookStatus extends Command
{
    /** How many recent sync-log rows --ambiguous scans for entries. */
    private const AMBIGUOUS_LOG_WINDOW = 50;

    protected $signature = 'book:status
        {--ambiguous : List unresolved ambiguous matches from recent runs}';

    protected $description = 'Report book library counts, enrichment coverage, and sync history';

    public function handle(): int
    {
        if ($this->option('ambiguous')) {
            return $this->reportAmbiguous();
        }

        $total = BookLibraryTitle::count();
        $enriched = BookLibraryTitle::whereNotNull('enriched_at')->count();

        if ($total === 0) {
            $this->line('Titles: 0');
        } else {
            $pct = round($enriched / $total * 100, 1);
            $this->line("Titles: {$total} ({$enriched} enriched, {$pct}%)");
        }

        $this->newLine();
        $this->line('Memberships by list:');
        BookListMembership::query()
            ->selectRaw('list_source, list_key, count(*) as titles')
            ->groupBy('list_source', 'list_key')
            ->orderBy('list_source')
            ->orderBy('list_key')
            ->get()
            ->each(fn ($row) => $this->line("  {$row->list_source}/{$row->list_key}: {$row->titles}"));

        $this->newLine();
        $this->line('Recent sync runs:');
        BookSyncLog::orderByDesc('id')
            ->limit(5)
            ->get()
            ->each(function (BookSyncLog $log) {
                $started = $log->started_at?->format('Y-m-d H:i') ?? '-';
                $line = "  #{$log->id} {$log->sync_type} {$log->status} started={$started}"
                    ." calls={$log->api_calls_used} titles={$log->titles_processed}";
                if ($log->error_message !== null) {
                    $line .= " error={$log->error_message}";
                }
                $this->line($line);
            });

        return self::SUCCESS;
    }

    private function reportAmbiguous(): int
    {
        $entries = BookSyncLog::orderByDesc('id')
            ->limit(self::AMBIGUOUS_LOG_WINDOW)
            ->get()
            ->flatMap(fn (BookSyncLog $log) => $log->metadata['ambiguous'] ?? [])
            ->unique(fn (array $entry) => mb_strtolower((string) ($entry['incoming']['title'] ?? ''))
                .'|'.($entry['list_source'] ?? ''))
            ->values();

        if ($entries->isEmpty()) {
            $this->info('No ambiguous matches recorded.');

            return self::SUCCESS;
        }

        $this->line("{$entries->count()} unresolved ambiguous matches:");
        foreach ($entries as $entry) {
            $incoming = $entry['incoming'] ?? [];
            $title = $incoming['title'] ?? '?';
            $author = $incoming['author'] ?? null;
            $source = $entry['list_source'] ?? '?';
            $listKey = $entry['list_key'] ?? '?';
            $step = $entry['step'] ?? '?';

            $byline = $author !== null ? " by {$author}" : '';
            $this->line("- \"{$title}\"{$byline} [{$source}/{$listKey}] (step: {$step})");
            foreach ($entry['candidates'] ?? [] as $candidate) {
                $candidateAuthor = $candidate['author'] ?? '?';
                $this->line("    candidate #{$candidate['id']}: {$candidate['title']} — {$candidateAuthor}");
            }
        }

        return self::SUCCESS;
    }
}
