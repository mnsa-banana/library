<?php

namespace App\Console\Commands;

use App\Models\StreamingSyncLog;
use App\Models\StreamingTitleOffer;
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

            // Preload netflix/US offer provenance once (avoids a per-title existence query).
            $netflixOffers = DB::table('streaming_title_offers')
                ->where('service_id', 'netflix')->where('region', 'US')
                ->get(['title_id', 'source']);
            // title_ids are strings; array_flip + isset keeps lookups O(1) and integer-cast safe.
            $motnOwned = array_flip($netflixOffers->where('source', 'motn')->pluck('title_id')->all());
            $discoveryExisting = array_flip($netflixOffers->where('source', 'discovery')->pluck('title_id')->all());

            $created = 0;
            $restamped = 0;
            $skipped = 0;   // MOTN owns it
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
                    if (isset($motnOwned[$titleId])) {
                        $skipped++;   // MOTN is the authoritative owner of this title's Netflix offer.

                        continue;
                    }
                    StreamingTitleOffer::upsertDiscoveryNetflix($titleId, (int) $videoId);
                    if (isset($discoveryExisting[$titleId])) {
                        $restamped++;
                    } else {
                        $created++;
                        $discoveryExisting[$titleId] = true; // a 2nd browse hit on this title is a restamp, not a new create
                    }
                }
            }

            // Growth bound: drop discovery offers for titles verify-kids has confirmed are NOT
            // surfaced on Netflix Kids. Safe — never touches surfaced=true or not-yet-checked (null).
            $reaped = DB::table('streaming_title_offers')
                ->where('service_id', 'netflix')->where('region', 'US')->where('source', 'discovery')
                ->whereIn('title_id', fn ($q) => $q->select('id')->from('streaming_titles')
                    ->where('netflix_kids_surfaced', false))
                ->delete();

            $log->update([
                'status' => 'completed', 'completed_at' => now(), 'titles_processed' => $created,
                'metadata' => ['offers_created' => $created, 'offers_restamped' => $restamped,
                    'motn_owned_skipped' => $skipped, 'reaped_not_surfaced' => $reaped,
                    'unmatched_count' => count($unmatched), 'unmatched' => array_slice($unmatched, 0, 200)],
            ]);
            $this->info("created={$created} restamped={$restamped} motn_skipped={$skipped} reaped={$reaped} unmatched=".count($unmatched));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $log->update(['status' => 'failed', 'completed_at' => now(), 'error_message' => $e->getMessage()]);

            throw $e;
        }
    }
}
