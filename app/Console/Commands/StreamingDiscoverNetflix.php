<?php

namespace App\Console\Commands;

use App\Models\StreamingSyncLog;
use App\Services\NetflixKids\NetflixKidsClient;
use App\Services\StreamingAvailability\TitleResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Weekly: enumerate the live Netflix Kids catalog (genre-union browse), match
 * each title to an existing streaming_title, and write a source='discovery'
 * Netflix offer for matched titles that lack one (verify-kids classifies them
 * next run). Unmatched (brand-new-to-DB) titles are logged, not auto-created.
 * Strictly additive: the browse is known-incomplete, so it never un-marks anything.
 */
class StreamingDiscoverNetflix extends Command
{
    protected $signature = 'streaming:discover-netflix';

    protected $description = 'Discover Netflix-Kids titles by browsing the live catalog';

    public function handle(NetflixKidsClient $netflix, TitleResolver $resolver): int
    {
        $log = StreamingSyncLog::create([
            'sync_type' => 'discover_netflix', 'started_at' => now(), 'status' => 'running',
        ]);

        try {
            $session = $netflix->probeSession();
            if (($session['country'] ?? null) !== 'US' || ! ($session['is_kids'] ?? false)
                || empty($session['member_api_url']) || empty($session['auth_url'])) {
                $log->update(['status' => 'failed', 'completed_at' => now(),
                    'error_message' => 'invalid US Kids session']);
                $this->error('Netflix Kids session invalid.');

                return self::FAILURE;
            }
            $member = $session['member_api_url'];
            $auth = $session['auth_url'];

            $created = 0;
            $skipped = 0;
            $unmatched = [];

            foreach (config('services.netflix_kids.browse_genres', []) as $g) {
                $ids = $netflix->browseGenreVideoIds((int) $g['id'], $member, $auth);
                $titles = $netflix->resolveVideoTitles($ids, $member, $auth);
                foreach ($titles as $videoId => $title) {
                    $titleId = $resolver->resolve($title, $g['type']);
                    if ($titleId === null) {
                        $unmatched[] = ['videoId' => $videoId, 'title' => $title, 'type' => $g['type']];

                        continue;
                    }
                    $hasNetflix = DB::table('streaming_title_offers')->where('title_id', $titleId)
                        ->where('service_id', 'netflix')->where('region', 'US')->exists();
                    if ($hasNetflix) {
                        $skipped++;

                        continue;
                    }
                    DB::table('streaming_title_offers')->updateOrInsert(
                        ['title_id' => $titleId, 'service_id' => 'netflix', 'region' => 'US',
                            'type' => 'subscription', 'video_quality' => null],
                        ['link' => "https://www.netflix.com/title/{$videoId}/", 'source' => 'discovery', 'updated_at' => now()],
                    );
                    $created++;
                }
            }

            $log->update([
                'status' => 'completed', 'completed_at' => now(), 'titles_processed' => $created,
                'metadata' => ['offers_created' => $created, 'already_had' => $skipped,
                    'unmatched_count' => count($unmatched), 'unmatched' => array_slice($unmatched, 0, 200)],
            ]);
            $this->info("created={$created} already_had={$skipped} unmatched=".count($unmatched));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $log->update(['status' => 'failed', 'completed_at' => now(), 'error_message' => $e->getMessage()]);

            throw $e;
        }
    }
}
