<?php

namespace App\Console\Commands;

use App\Models\StreamingSyncLog;
use App\Models\StreamingTitle;
use App\Models\StreamingTitleOffer;
use App\Services\StreamingAvailability\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class StreamingSync extends Command
{
    private const CATALOGS = [
        'netflix.subscription',
        'prime.subscription', 'prime.rent', 'prime.buy', 'prime.free',
        'disney.subscription',
        'hbo.subscription',
        'hulu.subscription',
        'apple.subscription', 'apple.rent', 'apple.buy',
        'peacock.subscription', 'peacock.free',
        'paramount.subscription',
        'starz.subscription',
        'tubi.free',
        'plutotv.free',
        'crunchyroll.subscription',
        'discovery.subscription',
        'curiosity.subscription',
        'britbox.subscription',
        'mubi.subscription',
        'criterion.subscription',
        'zee5.subscription',
    ];

    private const CHANGE_TYPES = ['new', 'updated', 'removed', 'expiring'];

    /** Upcoming is only supported for Apple TV, Disney+, Max, Netflix, Prime Video. */
    private const UPCOMING_CATALOGS = [
        'netflix.subscription',
        'prime.subscription', 'prime.rent', 'prime.buy',
        'disney.subscription',
        'hbo.subscription',
        'apple.subscription', 'apple.rent', 'apple.buy',
    ];

    protected $signature = 'streaming:sync {--hours=72 : Lookback window in hours}';
    protected $description = 'Apply incremental catalog changes from /changes feed';

    public function handle(): int
    {
        // /changes responses bundle full show payloads keyed by id, which can run
        // tens of MB per page. PHP's allocator keeps the high-water mark across
        // iterations, so the 128M default is not enough for the full catalog sweep.
        ini_set('memory_limit', '512M');
        DB::connection()->disableQueryLog();

        $hours = (int) $this->option('hours');
        $to = Carbon::now()->getTimestamp();
        $from = Carbon::now()->subHours($hours)->getTimestamp();

        $log = StreamingSyncLog::create([
            'sync_type' => 'changes',
            'status' => 'running',
            'metadata' => ['from' => $from, 'to' => $to, 'hours' => $hours],
        ]);
        $client = new Client($log);
        $stats = ['new' => 0, 'updated' => 0, 'removed' => 0, 'expiring' => 0, 'upcoming' => 0, 'failed' => 0];

        try {
            foreach (self::CATALOGS as $catalog) {
                foreach (self::CHANGE_TYPES as $changeType) {
                    $this->info("Sync {$catalog} / {$changeType}...");
                    $cursor = null;
                    do {
                        $params = [
                            'country' => 'us',
                            'catalogs' => $catalog,
                            'change_type' => $changeType,
                            'item_type' => 'show',
                            'from' => $from,
                            'to' => $to,
                        ];
                        if ($cursor) $params['cursor'] = $cursor;

                        $resp = $client->get('/changes', $params);
                        $changes = $resp['changes'] ?? [];
                        $shows = $resp['shows'] ?? [];

                        foreach ($changes as $change) {
                            try {
                                $this->applyChange($change, $changeType, $shows, $catalog);
                                $stats[$changeType]++;
                            } catch (\Throwable $e) {
                                $stats['failed']++;
                                $this->warn("    Failed change for show {$change['showId']} ({$changeType}): {$e->getMessage()}");
                            }
                        }
                        $cursor = $resp['nextCursor'] ?? null;
                        $hasMore = !empty($resp['hasMore']) && $cursor;
                        $log->update(['last_cursor' => $cursor]);
                        unset($resp, $changes, $shows);
                    } while ($hasMore);
                }
                gc_collect_cycles();
            }

            // Upcoming sweep — paginated, no date window (the API returns all future-dated changes).
            foreach (self::UPCOMING_CATALOGS as $catalog) {
                $this->info("Sync {$catalog} / upcoming...");
                $cursor = null;
                do {
                    $params = [
                        'country' => 'us',
                        'catalogs' => $catalog,
                        'change_type' => 'upcoming',
                        'item_type' => 'show',
                    ];
                    if ($cursor) $params['cursor'] = $cursor;

                    $resp = $client->get('/changes', $params);
                    $changes = $resp['changes'] ?? [];
                    $shows = $resp['shows'] ?? [];

                    foreach ($changes as $change) {
                        try {
                            $this->applyUpcoming($change, $shows);
                            $stats['upcoming']++;
                        } catch (\Throwable $e) {
                            $stats['failed']++;
                            $this->warn("    Failed upcoming for show {$change['showId']}: {$e->getMessage()}");
                        }
                    }
                    $cursor = $resp['nextCursor'] ?? null;
                    $hasMore = !empty($resp['hasMore']) && $cursor;
                    $log->update(['last_cursor' => $cursor]);
                    unset($resp, $changes, $shows);
                } while ($hasMore);
                gc_collect_cycles();
            }

            $log->update([
                'status' => 'completed',
                'completed_at' => now(),
                'last_cursor' => null,
                'titles_processed' => $stats['new'] + $stats['updated'] + $stats['removed'] + $stats['expiring'] + $stats['upcoming'],
                'metadata' => array_merge($log->metadata ?? [], $stats),
            ]);
            $this->info(sprintf('Done. new=%d updated=%d removed=%d expiring=%d upcoming=%d failed=%d. Calls=%d',
                $stats['new'], $stats['updated'], $stats['removed'], $stats['expiring'], $stats['upcoming'], $stats['failed'],
                $log->fresh()->api_calls_used));
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $log->update(['status' => 'failed', 'completed_at' => now(), 'error_message' => $e->getMessage()]);
            $this->error("Sync failed: {$e->getMessage()}");
            return self::FAILURE;
        }
    }

    private function applyChange(array $change, string $type, array $shows, string $catalog): void
    {
        $showId = $change['showId'] ?? null;
        if (!$showId) return;

        if ($type === 'removed') {
            $serviceId = explode('.', $catalog, 2)[0];
            $catalogType = explode('.', $catalog, 2)[1];
            StreamingTitleOffer::where('title_id', $showId)
                ->where('service_id', $serviceId)
                ->where('region', 'US')
                ->where('type', $catalogType)
                ->delete();
            return;
        }

        if ($type === 'expiring') {
            $expiresAt = isset($change['timestamp']) ? Carbon::createFromTimestamp($change['timestamp']) : null;
            $serviceId = explode('.', $catalog, 2)[0];
            $catalogType = explode('.', $catalog, 2)[1];
            StreamingTitleOffer::where('title_id', $showId)
                ->where('service_id', $serviceId)
                ->where('region', 'US')
                ->where('type', $catalogType)
                ->update(['expires_on' => $expiresAt]);
            return;
        }

        // 'new' or 'updated' — upsert title + replace US offers from response.shows[showId].
        $show = $shows[$showId] ?? null;
        if (!$show) return;

        $this->upsertTitle($show);
        $this->replaceUsOffers($show);
    }

    private function upsertTitle(array $show): void
    {
        [$tmdbType, $tmdbId] = $this->parseTmdbId($show['tmdbId'] ?? null);

        StreamingTitle::withTrashed()->updateOrCreate(
            ['id' => $show['id']],
            StreamingBackfill::titleAttrs($show, $tmdbType, $tmdbId),
        );
    }

    /**
     * Persist an upcoming-drop record: pre-warm the title metadata and write an
     * offer row stamped with available_from so the SPA can render "Coming X" badges.
     */
    private function applyUpcoming(array $change, array $shows): void
    {
        $showId = $change['showId'] ?? null;
        $serviceId = $change['service']['id'] ?? null;
        $type = $change['streamingOptionType'] ?? null;
        $ts = $change['timestamp'] ?? null;
        if (!$showId || !$serviceId || !$type || !$ts) return;

        if ($show = $shows[$showId] ?? null) {
            $this->upsertTitle($show);
        }

        StreamingTitleOffer::updateOrCreate(
            [
                'title_id' => $showId,
                'service_id' => $serviceId,
                'region' => 'US',
                'type' => $type,
                'video_quality' => null,
            ],
            [
                'link' => $change['link'] ?? '',
                'available_from' => Carbon::createFromTimestamp($ts),
            ],
        );
    }

    private function parseTmdbId(?string $tmdbId): array
    {
        if (!$tmdbId || !str_contains($tmdbId, '/')) return [null, null];
        [$type, $id] = explode('/', $tmdbId, 2);
        return [$type, ctype_digit($id) ? (int) $id : null];
    }

    private function replaceUsOffers(array $show): void
    {
        $usOptions = $show['streamingOptions']['us'] ?? [];

        // Preserve upcoming rows (available_from IS NOT NULL) for services that
        // haven't dropped yet — they're not in this response's streamingOptions.
        StreamingTitleOffer::where('title_id', $show['id'])
            ->where('region', 'US')
            ->whereNull('available_from')
            ->delete();

        foreach ($usOptions as $opt) {
            $price = $opt['price'] ?? null;
            $expiresOn = isset($opt['expiresOn']) ? Carbon::createFromTimestamp($opt['expiresOn']) : null;
            StreamingTitleOffer::updateOrCreate(
                [
                    'title_id' => $show['id'],
                    'service_id' => $opt['service']['id'] ?? null,
                    'region' => 'US',
                    'type' => $opt['type'] ?? 'subscription',
                    'video_quality' => $opt['quality'] ?? null,
                ],
                [
                    'link' => $opt['link'] ?? '',
                    'deep_link' => $opt['deepLink'] ?? null,
                    'price_amount' => $price['amount'] ?? null,
                    'price_currency' => $price['currency'] ?? null,
                    'expires_on' => $expiresOn,
                ],
            );
        }

        StreamingTitle::where('id', $show['id'])
            ->update(['umbrella_services' => self::collapsedServices($usOptions) ?: null]);
    }

    /** Group offers by (service, type, quality); return [service_id => count] for groups >1. */
    public static function collapsedServices(array $usOptions): array
    {
        $buckets = [];
        foreach ($usOptions as $opt) {
            $svc = $opt['service']['id'] ?? null;
            if (!$svc) continue;
            $key = $svc . '|' . ($opt['type'] ?? '') . '|' . ($opt['quality'] ?? '');
            $buckets[$key] = ($buckets[$key] ?? 0) + 1;
        }
        $collapsed = [];
        foreach ($buckets as $key => $n) {
            if ($n <= 1) continue;
            $svc = explode('|', $key, 2)[0];
            $collapsed[$svc] = max($collapsed[$svc] ?? 0, $n);
        }
        return $collapsed;
    }
}
