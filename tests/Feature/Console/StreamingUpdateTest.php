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
     * @param  array<int, string>  $throwSteps  signatures that throw instead of returning
     */
    private function stubSteps(array $failCodes = [], array $throwSteps = []): void
    {
        $calls = &$this->calls;

        Artisan::command('streaming:sync {--hours=}', function () use (&$calls, $failCodes, $throwSteps) {
            $calls[] = 'streaming:sync:'.$this->option('hours');

            if (in_array('streaming:sync', $throwSteps, true)) {
                throw new \RuntimeException('boom');
            }

            return $failCodes['streaming:sync'] ?? 0;
        });

        foreach (['streaming:enrich', 'streaming:verify-kids', 'streaming:push-availability'] as $name) {
            Artisan::command($name, function () use (&$calls, $name, $failCodes, $throwSteps) {
                $calls[] = $name;

                if (in_array($name, $throwSteps, true)) {
                    throw new \RuntimeException('boom');
                }

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

    public function test_returns_failure_and_skips_remaining_steps_when_a_step_throws(): void
    {
        $this->stubSteps(throwSteps: ['streaming:verify-kids']);

        $this->artisan('streaming:update')->assertExitCode(1);

        $this->assertSame([
            'streaming:sync:72',
            'streaming:enrich',
            'streaming:verify-kids',
        ], $this->calls);
        $this->assertNotContains('streaming:push-availability', $this->calls);
    }

    public function test_rejects_non_integer_hours_without_running_any_step(): void
    {
        $this->stubSteps();

        $this->artisan('streaming:update', ['--hours' => 'abc'])->assertExitCode(2);

        $this->assertSame([], $this->calls);
    }

    public function test_rejects_non_positive_hours_without_running_any_step(): void
    {
        $this->stubSteps();

        $this->artisan('streaming:update', ['--hours' => 0])->assertExitCode(2);
        $this->artisan('streaming:update', ['--hours' => -5])->assertExitCode(2);

        $this->assertSame([], $this->calls);
    }
}
