<?php

namespace App\Services\NetflixKids;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class NetflixKidsClient
{
    private const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
    private const GRAPHQL_URL = 'https://web.prod.cloud.netflix.com/graphql';

    private string $cookie;

    public function __construct()
    {
        $this->cookie = (string) config('services.netflix_kids.cookie');
        if ($this->cookie === '') {
            throw new RuntimeException('NETFLIX_KIDS_COOKIE is not configured');
        }
    }

    private function unescape(string $s): string
    {
        return str_replace(['\\x2F', '\\x2B', '\\x3D'], ['/', '+', '='], $s);
    }

    /** Fetch /Kids and scrape session facts. */
    public function probeSession(): array
    {
        $body = Http::withHeaders(['User-Agent' => self::UA, 'Cookie' => $this->cookie])
            ->get('https://www.netflix.com/Kids')
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
