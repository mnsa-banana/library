<?php

namespace App\Console\Commands;

use App\Models\StreamingSyncLog;
use App\Models\StreamingTitle;
use App\Models\StreamingTitleOffer;
use App\Services\NetflixKids\NetflixKidsClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Monthly TMDB backstop: for in-DB titles TMDB says are on Netflix-US but which
 * we have no Netflix offer for, resolve the Netflix videoId via the Kids search
 * and write a source='discovery' offer (verify-kids classifies it next run). No
 * offer when the title isn't in the Kids catalog (e.g. mature titles) — see spec E8.
 */
class StreamingTmdbBackstop extends Command
{
    protected $signature = 'streaming:tmdb-backstop {--limit=0 : 0 = all}';

    protected $description = 'Fill Netflix-Kids gaps MOTN missed, cross-referenced against TMDB';

    private const NETFLIX_PROVIDER_IDS = [8, 1796]; // Netflix, Netflix Standard with Ads (US)

    public function handle(NetflixKidsClient $netflix): int
    {
        $key = config('services.tmdb.api_key');
        if (! $key) {
            $this->error('TMDB_API_KEY not configured.');

            return self::FAILURE;
        }

        $log = StreamingSyncLog::create([
            'sync_type' => 'tmdb_backstop', 'started_at' => now(), 'status' => 'running',
        ]);

        try {
            $session = $netflix->probeSession();
            if (($session['country'] ?? null) !== 'US' || ! ($session['is_kids'] ?? false)
                || empty($session['app_version'])) {
                $log->update(['status' => 'failed', 'completed_at' => now(),
                    'error_message' => 'invalid US Kids session']);
                $this->error('Netflix Kids session invalid.');

                return self::FAILURE;
            }
            $app = $session['app_version'];

            $created = 0;
            $netflixSays = 0;
            $checked = 0;

            $query = StreamingTitle::whereNotNull('tmdb_id')->where('tmdb_id', '>', 0)
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('streaming_title_offers as o')
                    ->whereColumn('o.title_id', 'streaming_titles.id')
                    ->where('o.service_id', 'netflix')->where('o.region', 'US'));
            if ($limit = (int) $this->option('limit')) {
                $query->limit($limit);
            }

            $query->orderBy('id')->chunkById(500, function ($titles) use ($key, $netflix, $app, &$created, &$netflixSays, &$checked) {
                foreach ($titles as $t) {
                    $checked++;
                    if (! $this->tmdbSaysNetflix($key, $t)) {
                        continue;
                    }
                    $netflixSays++;
                    $videoId = $netflix->resolveKidsVideoId($t->title, (string) $t->tmdb_type === 'tv' ? 'series' : 'movie', $app);
                    if ($videoId === null) {
                        continue; // on Netflix-US per TMDB but not in Kids (or unresolvable) — no offer (E8).
                    }
                    StreamingTitleOffer::upsertDiscoveryNetflix($t->id, $videoId);
                    $created++;
                }
            });

            $log->update([
                'status' => 'completed', 'completed_at' => now(), 'titles_processed' => $created,
                'metadata' => ['checked' => $checked, 'tmdb_netflix' => $netflixSays, 'offers_created' => $created],
            ]);
            $this->info("checked={$checked} tmdb_netflix={$netflixSays} discovery_offers_created={$created}");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $log->update(['status' => 'failed', 'completed_at' => now(), 'error_message' => $e->getMessage()]);

            throw $e;
        }
    }

    private function tmdbSaysNetflix(string $key, StreamingTitle $t): bool
    {
        $path = ((string) $t->tmdb_type === 'tv') ? 'tv' : 'movie';
        try {
            $resp = Http::timeout(15)->retry(2, 300, throw: false)
                ->get("https://api.themoviedb.org/3/{$path}/{$t->tmdb_id}/watch/providers", ['api_key' => $key]);
        } catch (\Throwable) {
            return false;
        }
        if (! $resp->successful()) {
            return false;
        }
        foreach (['flatrate', 'ads', 'flatrate_and_buy'] as $bucket) {
            foreach ($resp->json("results.US.{$bucket}", []) as $p) {
                if (in_array($p['provider_id'] ?? 0, self::NETFLIX_PROVIDER_IDS, true)) {
                    return true;
                }
            }
        }

        return false;
    }
}
