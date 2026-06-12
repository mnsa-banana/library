<?php

namespace Tests\Feature\Console;

use App\Models\StreamingSyncLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class StreamingUpdateTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> ordered record of which sub-commands ran */
    private array $calls = [];

    /**
     * Replace the three real pipeline sub-commands with closure stubs that record
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

        foreach (['streaming:enrich', 'streaming:verify-kids'] as $name) {
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
        ], $this->calls);
    }

    public function test_defaults_hours_to_72(): void
    {
        $this->stubSteps();

        $this->artisan('streaming:update')->assertExitCode(0);

        $this->assertSame('streaming:sync:72', $this->calls[0]);
    }

    public function test_records_completed_pipeline_run_in_sync_log(): void
    {
        $this->stubSteps();

        $this->artisan('streaming:update')->assertExitCode(0);

        $log = StreamingSyncLog::where('sync_type', 'pipeline')->sole();
        $this->assertSame('completed', $log->status);
        $this->assertNotNull($log->completed_at);
        $this->assertSame(72, $log->metadata['hours']);
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
        $this->assertSame('streaming:verify-kids', end($this->calls));
        $this->assertCount(3, $this->calls);

        $log = StreamingSyncLog::where('sync_type', 'pipeline')->sole();
        $this->assertSame('failed', $log->status);
        $this->assertSame('streaming:verify-kids', $log->metadata['failed_step']);
        $this->assertSame(2, $log->metadata['exit_code']);
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
        $this->assertSame('streaming:verify-kids', end($this->calls));
        $this->assertCount(3, $this->calls);

        $log = StreamingSyncLog::where('sync_type', 'pipeline')->sole();
        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString('boom', $log->error_message);
    }

    public function test_rejects_non_integer_hours_without_running_any_step(): void
    {
        $this->stubSteps();

        $this->artisan('streaming:update', ['--hours' => 'abc'])->assertExitCode(2);

        $this->assertSame([], $this->calls);
        $this->assertSame(0, StreamingSyncLog::count());
    }

    public function test_rejects_non_positive_hours_without_running_any_step(): void
    {
        $this->stubSteps();

        $this->artisan('streaming:update', ['--hours' => 0])->assertExitCode(2);
        $this->artisan('streaming:update', ['--hours' => -5])->assertExitCode(2);

        $this->assertSame([], $this->calls);
    }

    public function test_rejects_hours_above_the_cap_without_running_any_step(): void
    {
        $this->stubSteps();

        $this->artisan('streaming:update', ['--hours' => 999999])->assertExitCode(2);

        $this->assertSame([], $this->calls);
    }

    public function test_aborts_when_another_run_holds_the_lock(): void
    {
        $this->stubSteps();

        $lock = Cache::lock('streaming:update', 60);
        $this->assertTrue($lock->get());

        try {
            $this->artisan('streaming:update')->assertExitCode(1);
        } finally {
            $lock->release();
        }

        $this->assertSame([], $this->calls);
        $this->assertSame(0, StreamingSyncLog::count());
    }

    public function test_releases_the_lock_after_a_run(): void
    {
        $this->stubSteps();

        $this->artisan('streaming:update')->assertExitCode(0);

        $lock = Cache::lock('streaming:update', 60);
        $this->assertTrue($lock->get(), 'lock should be free after the run completes');
        $lock->release();
    }
}
