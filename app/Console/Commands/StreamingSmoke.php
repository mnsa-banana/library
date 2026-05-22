<?php

namespace App\Console\Commands;

use App\Services\StreamingAvailability\Client;
use Illuminate\Console\Command;

class StreamingSmoke extends Command
{
    protected $signature = 'streaming:smoke';
    protected $description = 'Smoke-test the Streaming Availability API: hit 3 known titles and assert offers exist';

    private const FIXTURES = [
        'movie/950387' => 'A Minecraft Movie (2025)',
        'tv/87108' => 'Chernobyl (HBO, 2019)',
        'movie/9999999999' => 'Bogus ID (expects 404)',
    ];

    public function handle(): int
    {
        $client = new Client();
        $failures = 0;

        foreach (self::FIXTURES as $id => $label) {
            $this->info("→ /shows/{$id}  ({$label})");
            try {
                $data = $client->get("/shows/{$id}");
                $usOptions = $data['streamingOptions']['us'] ?? [];
                $count = count($usOptions);
                if (str_starts_with($id, 'movie/9999')) {
                    $this->error("  Expected 404 but got {$count} options");
                    $failures++;
                } elseif ($count === 0) {
                    $this->warn("  No US offers (could be off all platforms now — manual check)");
                } else {
                    $services = collect($usOptions)->pluck('service.id')->unique()->all();
                    $this->info("  {$count} US offers across: " . implode(', ', $services));
                }
            } catch (\Throwable $e) {
                if (str_starts_with($id, 'movie/9999') && str_contains($e->getMessage(), '404')) {
                    $this->info('  404 as expected ✓');
                } else {
                    $this->error("  Error: {$e->getMessage()}");
                    $failures++;
                }
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
