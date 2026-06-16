<?php

namespace App\Console\Commands;

use App\Mail\NightlyDigest;
use App\Services\Ops\HealthReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class OpsNightlyDigest extends Command
{
    protected $signature = 'ops:nightly-digest {--dry-run : Render the verdict to stdout without sending}';

    protected $description = 'Email a daily health digest of the nightly cron runs.';

    public function handle(): int
    {
        $report = HealthReport::build();

        foreach ($report->jobs as $job) {
            $this->line("{$job->emoji()} {$job->label}: {$job->summary}");
        }
        $this->info("Overall: {$report->overall}");

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $to = config('ops.digest.to');
        if (empty($to)) {
            $this->error('ops.digest.to is not configured — cannot send digest.');

            return self::FAILURE;
        }

        Mail::to($to)->send(new NightlyDigest($report));
        $this->info('Digest sent to '.$to);

        return self::SUCCESS;
    }
}
