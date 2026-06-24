<?php

namespace App\Console\Commands;

use App\Models\StreamingSyncLog;
use App\Models\StreamingTitleOffer;
use App\Services\NetflixKids\NetflixKidsClient;
use App\Services\StreamingAvailability\TitleResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-shot maintenance: re-check every source='discovery' Netflix-US offer against
 * the title it actually points at, and move offers the old (year-blind) TitleResolver
 * mis-assigned to a same-name sibling (e.g. Netflix-Kids "Fearless" 2020 stamped onto
 * the 1993 drama).
 *
 * Each discovery offer stores its Netflix videoId in the link, and that videoId is the
 * REAL Kids video — only the streaming_title it was attached to could be wrong. So we
 * re-fetch each videoId's {title, releaseYear} from Netflix and re-resolve with the now
 * year-aware resolver. We only ever delete+move on a positive, unique re-resolution to a
 * DIFFERENT title; anything we can't confidently place is left untouched and reported.
 *
 * Read-only by default (dry run). Pass --apply to perform the moves. Not scheduled.
 */
class StreamingReconcileNetflixDiscovery extends Command
{
    protected $signature = 'streaming:reconcile-netflix-discovery {--apply : perform the deletes/moves (default is a read-only dry run)}';

    protected $description = 'Move Netflix discovery offers the year-blind resolver mis-assigned to a same-name title';

    public function handle(NetflixKidsClient $netflix, TitleResolver $resolver): int
    {
        // TitleResolver loads the full ~115k-title catalog into an in-memory index (~180MB).
        ini_set('memory_limit', '512M');

        $apply = (bool) $this->option('apply');
        $this->info($apply ? 'Mode: APPLY (offers will be moved).' : 'Mode: dry run (no writes). Pass --apply to act.');

        $session = $netflix->probeSession();
        if (($session['country'] ?? null) !== 'US' || ! ($session['is_kids'] ?? false)
            || empty($session['member_api_url']) || empty($session['auth_url'])) {
            $this->error('Netflix Kids session invalid — refresh NETFLIX_KIDS_COOKIE. Nothing touched.');

            return self::FAILURE;
        }
        $member = $session['member_api_url'];
        $auth = $session['auth_url'];

        // Every source='discovery' Netflix-US offer, with the title it currently points at.
        $offers = DB::table('streaming_title_offers as o')
            ->join('streaming_titles as t', 't.id', '=', 'o.title_id')
            ->where('o.service_id', 'netflix')->where('o.region', 'US')->where('o.source', 'discovery')
            ->get(['o.id as offer_id', 'o.title_id', 'o.link', 't.title', 't.show_type', 't.imdb_id']);
        $this->info("Discovery Netflix-US offers to check: {$offers->count()}");

        // Map each offer to its Netflix videoId; collect ids to re-fetch in one batch.
        $videoIds = [];
        $badLink = [];
        foreach ($offers as $o) {
            $vid = StreamingTitleOffer::netflixVideoIdFromLink($o->link);
            if ($vid === null) {
                $badLink[] = $o;

                continue;
            }
            $o->video_id = $vid;
            $videoIds[$vid] = true;
        }
        $meta = $netflix->resolveVideoTitles(array_keys($videoIds), $member, $auth);

        $moves = [];
        $review = [];
        $unresolved = [];
        $ok = 0;
        foreach ($offers as $o) {
            if (! isset($o->video_id)) {
                continue; // already in $badLink
            }
            $m = $meta[$o->video_id] ?? null;
            if ($m === null) {
                $unresolved[] = $o; // video no longer resolves (likely left Netflix) — leave to staleness path

                continue;
            }
            $correctId = $resolver->resolve($m['title'], $o->show_type, $m['year']);
            if ($correctId === $o->title_id) {
                $ok++;
            } elseif ($correctId !== null) {
                $o->nf_title = $m['title'];
                $o->nf_year = $m['year'];
                $o->correct_id = $correctId;
                $o->correct_imdb = DB::table('streaming_titles')->where('id', $correctId)->value('imdb_id');
                $moves[] = $o;
            } else {
                $o->nf_title = $m['title'];
                $o->nf_year = $m['year'];
                // Surface WHY it could not be placed: the same-name candidates it saw + their
                // years (so a near-miss — e.g. Netflix 2024 vs our 2025, or a series whose
                // releaseYear ≠ first_air_year — is visible rather than silently shelved).
                $cands = $resolver->exactCandidates($m['title'], $o->show_type);
                $imdbById = $cands === []
                    ? []
                    : DB::table('streaming_titles')->whereIn('id', array_column($cands, 'id'))->pluck('imdb_id', 'id')->all();
                $o->candidates = array_map(fn ($c) => [
                    'id' => $c['id'], 'year' => $c['year'], 'imdb' => $imdbById[$c['id']] ?? null,
                    'current' => $c['id'] === $o->title_id,
                ], $cands);
                $review[] = $o; // can't confidently place (ambiguous / no year) — never auto-delete
            }
        }

        $this->report($moves, $review, $unresolved, $badLink, $ok);

        if ($apply && $moves !== []) {
            $this->applyMoves($moves);
            $this->info('Applied '.count($moves).' move(s).');
        } elseif ($moves !== []) {
            $this->warn('Dry run: '.count($moves).' move(s) NOT applied. Re-run with --apply to act.');
        }

        StreamingSyncLog::create([
            'sync_type' => 'reconcile_netflix_discovery',
            'started_at' => now(), 'completed_at' => now(), 'status' => 'completed',
            'titles_processed' => $apply ? count($moves) : 0,
            'metadata' => [
                'applied' => $apply,
                'checked' => $offers->count(),
                'ok' => $ok,
                'moved' => count($moves),
                'review' => count($review),
                'unresolved' => count($unresolved),
                'bad_link' => count($badLink),
                'moves' => array_map(fn ($o) => [
                    'video_id' => $o->video_id, 'nf_title' => $o->nf_title, 'nf_year' => $o->nf_year,
                    'from' => $o->title_id, 'from_imdb' => $o->imdb_id,
                    'to' => $o->correct_id, 'to_imdb' => $o->correct_imdb,
                ], array_slice($moves, 0, 200)),
                'review_detail' => array_map(fn ($o) => [
                    'video_id' => $o->video_id, 'nf_title' => $o->nf_title, 'nf_year' => $o->nf_year,
                    'sits_on' => $o->title_id, 'sits_on_imdb' => $o->imdb_id, 'candidates' => $o->candidates,
                ], array_slice($review, 0, 200)),
            ],
        ]);

        return self::SUCCESS;
    }

