<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class StreamingUpdate extends Command
{
    protected $signature = 'streaming:update {--hours=72 : Lookback window in hours for the sync step}';

    protected $description = 'Run the full streaming refresh pipeline (sync → enrich → verify-kids → push-availability), fail-fast.';

    public function handle(): int
    {
        $hours = filter_var($this->option('hours'), FILTER_VALIDATE_INT);
        if ($hours === false || $hours < 1) {
            $this->error(sprintf('--hours must be a positive integer, got "%s".', $this->option('hours')));

            return self::INVALID;
        }

        $pipelineStartedAt = microtime(true);
        Log::info('streaming:update started', ['hours' => $hours]);

        foreach ($this->steps($hours) as [$signature, $params]) {
            $this->newLine();
            $this->info("▶ {$signature}");
            $stepStartedAt = microtime(true);

            try {
                $code = $this->call($signature, $params);
            } catch (\Throwable $e) {
                Log::error('streaming:update step threw; remaining steps skipped', [
                    'step' => $signature,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                    'elapsed_seconds' => $this->elapsedSeconds($stepStartedAt),
                ]);
                $this->error("✗ {$signature} threw {$e->getMessage()} after {$this->elapsedSeconds($stepStartedAt)}s. Stopping; remaining steps skipped.");

                return self::FAILURE;
            }

            $elapsed = $this->elapsedSeconds($stepStartedAt);

            if ($code !== self::SUCCESS) {
                Log::error('streaming:update step failed; remaining steps skipped', [
                    'step' => $signature,
                    'exit_code' => $code,
                    'elapsed_seconds' => $elapsed,
                ]);
                $this->error("✗ {$signature} failed (exit {$code}) after {$elapsed}s. Stopping; remaining steps skipped.");

                return $code;
            }

            $this->info("✓ {$signature} finished in {$elapsed}s");
        }

        $totalElapsed = $this->elapsedSeconds($pipelineStartedAt);
        Log::info('streaming:update completed', ['elapsed_seconds' => $totalElapsed]);
        $this->newLine();
        $this->info("✓ streaming:update complete — all steps succeeded in {$totalElapsed}s.");

        return self::SUCCESS;
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
