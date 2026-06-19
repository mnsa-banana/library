<?php

namespace App\Services\NetflixKids;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class NetflixKidsClient
{
    private const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 '
        .'(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    private const GRAPHQL_URL = 'https://web.prod.cloud.netflix.com/graphql';

    /**
     * The falcor pathEvaluator lives under the nodequark member API (scraped from the /Kids
     * page's "memberapi" config; the legacy /api/shakti/* alias was retired 2026-06-11) and
     * rejects requests with HTTP 412 unless this original_path query param is present.
     */
    private const PATH_EVALUATOR_ORIGINAL_PATH = '/shakti/mre/pathEvaluator';

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
                    $last = 'HTTP '.$resp->status();
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
    public function maturityLevels(array $netflixIds, string $memberApiUrl, string $authUrl, ?callable $onBatch = null): array
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
                    http_build_query($form).'&'.implode('&', array_map(
                        fn ($p) => 'path='.urlencode($p), $paths
                    )),
                    'application/x-www-form-urlencoded'
                )
                ->post(rtrim($memberApiUrl, '/').'/pathEvaluator?original_path='
                    .rawurlencode(self::PATH_EVALUATOR_ORIGINAL_PATH)));

            // falcor jsonGraph response: maturity is an atom whose payload sits under 'value'
            $videos = $resp->json('jsonGraph.videos', []);
            foreach ($chunk as $id) {
                $out[$id] = $videos[(string) $id]['maturity']['value']['rating']['maturityLevel'] ?? null;
            }
            if ($onBatch) {
                $onBatch(count($out), count($netflixIds));
            }
        }

        return $out;
    }

    /**
     * Walk a genre's standard ("su") list via the member-API pathEvaluator,
     * 50 ids per page, until a page returns no video refs.
     *
     * @return int[] distinct video ids
     */
    public function browseGenreVideoIds(int $genreId, string $memberApiUrl, string $authUrl): array
    {
        $ids = [];
        // 10000 is a safety cap, not the expected terminator: the short-page break
        // below normally ends the walk. This just bounds a pathological response
        // that keeps returning full 50-id pages without ever shrinking.
        for ($from = 0; $from < 10000; $from += 50) {
            $path = json_encode(['genres', $genreId, 'su', ['from' => $from, 'to' => $from + 49], 'reference', ['title']]);
            $resp = $this->memberFalcor([$path], $memberApiUrl, $authUrl);
            preg_match_all('/\["videos","(\d+)"\]/', $resp->body(), $m);
            if (! $m[1]) {
                break;
            }
            foreach ($m[1] as $id) {
                $ids[(int) $id] = true;
            }
            if (count($m[1]) < 50) {
                break;
            }
        }

        return array_keys($ids);
    }

    /**
     * Batch-resolve video ids to titles via the member-API pathEvaluator.
     *
     * @param  int[]  $videoIds
     * @return array<int,string> videoId => title
     */
    public function resolveVideoTitles(array $videoIds, string $memberApiUrl, string $authUrl): array
    {
        $out = [];
        foreach (array_chunk($videoIds, 48) as $chunk) {
            $paths = array_map(fn ($id) => json_encode(['videos', (int) $id, 'title']), $chunk);
            $resp = $this->memberFalcor($paths, $memberApiUrl, $authUrl);
            $videos = $resp->json('jsonGraph.videos', []);
            foreach ($chunk as $id) {
                $title = $videos[(string) $id]['title']['value'] ?? null;
                if (is_string($title) && $title !== '') {
                    $out[(int) $id] = $title;
                }
            }
        }

        return $out;
    }

    /** Form-encoded falcor POST to the member-API pathEvaluator (shared by the genre-browse primitives). */
    private function memberFalcor(array $encodedPaths, string $memberApiUrl, string $authUrl): Response
    {
        return $this->sendWithRetry(fn () => Http::asForm()
            ->withHeaders(['User-Agent' => self::UA, 'Cookie' => $this->cookie])
            ->timeout(30)
            ->withBody(
                http_build_query(['authURL' => $authUrl]).'&'
                .implode('&', array_map(fn ($p) => 'path='.urlencode($p), $encodedPaths)),
                'application/x-www-form-urlencoded'
            )
            ->post(rtrim($memberApiUrl, '/').'/pathEvaluator?original_path='.rawurlencode(self::PATH_EVALUATOR_ORIGINAL_PATH)));
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

    /**
     * Run a Kids-catalog search and parse the result entities.
     *
     * @return list<array{videoId:int, title:string, type:string, maturity:?int}>
     */
    public function searchResults(string $term, string $appVersion): array
    {
        $body = $this->searchTemplate();
        $body['variables']['searchTerm'] = $term;
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

        // Each result entity: "displayString":"<title>","unifiedEntity":{"__typename":
        // "Movie|Show","unifiedEntityId":"Video:<id>","contentAdvisory":{…"maturityLevel":<n>},"videoId":<id>}
        preg_match_all(
            '/"displayString":"([^"]+)","unifiedEntity":\{"__typename":"(Movie|Show)",'
            .'"unifiedEntityId":"Video:(\d+)","contentAdvisory":\{[^{}]*"maturityLevel":(\d+)\}/',
            $resp->body(), $m, PREG_SET_ORDER);

        $out = [];
        foreach ($m as $row) {
            $out[] = [
                'videoId' => (int) $row[3],
                'title' => $row[1],
                'type' => $row[2] === 'Movie' ? 'movie' : 'series',
                'maturity' => (int) $row[4],
            ];
        }

        return $out;
    }

    /**
     * Resolve a title to its Netflix Kids videoId, or null if it doesn't surface.
     * Exact normalized-title match first (preferring a type match), then a
     * subtitle-tolerant containment fallback. Conservative: null on no confident match.
     */
    public function resolveKidsVideoId(string $title, string $type, string $appVersion): ?int
    {
        $norm = fn (string $s): string => preg_replace('/[^a-z0-9]+/', '', strtolower($s));
        $want = $norm($title);
        if ($want === '') {
            return null;
        }

        $results = $this->searchResults($title, $appVersion);

        // Pass 1: exact normalized title, same type only.
        // Conservative: if the exact-title match is a different type, do NOT return it — fall through.
        foreach ($results as $r) {
            if ($norm($r['title']) === $want && $r['type'] === $type) {
                return $r['videoId'];
            }
        }

        // Pass 2: containment (handles subtitle variants like "Paw Patrol: The Movie" → "paw patrol"),
        // same type only. Guard with a length-ratio check so short words (e.g. "prince") don't
        // spuriously match as substrings of longer unrelated titles ("theswanprincess").
        // Additional guard: reject numeric-sequel matches where one string is a prefix of the other
        // and the remaining suffix starts with a digit (e.g. "frozen" ⊂ "frozen2" → reject).
        foreach ($results as $r) {
            $rn = $norm($r['title']);
            if ($r['type'] !== $type || $rn === '') {
                continue;
            }
            $shorter = min(strlen($want), strlen($rn));
            $longer = max(strlen($want), strlen($rn));
            if ($shorter / $longer < 0.5) {
                continue; // too different in length — likely a coincidental substring, not a subtitle variant
            }
            if (str_contains($rn, $want) || str_contains($want, $rn)) {
                // Reject numeric-sequel collisions: "frozen"→"frozen2", "frozen2"→"frozen20", etc.
                if (str_starts_with($rn, $want) && preg_match('/^\d/', substr($rn, strlen($want)))) {
                    continue;
                }
                if (str_starts_with($want, $rn) && preg_match('/^\d/', substr($want, strlen($rn)))) {
                    continue;
                }

                return $r['videoId'];
            }
        }

        return null;
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

        return (bool) preg_match('/"videoId":'.$netflixId.'\b/', $resp->body());
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
        $build = $grab('/"BUILD_IDENTIFIER":"([^"]+)"/');

        $memberApi = null;
        if (preg_match('/"memberapi":\{([^{}]*)\}/', $body, $m)
            && preg_match('/"hostname":"([^"]+)"/', $m[1], $host)
            && preg_match('/"path":\["([^"]+)"/', $m[1], $path)) {
            $memberApi = 'https://'.$host[1].$this->unescape($path[1]);
        }

        return [
            'country' => $country,
            'is_kids' => str_contains($body, 'container-kids') || str_contains($body, '"isKidsProfile":true'),
            'auth_url' => $auth ? $this->unescape($auth) : null,
            'member_api_url' => $memberApi,
            'app_version' => $build,
        ];
    }
}