    /** Delete each mis-assigned offer and re-create it on the correct title, then reset orphaned flags. */
    private function applyMoves(array $moves): void
    {
        $vacated = [];
        foreach ($moves as $o) {
            DB::transaction(function () use ($o) {
                DB::table('streaming_title_offers')->where('id', $o->offer_id)->delete();
                StreamingTitleOffer::upsertDiscoveryNetflix($o->correct_id, $o->video_id);
            });
            $vacated[$o->title_id] = true;
        }

        // A title we pulled the offer off of, that now has no playable US-Netflix offer,
        // must not keep a stale netflix_kids_surfaced=true. (verify-kids' resetOrphans would
        // also catch this next run; we do it now so the read API is correct immediately.)
        foreach (array_keys($vacated) as $titleId) {
            $stillHasOffer = DB::table('streaming_title_offers')
                ->where('title_id', $titleId)->where('service_id', 'netflix')->where('region', 'US')
                ->where('link', 'like', '%/title/%')->exists();
            if (! $stillHasOffer) {
                DB::table('streaming_titles')->where('id', $titleId)
                    ->update(['netflix_kids_surfaced' => null, 'netflix_kids_checked_at' => null]);
            }
        }
    }

    private function report(array $moves, array $review, array $unresolved, array $badLink, int $ok): void
    {
        $this->newLine();
        if ($moves !== []) {
            $this->line('<comment>MOVES (mis-assigned → correct title):</comment>');
            $this->table(
                ['videoId', 'Netflix title', 'yr', 'from id', 'from imdb', '→ to id', 'to imdb'],
                array_map(fn ($o) => [
                    $o->video_id, $o->nf_title, $o->nf_year ?? '—',
                    $o->title_id, $o->imdb_id ?? '—', $o->correct_id, $o->correct_imdb ?? '—',
                ], $moves)
            );
        }
        if ($review !== []) {
            $this->line('<comment>REVIEW (resolver returned null — left as-is; candidates it saw shown below):</comment>');
            foreach ($review as $o) {
                $this->line(sprintf('  • %s — Netflix yr=%s — offer currently on %s/%s',
                    $o->nf_title, $o->nf_year ?? '—', $o->title_id, $o->imdb_id ?? '—'));
                if ($o->candidates === []) {
                    $this->line('      (no exact-name candidates — matched via containment ambiguity)');
                }
                foreach ($o->candidates as $c) {
                    $this->line(sprintf('      - %-10s %-12s yr=%-6s%s',
                        $c['id'], $c['imdb'] ?? '—', $c['year'] ?? '—', $c['current'] ? '  ← current' : ''));
                }
            }
        }
        if ($unresolved !== []) {
            $this->line('  '.count($unresolved).' offer(s) whose videoId no longer resolves (left to staleness path).');
        }
        if ($badLink !== []) {
            $this->line('  '.count($badLink).' offer(s) with no numeric /title/<id> link (skipped).');
        }
        $this->info("Summary: ok={$ok} moves=".count($moves).' review='.count($review)
            .' unresolved='.count($unresolved).' bad_link='.count($badLink));
    }
}
