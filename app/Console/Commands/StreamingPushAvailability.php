<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class StreamingPushAvailability extends Command
{
    protected $signature = 'streaming:push-availability';

    protected $description = 'Push the full Netflix-US imdb_id set to MNSA so it can reconcile its reports.on_netflix_us flags.';

    public function handle(): int
    {
        $baseUrl = (string) config('services.mnsa.base_url');
        $token = (string) config('services.mnsa.service_token');
        $timeout = (int) config('services.mnsa.http_timeout', 30);

        if ($baseUrl === '' || $token === '') {
            $this->error('MNSA_BASE_URL and MNSA_SERVICE_TOKEN must be configured.');

            return self::FAILURE;
        }

        $imdbIds = DB::table('streaming_title_offers as sto')
            ->join('streaming_titles as st', 'st.id', '=', 'sto.title_id')
            ->where('sto.service_id', 'netflix')
            ->where('sto.region', 'US')
            ->where('sto.type', 'subscription')
            ->whereNotNull('st.imdb_id')
            ->distinct()
            ->pluck('st.imdb_id')
            ->sort()
            ->values()
            ->all();

        $this->info('Computed '.count($imdbIds).' distinct Netflix-US imdb_ids.');

        $url = rtrim($baseUrl, '/').'/api/v1/internal/netflix-availability';
        $response = Http::timeout($timeout)
            ->retry(1, 1000)
            ->withToken($token)
            ->acceptJson()
            ->post($url, ['imdb_ids' => $imdbIds]);

        if ($response->failed()) {
            Log::error('streaming:push-availability — MNSA push failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            $this->error('Push failed: '.$response->status().' '.$response->body());

            return self::FAILURE;
        }

        $summary = $response->json();
        $this->info('MNSA acknowledged: '.json_encode($summary));

        return self::SUCCESS;
    }
}
