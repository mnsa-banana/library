<?php

namespace App\Services\Ops;

use App\Models\BookLibraryTitle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class HealthReport
{
    /** @param JobHealth[] $jobs */
    public function __construct(
        public array $jobs,
        public string $overall,
    ) {}

    public static function build(): self
    {
        $staleHours = (int) config('ops.digest.pipeline_stale_hours');
        $jobs = [];

        foreach (config('ops.watch', []) as $w) {
            if (($w['cadence'] ?? null) !== 'daily') {
                $jobs[] = new JobHealth(
                    $w['key'] ?? '?',
                    $w['label'] ?? ($w['key'] ?? '?'),
                    'warn',
                    "cadence '".($w['cadence'] ?? 'null')."' not yet supported (digest handles 'daily' only)",
                );

                continue;
            }
            $jobs[] = self::assessDaily($w, $staleHours);
        }

        self::reconcileVerifyKids($jobs);

        $rank = ['ok' => 0, 'warn' => 1, 'fail' => 2];
        $overall = 'ok';
        foreach ($jobs as $j) {
            if ($rank[$j->verdict] > $rank[$overall]) {
                $overall = $j->verdict;
            }
        }

        return new self($jobs, $overall);
    }

    /** @param array{key:string,table:string,type:string,label:string,cadence:string} $w */
    private static function assessDaily(array $w, int $staleHours): JobHealth
    {
        $row = DB::table($w['table'])->where('sync_type', $w['type'])->latest('id')->first();

        if ($row === null) {
            return new JobHealth($w['key'], $w['label'], 'fail', 'no runs ever recorded');
        }

        $started = $row->started_at ? Carbon::parse($row->started_at) : null;
        $completed = $row->completed_at ? Carbon::parse($row->completed_at) : null;

        if ($row->status === 'failed') {
            return new JobHealth($w['key'], $w['label'], 'fail',
                'failed'.($row->error_message ? ': '.$row->error_message : ''), $completed ?? $started);
        }

        if ($completed === null) {
            // running / no terminal row. Started today → incomplete; otherwise stale.
            $verdict = ($started && $started->gte(now()->subHours($staleHours))) ? 'warn' : 'fail';
            $msg = $verdict === 'warn' ? 'still running at digest time' : 'last run never completed';

            return new JobHealth($w['key'], $w['label'], $verdict, $msg, $started);
        }

        if ($completed->lt(now()->subHours($staleHours))) {
            return new JobHealth($w['key'], $w['label'], 'fail',
                'no completed run in '.$staleHours.'h (last '.$completed->diffForHumans().')', $completed);
        }

        return new JobHealth($w['key'], $w['label'], 'ok', self::okSummary($w, $row, $completed), $completed);
    }

    /** Per-job "ok" detail line, with the streaming/book enrichments. */
    private static function okSummary(array $w, object $row, Carbon $completed): string
    {
        $dur = $row->started_at
            ? round(abs(Carbon::parse($row->started_at)->diffInSeconds($completed)) / 60, 1).' min'
            : '?';
        $base = 'completed '.$completed->format('H:i').' ('.$dur.')';

        if ($w['key'] === 'verify_kids') {
            $m = is_array($row->metadata) ? $row->metadata : (array) json_decode($row->metadata ?? '{}', true);
            return $base.' — session OK; checked '.($m['candidates'] ?? '?')
                .', surfaced '.($m['surfaced'] ?? '?').', pruned '.($m['pruned'] ?? '?');
        }

        if ($w['key'] === 'book_enrich') {
            $stats = BookLibraryTitle::selectRaw('count(*) as total, count(enriched_at) as enriched')->first();
            $total = (int) $stats->total;
            $enriched = (int) $stats->enriched;
            $remaining = $total - $enriched;
            $tonight = (int) ($row->titles_processed ?? 0);

            if ($total > 0 && $remaining === 0) {
                return $base.' — BACKFILL COMPLETE: all '.$total
                    .' titles enriched. Safe to remove the temporary book:seed/book:enrich crons.';
            }

            $pct = $total > 0 ? round($enriched / $total * 100, 1) : 0;
            $eta = $tonight > 0 ? ' (~'.(int) ceil($remaining / $tonight).' more nights)' : '';

            return $base.' — '.$tonight.' enriched tonight; '
                ."{$enriched}/{$total} = {$pct}%; {$remaining} remaining".$eta;
        }

        if ($w['key'] === 'book_seed') {
            $added = (int) ($row->titles_processed ?? 0);

            return $base.' — '.($added === 0
                ? 'nothing new to seed (nyt-history list exhausted)'
                : $added.' new titles seeded');
        }

        if ($w['key'] === 'streaming') {
            $changes = DB::table('streaming_sync_log')->where('sync_type', 'changes')
                ->where('status', 'completed')->latest('id')->first();
            $parts = [];
            if ($changes && ! empty($changes->titles_processed)) {
                $parts[] = $changes->titles_processed.' titles';
            }
            if ($changes && ! empty($changes->api_calls_used)) {
                $parts[] = $changes->api_calls_used.' calls';
            }

            return $base.($parts ? ' — '.implode(', ', $parts).' synced' : '');
        }

        $extra = [];
        if (! empty($row->titles_processed)) {
            $extra[] = $row->titles_processed.' titles';
        }
        if (! empty($row->api_calls_used)) {
            $extra[] = $row->api_calls_used.' calls';
        }

        return $base.($extra ? ' — '.implode(', ', $extra) : '');
    }

    /**
     * verify-kids runs as the last fail-fast step of streaming:update; on a night an
     * earlier step fails it's skipped and writes no row, so the prior night's row can
     * still look fresh. If the latest pipeline attempt started after the latest
     * verify_kids run completed, this cycle's Kids check didn't run — downgrade an
     * otherwise-ok verdict so a green line can't sit next to a failed pipeline.
     *
     * @param JobHealth[] $jobs
     */
    private static function reconcileVerifyKids(array &$jobs): void
    {
        $idx = null;
        foreach ($jobs as $i => $j) {
            if ($j->key === 'verify_kids') {
                $idx = $i;
                break;
            }
        }
        if ($idx === null || $jobs[$idx]->verdict !== 'ok') {
            return; // only override a verdict that would otherwise read healthy
        }

        $pipe = DB::table('streaming_sync_log')->where('sync_type', 'pipeline')->latest('id')->first();
        if (! $pipe || ! $pipe->started_at) {
            return;
        }
        $pipeStarted = Carbon::parse($pipe->started_at);

        $vkRow = DB::table('streaming_sync_log')->where('sync_type', 'verify_kids')->latest('id')->first();
        $vkDone = ($vkRow && $vkRow->completed_at) ? Carbon::parse($vkRow->completed_at) : null;

        if ($vkDone === null || $vkDone->lt($pipeStarted)) {
            $jobs[$idx] = new JobHealth(
                $jobs[$idx]->key,
                $jobs[$idx]->label,
                'warn',
                'did not run in the latest pipeline cycle (an earlier step failed)',
                $jobs[$idx]->lastRun,
            );
        }
    }
}
