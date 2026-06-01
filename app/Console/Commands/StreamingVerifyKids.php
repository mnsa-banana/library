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

    /** Flush bulk writes every N processed titles (keeps resume ~granular, avoids 1 UPDATE/title). */
    private const WRITE_BATCH = 500;

    public function handle(NetflixKidsClient $client): int
    {
        $ceiling = (int) config('services.netflix_kids.maturity_ceiling');

        // ── Stage 0: validate session + BOTH endpoints BEFORE any write ──
        // Any failure here (bad region / expired cookie / rotated query or
        // shakti URL / transient error surviving retries) aborts with no writes.
        $this->info('Validating US Kids session…');
        try {
            $session = $client->probeSession();
            if (($session['country'] ?? null) !== 'US' || ! ($session['is_kids'] ?? false)
                || empty($session['auth_url']) || empty($session['shakti_url']) || empty($session['app_version'])) {
                return $this->abort('not a US Kids session (country=' . ($session['country'] ?? 'null') . ', is_kids=' . var_export($session['is_kids'] ?? null, true) . ')');
            }
            $app = $session['app_version'];

            // search path: in-anchors must surface, out-control must not
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

            // maturity path: anchors must return a usable (<= ceiling) level, else
            // shakti/auth has rotated and Stage 1 would wrongly prune everything.
            $anchorLevels = $client->maturityLevels(array_keys(self::ANCHORS_IN), $session['shakti_url'], $session['auth_url']);
            if (count(array_filter($anchorLevels, fn ($l) => $l !== null && $l <= $ceiling)) === 0) {
                return $this->abort('maturity endpoint returned no usable data for anchors — shakti/auth likely rotated');
            }
        } catch (\Throwable $e) {
            return $this->abort('session validation failed: ' . $e->getMessage());
        }
        $this->info("✓ US Kids session OK (region={$session['country']}, search + maturity anchors verified).");

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
            ->where(fn ($q) => $this->scopePlayableNetflix($q, 'o'))
            ->when($floor !== null, fn ($q) => $q->where(fn ($w) => $w
                ->whereNull('st.netflix_kids_checked_at')->orWhere('st.netflix_kids_checked_at', '<', $floor)))
            ->select('st.id', 'st.title', 'o.link')
            ->distinct()->get();

        // map id => nfid; a link that matched LIKE but has no numeric id is surfaced as a warning, not silently dropped
        $byNf = [];
        $badLinks = 0;
        foreach ($rows as $r) {
            if (preg_match('#/title/(\d+)#', $r->link, $m)) {
                $byNf[$r->id] = ['title' => $r->title, 'nfid' => (int) $m[1]];
            } else {
                $badLinks++;
            }
        }
        if ($badLinks > 0) {
            $this->warn("  {$badLinks} Netflix offer link(s) had no numeric /title/<id> — left unverified.");
        }
        $this->info('Candidates: ' . count($byNf) . ' currently-playable US Netflix titles to verify.');

        // ── Stage 1: maturity (abort on failure — before any write) ──────
        $nfids = array_values(array_map(fn ($x) => $x['nfid'], $byNf));
        $levels = [];
        if (count($nfids) > 0) {
            $this->info(sprintf('Stage 1: fetching Netflix maturity for %d titles…', count($nfids)));
            $matBar = $this->output->createProgressBar(count($nfids));
            $matBar->start();
            try {
                $levels = $client->maturityLevels(
                    $nfids, $session['shakti_url'], $session['auth_url'],
                    fn (int $done, int $total) => $matBar->setProgress(min($done, $total))
                );
            } catch (\Throwable $e) {
                $matBar->finish();
                $this->newLine();
                return $this->abort('maturity fetch failed mid-run: ' . $e->getMessage());
            }
            $matBar->finish();
            $this->newLine();
        }

        // ── Stage 2: per-title Kids search; null maturity = unknown (skip, leave null) ──
        $this->info(sprintf('Stage 2: searching Kids catalog (ceiling=maturityLevel %d)…', $ceiling));
        $delay = (float) config('services.netflix_kids.search_delay');
        $surfacedCount = 0;
        $skipped = 0;
        $pruned = 0;
        $unknown = 0;
        $pendingTrue = [];
        $pendingFalse = [];
        $flush = function () use (&$pendingTrue, &$pendingFalse): void {
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
        };
        $bar = $this->output->createProgressBar(count($byNf));
        $bar->setFormat(' %current%/%max% [%bar%] %message%');
        $bar->setMessage('starting…');
        $bar->start();
        foreach ($byNf as $titleId => $info) {
            $level = $levels[$info['nfid']] ?? null;
            if ($level === null) {
                // unknown maturity (not in catalog response / partial failure):
                // leave unchecked so the heuristic still applies and a later run retries.
                $unknown++;
                $bar->advance();
                continue;
            }
            if ($level > $ceiling) {
                $pendingFalse[] = $titleId;
                $pruned++;
            } else {
                try {
                    $surfaced = $client->searchHasId($info['title'], $info['nfid'], $app); // Stage 2
                } catch (\Throwable $e) {
                    $skipped++;
                    $this->newLine();
                    $this->warn("  skip {$info['nfid']} ({$info['title']}): {$e->getMessage()}");
                    $bar->advance();
                    continue; // leave unchecked so a later run retries it
                }
                if ($surfaced) {
                    $pendingTrue[] = $titleId;
                    $surfacedCount++;
                } else {
                    $pendingFalse[] = $titleId;
                }
                if ($delay > 0) { usleep((int) ($delay * 1_000_000)); }
            }
            if (count($pendingTrue) + count($pendingFalse) >= self::WRITE_BATCH) {
                $flush();
            }
            $bar->setMessage(sprintf('surfaced=%d skipped=%d :: %s', $surfacedCount, $skipped, $info['title']));
            $bar->advance();
        }
        $flush();
        $bar->finish();
        $this->newLine();

        $this->info(sprintf(
            'Done. candidates=%d surfaced=%d pruned=%d unknown=%d failed=%d.',
            count($byNf), $surfacedCount, $pruned, $unknown, $skipped
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
     * Single source of truth for "a currently-playable US Netflix offer".
     * Applied (with the given table alias) to both the work-set join and the
     * resetOrphans EXISTS subquery so the two can never drift.
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
