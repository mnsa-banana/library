<?php

namespace App\Services\Ops;

use App\Models\BookLibraryTitle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class HealthReport
{
    /** @param JobHealth[] $jobs */
    public function __construct(
        public array $jobs,
        public string $overall,
    ) {}

    public static function build(): self
    {
        $staleHours = (int) config('ops.digest.pipeline_stale_hours', 26);
        $jobs = [];

        foreach (config('ops.watch', []) as $w) {
            if (($w['cadence'] ?? null) !== 'daily') {
                throw new RuntimeException("Unsupported cadence '{$w['cadence']}' for '{$w['key']}'; v1 handles 'daily' only.");
            }
            $jobs[] = self::assessDaily($w, $staleHours);
        }

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
            $total = BookLibraryTitle::count();
            $enriched = BookLibraryTitle::whereNotNull('enriched_at')->count();
            $pct = $total > 0 ? round($enriched / $total * 100, 1) : 0;

            return $base.' — '.($row->titles_processed ?? 0).' enriched tonight; '
                ."{$enriched}/{$total} = {$pct}% overall";
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
}
