<?php

namespace App\Console\Commands;

use App\Models\StreamingSyncLog;
use App\Models\StreamingTitle;
use App\Services\StreamingAvailability\TmdbEnricher;
use Illuminate\Console\Command;

class StreamingEnrich extends Command
{
    protected $signature = 'streaming:enrich
        {--type= : Filter by tmdb_type (movie or tv)}
        {--force : Re-enrich all, not just missing us_certification}';

    protected $description = 'Fill us_certification (and fallback trailer_url) on streaming_titles via TMDB';

    public function handle(TmdbEnricher $enricher): int
    {
        $log = StreamingSyncLog::create(['sync_type' => 'enrich', 'status' => 'running']);

        $query = StreamingTitle::whereNotNull('tmdb_id')->where('tmdb_id', '>', 0);
        if (!$this->option('force')) $query->whereNull('us_certification');
        if ($type = $this->option('type')) $query->where('tmdb_type', $type);

        $total = $query->count();
        $this->info("Enriching {$total} titles from TMDB...");

        $enriched = 0;
        $failed = 0;

        // Stream rows in batches of 500 to avoid loading the full result set
        // into memory — large catalogs (60k+ titles) blow the default 128MB.
        $query->chunkById(500, function ($titles) use ($enricher, &$enriched, &$failed, $total) {
            foreach ($titles as $title) {
                try {
                    if ($enricher->enrich($title)) $enriched++;
                    else $failed++;
                } catch (\Throwable $e) {
                    $failed++;
                    $this->warn("  Skipping {$title->title} (tmdb_id={$title->tmdb_id}): {$e->getMessage()}");
                }
                if (($enriched + $failed) % 50 === 0) {
                    $this->info("  {$enriched}/{$total} enriched, {$failed} skipped/failed");
                }
            }
        });

        $log->update([
            'status' => 'completed',
            'completed_at' => now(),
            'titles_processed' => $enriched,
            'metadata' => ['failed' => $failed],
        ]);
        $this->info("Done. {$enriched}/{$total} enriched, {$failed} skipped/soft-deleted.");
        return self::SUCCESS;
    }
}
