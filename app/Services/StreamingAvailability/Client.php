<?php

namespace App\Services\StreamingAvailability;

use App\Models\StreamingSyncLog;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class Client
{
    private const MAX_RETRIES = 3;

    private string $apiKey;
    private string $baseUrl;
    private int $qps;
    private float $lastRequestAt = 0.0;
    private ?StreamingSyncLog $syncLog;

    public function __construct(?StreamingSyncLog $syncLog = null)
    {
        $this->apiKey = config('services.streaming_availability.api_key')
            ?? throw new RuntimeException('STREAMING_AVAILABILITY_API_KEY is not configured');
        $this->baseUrl = rtrim(config('services.streaming_availability.base_url'), '/');
        $this->qps = (int) config('services.streaming_availability.qps', 5);
        $this->syncLog = $syncLog;
    }

    public function get(string $path, array $params = []): array
    {
        $url = $this->baseUrl . $path;

        for ($attempt = 0; $attempt < self::MAX_RETRIES; $attempt++) {
            $this->throttle();

            $response = Http::timeout(30)
                ->withHeaders(['x-api-key' => $this->apiKey])
                ->get($url, $params);

            if ($response->successful()) {
                if ($this->syncLog) {
                    $this->syncLog->increment('api_calls_used');
                }
                return $response->json();
            }

            // Retry on rate-limit and transient upstream errors
            if ($response->status() === 429 || $response->status() >= 500) {
                if ($attempt === self::MAX_RETRIES - 1) {
                    throw new RuntimeException(
                        "Streaming Availability API error {$response->status()} on {$path}: {$response->body()}"
                    );
                }
                sleep((int) pow(2, $attempt)); // 1s, 2s, 4s
                continue;
            }

            // Non-retryable 4xx — fail fast
            throw new RuntimeException(
                "Streaming Availability API error {$response->status()} on {$path}: {$response->body()}"
            );
        }

        throw new RuntimeException("Streaming Availability API request failed after retries");
    }

    private function throttle(): void
    {
        if ($this->qps <= 0) return;
        $minIntervalMicros = (int) (1_000_000 / $this->qps);
        $elapsed = (int) ((microtime(true) - $this->lastRequestAt) * 1_000_000);
        if ($elapsed < $minIntervalMicros) {
            usleep($minIntervalMicros - $elapsed);
        }
        $this->lastRequestAt = microtime(true);
    }
}
