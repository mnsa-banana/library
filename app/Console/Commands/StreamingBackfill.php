<?php

namespace App\Console\Commands;

use App\Models\StreamingSyncLog;
use App\Models\StreamingTitle;
use App\Models\StreamingTitleOffer;
use App\Services\StreamingAvailability\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class StreamingBackfill extends Command
{
    /** Catalogs to fully ingest. Format: '<service_id>.<type>'. */
    private const CATALOGS = [
        'netflix.subscription',
        'prime.subscription',
        'prime.rent',
        'prime.buy',
        'prime.free',
        'disney.subscription',
        'hbo.subscription',
        'hulu.subscription',
        'apple.subscription',
        'apple.rent',
        'apple.buy',
        'peacock.subscription',
        'peacock.free',
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

    protected $signature = 'streaming:backfill {--catalog=* : Restrict to a subset of catalogs (slug.type)}';
    protected $description = 'Initial one-time backfill of US streaming catalogs into streaming_titles + streaming_title_offers';

    public function handle(): int
    {
        // /shows/search/filters responses include full show payloads (cast, directors,
        // streamingOptions, imageSet variants). Across hundreds of paginated requests
        // PHP's allocator high-water mark grows past the 128M default.
        ini_set('memory_limit', '512M');
        DB::connection()->disableQueryLog();

        $catalogs = $this->option('catalog') ?: self::CATALOGS;

        $log = StreamingSyncLog::create([
            'sync_type' => 'initial_backfill',
            'status' => 'running',
            'metadata' => ['catalogs' => $catalogs],
        ]);
        $client = new Client($log);
        $titlesProcessed = 0;

        try {
            foreach ($catalogs as $catalog) {
                $this->info("Backfilling {$catalog}...");
                $cursor = null;
                $page = 0;
                do {
                    $params = [
                        'country' => 'us',
                        'catalogs' => $catalog,
                        'series_granularity' => 'show',
                    ];
                    if ($cursor) $params['cursor'] = $cursor;

                    $resp = $client->get('/shows/search/filters', $params);
                    $shows = $resp['shows'] ?? [];

                    foreach ($shows as $show) {
                        $this->upsertTitle($show);
                        $this->replaceUsOffers($show);
                        $titlesProcessed++;
                    }

                    $cursor = $resp['nextCursor'] ?? null;
                    $log->update(['last_cursor' => $cursor, 'titles_processed' => $titlesProcessed]);
                    $page++;
                    $this->line("  {$catalog} page {$page}: " . count($shows) . " shows (cumulative {$titlesProcessed}, calls={$log->fresh()->api_calls_used})");
                } while (!empty($resp['hasMore']) && $cursor);
            }

            $log->update([
                'status' => 'completed',
                'completed_at' => now(),
                'last_cursor' => null,
                'titles_processed' => $titlesProcessed,
            ]);
            $this->info("Done. {$titlesProcessed} titles processed. Calls used: {$log->fresh()->api_calls_used}");
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $log->update(['status' => 'failed', 'completed_at' => now(), 'error_message' => $e->getMessage()]);
            $this->error("Backfill failed: {$e->getMessage()}");
            return self::FAILURE;
        }
    }

    private function upsertTitle(array $show): void
    {
        [$tmdbType, $tmdbId] = $this->parseTmdbId($show['tmdbId'] ?? null);

        StreamingTitle::withTrashed()->updateOrCreate(
            ['id' => $show['id']],
            self::titleAttrs($show, $tmdbType, $tmdbId),
        );
    }

    /** Shared attribute map for streaming_titles upserts. Reused by StreamingSync. */
    public static function titleAttrs(array $show, ?string $tmdbType, ?int $tmdbId): array
    {
        return [
            'imdb_id' => $show['imdbId'] ?? null,
            'tmdb_id' => $tmdbId,
            'tmdb_type' => $tmdbType,
            'show_type' => $show['showType'] ?? 'movie',
            'title' => mb_substr($show['title'] ?? '', 0, 500),
            'release_year' => $show['releaseYear'] ?? null,
            'first_air_year' => $show['firstAirYear'] ?? null,
            'last_air_year' => $show['lastAirYear'] ?? null,
            'runtime' => $show['runtime'] ?? null,
            'rating' => $show['rating'] ?? null,
            'season_count' => $show['seasonCount'] ?? null,
            'episode_count' => $show['episodeCount'] ?? null,
            'genres' => collect($show['genres'] ?? [])->pluck('name')->all() ?: null,
            'cast_members' => $show['cast'] ?? null,
            'directors' => $show['directors'] ?? null,
            'creators' => $show['creators'] ?? null,
            'poster_url' => $show['imageSet']['verticalPoster']['w720']
                ?? $show['imageSet']['verticalPoster']['w480']
                ?? null,
            'backdrop_url' => $show['imageSet']['horizontalBackdrop']['w1080']
                ?? $show['imageSet']['horizontalBackdrop']['w720']
                ?? null,
            'overview' => $show['overview'] ?? null,
        ];
    }

    /** Parse "movie/123" → ['movie', 123]. Returns [null, null] if input is null/malformed. */
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
            ->update(['umbrella_services' => StreamingSync::collapsedServices($usOptions) ?: null]);
    }
}
