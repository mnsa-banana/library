<?php

namespace App\Console\Commands;

use App\Models\StreamingService;
use App\Models\StreamingSyncLog;
use App\Services\StreamingAvailability\Client;
use Illuminate\Console\Command;

class StreamingRefreshServices extends Command
{
    protected $signature = 'streaming:refresh-services';
    protected $description = 'Fetch the US service catalog and upsert streaming_services rows';

    public function handle(): int
    {
        $log = StreamingSyncLog::create(['sync_type' => 'service_refresh', 'status' => 'running']);
        $client = new Client($log);

        try {
            $data = $client->get('/countries/us');
            $services = $data['services'] ?? [];

            $upserted = 0;
            foreach ($services as $svc) {
                $imageSet = $svc['imageSet'] ?? [];
                StreamingService::updateOrCreate(
                    ['id' => $svc['id']],
                    [
                        'name' => $svc['name'],
                        'theme_color' => $svc['themeColorCode'] ?? null,
                        'logo_light' => $imageSet['lightThemeImage'] ?? null,
                        'logo_dark' => $imageSet['darkThemeImage'] ?? null,
                    ],
                );
                $upserted++;
            }

            $log->update([
                'status' => 'completed',
                'completed_at' => now(),
                'titles_processed' => $upserted,
            ]);
            $this->info("Upserted {$upserted} services. Calls used: {$log->fresh()->api_calls_used}");
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $log->update(['status' => 'failed', 'completed_at' => now(), 'error_message' => $e->getMessage()]);
            $this->error("Refresh failed: {$e->getMessage()}");
            return self::FAILURE;
        }
    }
}
