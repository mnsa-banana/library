<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class StreamingUpdateTest extends TestCase
{
    /** @var array<int, string> ordered record of which sub-commands ran */
    private array $calls = [];

    /**
     * Replace the four real pipeline sub-commands with closure stubs that record
     * their invocation order and return a scripted exit code. Overriding by name
     * shadows the real commands, so the orchestration is tested without real APIs.
     *
     * @param  array<string, int>  $failCodes  signature => non-zero exit code
     */
    private function stubSteps(array $failCodes = []): void
    {
        $calls = &$this->calls;

        Artisan::command('streaming:sync {--hours=}', function () use (&$calls, $failCodes) {
            $calls[] = 'streaming:sync:'.$this->option('hours');

            return $failCodes['streaming:sync'] ?? 0;
        });

        foreach (['streaming:enrich', 'streaming:verify-kids', 'streaming:push-availability'] as $name) {
            Artisan::command($name, function () use (&$calls, $name, $failCodes) {
                $calls[] = $name;

                return $failCodes[$name] ?? 0;
            });
        }
    }

    public function test_runs_all_steps_in_order_and_forwards_hours(): void
    {
        $this->stubSteps();

        $this->artisan('streaming:update', ['--hours' => 48])->assertExitCode(0);

        $this->assertSame([
            'streaming:sync:48',
            'streaming:enrich',
            'streaming:verify-kids',
            'streaming:push-availability',
        ], $this->calls);
    }

    public function test_defaults_hours_to_72(): void
    {
        $this->stubSteps();

        $this->artisan('streaming:update')->assertExitCode(0);

        $this->assertSame('streaming:sync:72', $this->calls[0]);
    }

    public function test_fails_fast_and_skips_remaining_steps(): void
    {
        $this->stubSteps(['streaming:verify-kids' => 2]);

        $this->artisan('streaming:update')->assertExitCode(2);

        $this->assertSame([
            'streaming:sync:72',
            'streaming:enrich',
            'streaming:verify-kids',
        ], $this->calls);
        $this->assertNotContains('streaming:push-availability', $this->calls);
    }
}
