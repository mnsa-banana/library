<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class StreamingUpdate extends Command
{
    protected $signature = 'streaming:update {--hours=72 : Lookback window in hours for the sync step}';

    protected $description = 'Run the full streaming refresh pipeline (sync → enrich → verify-kids → push-availability), fail-fast.';

    public function handle(): int
    {
        foreach ($this->steps() as [$signature, $params]) {
            $this->newLine();
            $this->info("▶ {$signature}");

            $code = $this->call($signature, $params);
            if ($code !== self::SUCCESS) {
                $this->error("✗ {$signature} failed (exit {$code}). Stopping; remaining steps skipped.");

                return $code;
            }
        }

        $this->newLine();
        $this->info('✓ streaming:update complete — all steps succeeded.');

        return self::SUCCESS;
    }

    /**
     * The pipeline, in order. Each entry is [command signature, parameters].
     * Only the sync step takes a parameter (the lookback window); the rest run
     * with their own defaults.
     *
     * @return array<int, array{0: string, 1: array<string, mixed>}>
     */
    private function steps(): array
    {
        return [
            ['streaming:sync', ['--hours' => (int) $this->option('hours')]],
            ['streaming:enrich', []],
            ['streaming:verify-kids', []],
            ['streaming:push-availability', []],
        ];
    }
}
