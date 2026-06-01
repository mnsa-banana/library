<?php

namespace App\Console\Commands;

use App\Services\NetflixKids\NetflixKidsClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class StreamingVerifyKids extends Command
{
    protected $signature = 'streaming:verify-kids {--stale-days= : re-verify only titles older than N days} {--force : re-verify everything}';

    protected $description = 'Verify which US Netflix titles are surfaced in the Kids profile and update streaming_titles.';

    private const ANCHORS_IN = [81186615 => 'the thundermans', 81315367 => 'bigfoot family'];
    private const ANCHOR_OUT = [70153373 => 'seinfeld'];

    /** Flush bulk writes every N processed titles — bounds crash-loss + checked_at skew while avoiding 1 UPDATE/title. */
    private const WRITE_BATCH = 100;

    public function handle(NetflixKidsClient $client): int
    {
        $ceiling = (int) config('services.netflix_kids.maturity_ceiling');

        $session = $this->gateSession($client, $ceiling);
        if ($session === null) {
            return self::FAILURE;
        }

        $this->resetOrphans();

        [$byNf, $badLinks] = $this->loadCandidates($this->staleFloor());
        if ($badLinks > 0) {
            $this->warn("  {$badLinks} Netflix offer link(s) had no numeric /title/<id> — left unverified.");
        }
        $this->info('Candidates: ' . count($byNf) . ' currently-playable US Netflix titles to verify.');

        $levels = $this->fetchMaturity($client, $byNf, $session);
        if ($levels === null) {
            return self::FAILURE; // maturity fetch failed mid-run; aborted with no writes
        }

        return $this->runSearchStage($client, $session['app_version'], $byNf, $levels, $ceiling);
    }

    /** Stage 0: validate session + BOTH endpoints before any write. Returns the session, or null after aborting. */
    private function gateSession(NetflixKidsClient $client, int $ceiling): ?array
    {
        $this->info('Validating US Kids session…');
        try {
            $session = $client->probeSession();
            if (($session['country'] ?? null) !== 'US' || ! ($session['is_kids'] ?? false)
                || empty($session['auth_url']) || empty($session['shakti_url']) || empty($session['app_version'])) {
                $this->abort('not a US Kids session (country=' . ($session['country'] ?? 'null') . ', is_kids=' . var_export($session['is_kids'] ?? null, true) . ')');

                return null;
            }
            $app = $session['app_version'];

            // search path: in-anchors must surface, out-control must not
            foreach (self::ANCHORS_IN as $id => $term) {
                if (! $client->searchHasId($term, $id, $app)) {
                    $this->abort("anchor '$term' ($id) not surfaced — cookie/persisted-query likely stale");

                    return null;
                }
            }
            foreach (self::ANCHOR_OUT as $id => $term) {
                if ($client->searchHasId($term, $id, $app)) {
                    $this->abort("control '$term' ($id) unexpectedly surfaced — search not catalog-restricted");

                    return null;
                }
            }

            // maturity path: distinguish "endpoint dead" from "ceiling misconfigured"
            $anchorLevels = array_filter(
                $client->maturityLevels(array_keys(self::ANCHORS_IN), $session['shakti_url'], $session['auth_url']),
                fn ($l) => $l !== null
            );
            if (count($anchorLevels) === 0) {
                $this->abort('maturity endpoint returned no data for anchors — shakti/auth likely rotated');

                return null;
            }
            if (count(array_filter($anchorLevels, fn ($l) => $l <= $ceiling)) === 0) {
                $this->abort("anchors' maturity all exceed ceiling ($ceiling) — maturity_ceiling likely misconfigured");

                return null;
            }
        } catch (\Throwable $e) {
            $this->abort('session validation failed: ' . $e->getMessage());

            return null;
        }
        $this->info("✓ US Kids session OK (region={$session['country']}, search + maturity anchors verified).");

        return $session;
    }

    private function staleFloor(): ?Carbon
    {
        // --force: re-verify everything. Otherwise re-verify titles older than --stale-days
        // (or the configured default); more-recently-checked titles are skipped so an aborted run resumes.
        if ($this->option('force')) {
            return null;
        }
        $days = $this->option('stale-days') !== null
            ? (int) $this->option('stale-days')
            : (int) config('services.netflix_kids.default_stale_days', 14);

        return now()->subDays($days);
    }

    /**
     * Work set: currently-playable US-Netflix titles, checked_at gated.
     *
     * @return array{0: array<string,array{title:string,nfid:int}>, 1: int} [byNf keyed by title id, bad-link count]
     */
    private function loadCandidates(?Carbon $floor): array
    {
        // GROUP BY + MIN(link) yields exactly one deterministic link per title even when a
        // title has several qualifying Netflix offers, so $byNf can't be clobbered.
        $rows = DB::table('streaming_titles as st')
            ->join('streaming_title_offers as o', 'o.title_id', '=', 'st.id')
            ->where(fn ($q) => $this->scopePlayableNetflix($q, 'o'))
            ->when($floor !== null, fn ($q) => $q->where(fn ($w) => $w
                ->whereNull('st.netflix_kids_checked_at')->orWhere('st.netflix_kids_checked_at', '<', $floor)))
            ->groupBy('st.id', 'st.title')
            ->select('st.id', 'st.title', DB::raw('MIN(o.link) as link'))
            ->get();

        $byNf = [];
        $badLinks = 0;
        foreach ($rows as $r) {
            if (preg_match('#/title/(\d+)#', $r->link, $m)) {
                $byNf[$r->id] = ['title' => $r->title, 'nfid' => (int) $m[1]];
            } else {
                $badLinks++;
            }
        }

        return [$byNf, $badLinks];
    }

    /** Stage 1: batched maturity lookup. Returns id=>level map, or null after aborting on failure. */
    private function fetchMaturity(NetflixKidsClient $client, array $byNf, array $session): ?array
    {
        $nfids = array_values(array_map(fn ($x) => $x['nfid'], $byNf));
        if (count($nfids) === 0) {
            return [];
        }
        $this->info(sprintf('Stage 1: fetching Netflix maturity for %d titles…', count($nfids)));
        $bar = $this->output->createProgressBar(count($nfids));
        $bar->start();
        try {
            $levels = $client->maturityLevels(
                $nfids, $session['shakti_url'], $session['auth_url'],
                fn (int $done, int $total) => $bar->setProgress(min($done, $total))
            );
        } catch (\Throwable $e) {
            $bar->finish();
            $this->newLine();
            $this->abort('maturity fetch failed mid-run: ' . $e->getMessage());

            return null;
        }
        $bar->finish();
        $this->newLine();

        return $levels;
    }

    /** Stage 2: per-title Kids search with batched writes; null maturity = unknown (kept null, but converged). */
    private function runSearchStage(NetflixKidsClient $client, string $app, array $byNf, array $levels, int $ceiling): int
    {
        $this->info(sprintf('Stage 2: searching Kids catalog (ceiling=maturityLevel %d)…', $ceiling));
        $delay = (float) config('services.netflix_kids.search_delay');

        $surfaced = 0;
        $pruned = 0;
        $unknown = 0;
        $skipped = 0;
        $pendingTrue = [];
        $pendingFalse = [];
        $pendingNull = [];
        $flush = function () use (&$pendingTrue, &$pendingFalse, &$pendingNull): void {
            $now = now();
            if ($pendingTrue) {
                DB::table('streaming_titles')->whereIn('id', $pendingTrue)
                    ->update(['netflix_kids_surfaced' => true, 'netflix_kids_checked_at' => $now]);
                $pendingTrue = [];
            }
            if ($pendingFalse) {
                DB::table('streaming_titles')->whereIn('id', $pendingFalse)
                    ->update(['netflix_kids_surfaced' => false, 'netflix_kids_checked_at' => $now]);
                $pendingFalse = [];
            }
            if ($pendingNull) {
                DB::table('streaming_titles')->whereIn('id', $pendingNull)
                    ->update(['netflix_kids_surfaced' => null, 'netflix_kids_checked_at' => $now]);
                $pendingNull = [];
            }
        };

        $bar = $this->output->createProgressBar(count($byNf));
        $bar->setFormat(' %current%/%max% [%bar%] %message%');
        $bar->setMessage('starting…');
        $bar->start();
        $didSearch = false;
        foreach ($byNf as $titleId => $info) {
            $level = $levels[$info['nfid']] ?? null;
            if ($level === null) {
                // Unknown maturity (not in the catalog response / partial failure): record null +
                // checked_at so it converges (skipped until stale) yet still falls to the heuristic;
                // never write a definitive false off missing data.
                $pendingNull[] = $titleId;
                $unknown++;
            } elseif ($level > $ceiling) {
                $pendingFalse[] = $titleId;
                $pruned++;
            } else {
                if ($delay > 0 && $didSearch) {
                    usleep((int) ($delay * 1_000_000)); // throttle BETWEEN searches — no wasted trailing sleep
                }
                try {
                    $hit = $client->searchHasId($info['title'], $info['nfid'], $app);
                } catch (\Throwable $e) {
                    $skipped++;
                    $this->newLine();
                    $this->warn("  skip {$info['nfid']} ({$info['title']}): {$e->getMessage()}");
                    $bar->advance();
                    continue; // leave unchecked so a later run retries it
                }
                $didSearch = true;
                if ($hit) {
                    $pendingTrue[] = $titleId;
                    $surfaced++;
                } else {
                    $pendingFalse[] = $titleId;
                }
            }
            if (count($pendingTrue) + count($pendingFalse) + count($pendingNull) >= self::WRITE_BATCH) {
                $flush();
            }
            $bar->setMessage(sprintf('surfaced=%d skipped=%d :: %s', $surfaced, $skipped, $info['title']));
            $bar->advance();
        }
        $flush();
        $bar->finish();
        $this->newLine();

        $this->info(sprintf(
            'Done. candidates=%d surfaced=%d pruned=%d unknown=%d failed=%d.',
            count($byNf), $surfaced, $pruned, $unknown, $skipped
        ));

        return self::SUCCESS;
    }

    private function abort(string $why): int
    {
        $this->error("Netflix Kids verification aborted: $why. No data written. "
            . 'Refresh NETFLIX_KIDS_COOKIE (US VPN, Kids profile) and/or NETFLIX_KIDS_PERSISTED_QUERY_ID.');

        return self::FAILURE;
    }

    /**
     * Single source of truth for "a currently-playable US Netflix offer", applied (with the given
     * table alias) to both the work-set join and the resetOrphans EXISTS subquery so they can't drift.
     */
    private function scopePlayableNetflix($q, string $a)
    {
        return $q->where("$a.service_id", 'netflix')
            ->where("$a.region", 'US')
            ->where("$a.link", 'like', '%/title/%')  // LIKE (not pg `~`) keeps this portable to sqlite tests; PHP preg_match extracts the id
            ->where(fn ($w) => $w->whereNull("$a.available_from")->orWhere("$a.available_from", '<=', now()))
            ->where(fn ($w) => $w->whereNull("$a.expires_on")->orWhere("$a.expires_on", '>', now()));
    }

    /** Titles whose only/any qualifying playable US-Netflix offer is gone revert to null. */
    private function resetOrphans(): void
    {
        $hasOffer = DB::table('streaming_title_offers as o')
            ->whereColumn('o.title_id', 'streaming_titles.id');
        $this->scopePlayableNetflix($hasOffer, 'o');

        DB::table('streaming_titles')
            ->whereNotNull('netflix_kids_checked_at')
            ->whereNotExists($hasOffer)
            ->update(['netflix_kids_surfaced' => null, 'netflix_kids_checked_at' => null]);
    }
}
