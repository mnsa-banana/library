<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ValidatesHoursOption;
use App\Models\StreamingSyncLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class StreamingUpdate extends Command
{
    use ValidatesHoursOption;

    /**
     * Held for the lifetime of a run so scheduled and manual invocations cannot
     * overlap. The TTL only matters when the process dies without reaching the
     * finally block (kill -9, OOM): it must exceed the longest legitimate run
     * yet clear a stale lock before the next 03:00 slot — 12h satisfies both.
     */
    private const LOCK_NAME = 'streaming:update';

    private const LOCK_TTL_SECONDS = 60 * 60 * 12;

    protected $signature = 'streaming:update {--hours=72 : Lookback window in hours for the sync step}';

    protected $description = 'Run the full streaming refresh pipeline (sync → enrich → verify-kids → push-availability), fail-fast.';

    public function handle(): int
    {
        $hours = $this->validatedHoursOption();
        if ($hours === null) {
            return self::INVALID;
        }

        $lock = Cache::lock(self::LOCK_NAME, self::LOCK_TTL_SECONDS);
        if (! $lock->get()) {
            Log::error('streaming:update skipped — another run still holds the lock');
            $this->error('Another streaming:update run is in progress; aborting.');

            return self::FAILURE;
        }

        try {
            return $this->runPipeline($hours);
        } finally {
            $lock->release();
        }
    }

    private function runPipeline(int $hours): int
    {
        $pipelineStartedAt = microtime(true);
        $log = StreamingSyncLog::create([
            'sync_type' => 'pipeline',
            'status' => 'running',
            'metadata' => ['hours' => $hours],
        ]);

        foreach ($this->steps($hours) as [$signature, $params]) {
            $this->newLine();
            $this->info("▶ {$signature}");
            $stepStartedAt = microtime(true);

            try {
                $code = $this->call($signature, $params);
            } catch (\Throwable $e) {
                return $this->failStep($log, $signature, self::FAILURE, $this->elapsedSeconds($stepStartedAt), "threw {$e->getMessage()}");
            }

            $elapsed = $this->elapsedSeconds($stepStartedAt);

            if ($code !== self::SUCCESS) {
                return $this->failStep($log, $signature, $code, $elapsed, "failed (exit {$code})");
            }

            $this->info("✓ {$signature} finished in {$elapsed}s");
        }

        $log->update(['status' => 'completed', 'completed_at' => now()]);
        $this->newLine();
        $this->info("✓ streaming:update complete — all steps succeeded in {$this->elapsedSeconds($pipelineStartedAt)}s.");

        return self::SUCCESS;
    }

    /**
     * Record a failed step on the pipeline's streaming_sync_log row (so
     * streaming:status surfaces it) and the application log, then propagate
     * the exit code.
     */
    private function failStep(StreamingSyncLog $log, string $signature, int $code, float $elapsed, string $reason): int
    {
        $log->update([
            'status' => 'failed',
            'completed_at' => now(),
            'error_message' => "{$signature} {$reason}",
            'metadata' => array_merge($log->metadata ?? [], ['failed_step' => $signature, 'exit_code' => $code]),
        ]);
        Log::error('streaming:update step failed; remaining steps skipped', [
            'step' => $signature,
            'exit_code' => $code,
            'reason' => $reason,
            'elapsed_seconds' => $elapsed,
        ]);
        $this->error("✗ {$signature} {$reason} after {$elapsed}s. Stopping; remaining steps skipped.");

        return $code;
    }

    /**
     * The pipeline, in order. Each entry is [command signature, parameters].
     * Only the sync step takes a parameter (the lookback window); the rest run
     * with their own defaults.
     *
     * @return array<int, array{0: string, 1: array<string, mixed>}>
     */
    private function steps(int $hours): array
    {
        return [
            ['streaming:sync', ['--hours' => $hours]],
            ['streaming:enrich', []],
            ['streaming:verify-kids', []],
            ['streaming:push-availability', []],
        ];
    }

    private function elapsedSeconds(float $startedAt): float
    {
        return round(microtime(true) - $startedAt, 1);
    }
}
