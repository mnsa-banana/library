<?php

namespace App\Console\Commands;

use App\Models\StreamingService;
use App\Models\StreamingSyncLog;
use App\Models\StreamingTitle;
use App\Models\StreamingTitleOffer;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class StreamingStatus extends Command
{
    protected $signature = 'streaming:status';
    protected $description = 'Print streaming sync status and statistics';

    public function handle(): int
    {
        $this->info('=== Streaming Availability Sync Status ===');
        $this->newLine();

        $lastSync = StreamingSyncLog::where('sync_type', 'changes')
            ->where('status', 'completed')->orderByDesc('completed_at')->first();
        if ($lastSync) {
            $this->info("Last sync: {$lastSync->completed_at} ({$lastSync->api_calls_used} calls)");
        } else {
            $this->warn('No completed sync yet.');
        }

        $running = StreamingSyncLog::where('status', 'running')->get();
        if ($running->isNotEmpty()) {
            $this->newLine();
            $this->warn("In-progress jobs: {$running->count()}");
            foreach ($running as $job) {
                $this->line("  - {$job->sync_type} (started {$job->started_at}, {$job->titles_processed} titles)");
            }
        }

        $monthStart = Carbon::now()->startOfMonth();
        $monthCalls = StreamingSyncLog::where('started_at', '>=', $monthStart)->sum('api_calls_used');
        $this->newLine();
        $this->info("API calls used this month: {$monthCalls}");

        $this->newLine();
        $this->info('=== Database Stats ===');
        $this->info('Services: ' . StreamingService::count());
        $this->info('Titles: ' . StreamingTitle::count());
        $this->info('Offers: ' . StreamingTitleOffer::count());

        $this->newLine();
        $this->info('=== Per-service title counts (US) ===');
        $rows = DB::table('streaming_title_offers as o')
            ->join('streaming_services as s', 's.id', '=', 'o.service_id')
            ->where('o.region', 'US')
            ->groupBy('s.id', 's.name')
            ->orderBy('s.name')
            ->select('s.name', DB::raw('COUNT(DISTINCT o.title_id) as titles'))
            ->get();
        $this->table(['Service', 'Titles'], $rows->map(fn ($r) => [$r->name, $r->titles])->all());

        $this->newLine();
        $this->info('=== Recent sync logs ===');
        $recent = StreamingSyncLog::orderByDesc('started_at')->limit(5)->get();
        $this->table(
            ['Type', 'Status', 'Started', 'Calls', 'Titles'],
            $recent->map(fn ($l) => [
                $l->sync_type, $l->status, $l->started_at?->format('Y-m-d H:i'),
                $l->api_calls_used, $l->titles_processed,
            ])->all(),
        );

        return self::SUCCESS;
    }
}
