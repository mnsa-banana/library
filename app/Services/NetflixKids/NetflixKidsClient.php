<?php

namespace App\Services\NetflixKids;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class NetflixKidsClient
{
    private const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
    private const GRAPHQL_URL = 'https://web.prod.cloud.netflix.com/graphql';

    private string $cookie;
    private int $retryTimes;
    private int $retrySleepMs;
    private ?array $searchTemplate = null;

    public function __construct()
    {
        $this->cookie = (string) config('services.netflix_kids.cookie');
        if ($this->cookie === '') {
            throw new RuntimeException('NETFLIX_KIDS_COOKIE is not configured');
        }
        $this->retryTimes = max(1, (int) config('services.netflix_kids.retry_times', 4));
        $this->retrySleepMs = (int) config('services.netflix_kids.retry_sleep_ms', 1000);
    }

    private function unescape(string $s): string
    {
        return str_replace(['\\x2F', '\\x2B', '\\x3D'], ['/', '+', '='], $s);
    }

    /**
     * Send an HTTP request with status-aware retry. Laravel's ->retry() only
     * re-attempts on connection exceptions; Netflix throttling returns 429/5xx
     * as a normal response, so we must check ->successful() ourselves and retry
     * those too. Throws after exhausting attempts so callers fail loud rather
     * than silently treating an error body as "no match".
     */
    private function sendWithRetry(callable $send, bool $expectJson = true): Response
    {
        $last = 'unknown error';
        for ($attempt = 1; $attempt <= $this->retryTimes; $attempt++) {
            try {
                $resp = $send();
                if ($resp->successful()) {
                    // For the JSON APIs, guard against a 2xx that isn't our JSON (e.g. a WAF/HTML
                    // interstitial) which would otherwise be silently read as "no data"/"no match".
                    // The /Kids page is HTML, so callers expecting HTML pass $expectJson = false.
                    if (! $expectJson) {
                        return $resp;
                    }
                    $head = ltrim($resp->body(), " \t\n\r\0\x0B\xEF\xBB\xBF"); // also strip a leading UTF-8 BOM
                    if ($head !== '' && ($head[0] === '{' || $head[0] === '[')) {
                        return $resp;
                    }
                    $last = 'non-JSON 2xx body';
                } else {
                    $last = 'HTTP ' . $resp->status();
                }
            } catch (ConnectionException $e) {
                $last = $e->getMessage();
            }
            if ($attempt < $this->retryTimes && $this->retrySleepMs > 0) {
                usleep($this->retrySleepMs * 1000);
            }
        }
        throw new RuntimeException("Netflix request failed after {$this->retryTimes} attempts: {$last}");
    }

    /** @param int[] $netflixIds @return array<int,int|null> id => maturityLevel */
    public function maturityLevels(array $netflixIds, string $shaktiUrl, string $authUrl, ?callable $onBatch = null): array
    {
        $out = [];
        foreach (array_chunk($netflixIds, 48) as $chunk) {
            $form = ['authURL' => $authUrl];
            $paths = [];
            foreach ($chunk as $id) {
                $paths[] = json_encode(['videos', (int) $id, ['maturity']]);
            }
            $resp = $this->sendWithRetry(fn () => Http::asForm()
                ->withHeaders(['User-Agent' => self::UA, 'Cookie' => $this->cookie])
                ->timeout(30)
                ->withBody(
                    http_build_query($form) . '&' . implode('&', array_map(
                        fn ($p) => 'path=' . urlencode($p), $paths
                    )),
                    'application/x-www-form-urlencoded'
                )
                ->post(rtrim($shaktiUrl, '/') . '/pathEvaluator?method=call'));

            $videos = $resp->json('value.videos', []);
            foreach ($chunk as $id) {
                $out[$id] = $videos[(string) $id]['maturity']['rating']['maturityLevel'] ?? null;
            }
            if ($onBatch) {
                $onBatch(count($out), count($netflixIds));
            }
        }
        return $out;
    }

    private function searchTemplate(): array
    {
        if ($this->searchTemplate === null) {
            $path = resource_path('netflix/search_query_template.json');
            $this->searchTemplate = json_decode((string) file_get_contents($path), true);
            $this->searchTemplate['extensions'] = ['persistedQuery' => [
                'id' => (string) config('services.netflix_kids.persisted_query_id'),
                'version' => (int) config('services.netflix_kids.persisted_query_version'),
            ]];
        }
        return $this->searchTemplate;
    }

    public function searchHasId(string $title, int $netflixId, string $appVersion): bool
    {
        $body = $this->searchTemplate();
        $body['variables']['searchTerm'] = $title;
        $body['variables']['endCursor'] = null;

        $resp = $this->sendWithRetry(fn () => Http::withHeaders([
            'User-Agent' => self::UA,
            'Cookie' => $this->cookie,
            'Content-Type' => 'application/json',
            'x-netflix.context.operation-name' => 'SearchPageQueryResults',
            'x-netflix.context.app-version' => $appVersion,
            'x-netflix.context.ui-flavor' => 'akira',
            'x-netflix.context.locales' => 'en-us',
            'Origin' => 'https://www.netflix.com',
            'Referer' => 'https://www.netflix.com/Kids/search',
        ])->timeout(30)
          ->withBody(json_encode($body), 'application/json')
          ->post(self::GRAPHQL_URL));

        return (bool) preg_match('/"videoId":' . $netflixId . '\b/', $resp->body());
    }

    /** Fetch /Kids and scrape session facts. */
    public function probeSession(): array
    {
        $body = $this->sendWithRetry(fn () => Http::withHeaders(['User-Agent' => self::UA, 'Cookie' => $this->cookie])
            ->timeout(30)
            ->get('https://www.netflix.com/Kids'), expectJson: false)
            ->body();

        $grab = function (string $re) use ($body): ?string {
            return preg_match($re, $body, $m) ? $m[1] : null;
        };

        $country = $grab('/"currentCountry":"([A-Z]{2})"/') ?? $grab('/"countryOfSignup":"([A-Z]{2})"/');
        $auth = $grab('/"authURL":"([^"]+)"/');
        $api = $grab('/"apiUrl":"([^"]+shakti[^"]*)"/');
        $build = $grab('/"BUILD_IDENTIFIER":"([^"]+)"/');

        return [
            'country' => $country,
            'is_kids' => str_contains($body, 'container-kids') || str_contains($body, '"isKidsProfile":true'),
            'auth_url' => $auth ? $this->unescape($auth) : null,
            'shakti_url' => $api ? $this->unescape($api) : null,
            'app_version' => $build,
        ];
    }
}
