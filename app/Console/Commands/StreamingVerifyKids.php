<?php

namespace App\Console\Commands;

use App\Services\NetflixKids\NetflixKidsClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class StreamingVerifyKids extends Command
{
    protected $signature = 'streaming:verify-kids {--stale-days= : re-verify only titles older than N days} {--force : re-verify everything}';

    protected $description = 'Verify which US Netflix titles are surfaced in the Kids profile and update streaming_titles.';

    private const ANCHORS_IN = [81186615 => 'the thundermans', 81315367 => 'bigfoot family'];
    private const ANCHOR_OUT = [70153373 => 'seinfeld'];

    public function handle(NetflixKidsClient $client): int
    {
        // ── Stage 0: validate the session BEFORE any writes ──────────────
        $session = $client->probeSession();
        if (($session['country'] ?? null) !== 'US' || ! ($session['is_kids'] ?? false)
            || empty($session['auth_url']) || empty($session['shakti_url']) || empty($session['app_version'])) {
            return $this->abort('not a US Kids session (country=' . ($session['country'] ?? 'null') . ', is_kids=' . var_export($session['is_kids'] ?? null, true) . ')');
        }
        $app = $session['app_version'];
        foreach (self::ANCHORS_IN as $id => $term) {
            if (! $client->searchHasId($term, $id, $app)) {
                return $this->abort("anchor '$term' ($id) not surfaced — cookie/persisted-query likely stale");
            }
        }
        foreach (self::ANCHOR_OUT as $id => $term) {
            if ($client->searchHasId($term, $id, $app)) {
                return $this->abort("control '$term' ($id) unexpectedly surfaced — search not catalog-restricted");
            }
        }

        // ── Cleanup: titles with no qualifying offer revert to null ──────
        $this->resetOrphans();

        // ── Work set: currently-playable US-Netflix titles, checked_at gated ──
        // --force: re-verify everything (floor null). Otherwise re-verify titles
        // older than --stale-days (or the configured default); titles checked more
        // recently are skipped, so an aborted run resumes instead of restarting.
        if ($this->option('force')) {
            $floor = null;
        } else {
            $days = $this->option('stale-days') !== null
                ? (int) $this->option('stale-days')
                : (int) config('services.netflix_kids.default_stale_days', 14);
            $floor = now()->subDays($days);
        }

        $rows = DB::table('streaming_titles as st')
            ->join('streaming_title_offers as o', 'o.title_id', '=', 'st.id')
            ->where('o.service_id', 'netflix')->where('o.region', 'US')
            ->where('o.link', 'like', '%/title/%')  // PHP preg_match below extracts the id; LIKE keeps this portable to sqlite tests
            ->where(fn ($q) => $q->whereNull('o.available_from')->orWhere('o.available_from', '<=', now()))
            ->when($floor !== null, fn ($q) => $q->where(fn ($w) => $w
                ->whereNull('st.netflix_kids_checked_at')->orWhere('st.netflix_kids_checked_at', '<', $floor)))
            ->select('st.id', 'st.title', 'o.link')
            ->distinct()->get();

        // map id => nfid
        $byNf = [];
        foreach ($rows as $r) {
            if (preg_match('#/title/(\d+)#', $r->link, $m)) {
                $byNf[$r->id] = ['title' => $r->title, 'nfid' => (int) $m[1]];
            }
        }
        $this->info('Candidates: ' . count($byNf));

        // ── Stage 1: maturity prune ──────────────────────────────────────
        $nfids = array_map(fn ($x) => $x['nfid'], $byNf);
        $levels = $client->maturityLevels(array_values($nfids), $session['shakti_url'], $session['auth_url']);
        $ceiling = (int) config('services.netflix_kids.maturity_ceiling');

        $delay = (float) config('services.netflix_kids.search_delay');
        $surfacedCount = 0;
        foreach ($byNf as $titleId => $info) {
            $level = $levels[$info['nfid']] ?? null;
            if ($level === null || $level > $ceiling) {
                $surfaced = false;                       // above ceiling / unknown -> not in kids
            } else {
                $surfaced = $client->searchHasId($info['title'], $info['nfid'], $app); // Stage 2
                if ($delay > 0) { usleep((int) ($delay * 1_000_000)); }
            }
            DB::table('streaming_titles')->where('id', $titleId)->update([
                'netflix_kids_surfaced' => $surfaced,
                'netflix_kids_checked_at' => now(),
            ]);
            if ($surfaced) { $surfacedCount++; }
        }

        $this->info("Done. surfaced=$surfacedCount of " . count($byNf) . '.');
        return self::SUCCESS;
    }

    private function abort(string $why): int
    {
        $this->error("Netflix Kids verification aborted: $why. No data written. "
            . 'Refresh NETFLIX_KIDS_COOKIE (US VPN, Kids profile) and/or NETFLIX_KIDS_PERSISTED_QUERY_ID.');
        return self::FAILURE;
    }

    /** Titles whose only/any qualifying playable US-Netflix offer is gone revert to null. */
    private function resetOrphans(): void
    {
        $hasOffer = DB::table('streaming_title_offers as o')
            ->whereColumn('o.title_id', 'streaming_titles.id')
            ->where('o.service_id', 'netflix')->where('o.region', 'US')
            ->where('o.link', 'like', '%/title/%')  // PHP preg_match below extracts the id; LIKE keeps this portable to sqlite tests
            ->where(fn ($q) => $q->whereNull('o.available_from')->orWhere('o.available_from', '<=', now()));

        DB::table('streaming_titles')
            ->whereNotNull('netflix_kids_checked_at')
            ->whereNotExists($hasOffer)
            ->update(['netflix_kids_surfaced' => null, 'netflix_kids_checked_at' => null]);
    }
}
