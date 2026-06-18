<?php

namespace App\Services\BookLibrary;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Minimal Open Library API client for work resolution (WorkResolver step 2)
 * and edition enrichment (book:enrich). All HTTP goes through Laravel Http so
 * tests can fake it; an optional per-request delay keeps seed runs polite.
 * Transient failures (429/5xx/connection) are retried with exponential
 * backoff, mirroring StreamingAvailability\Client — at ~15k requests per
 * seed run a single blip must not kill the run. Tests pass backoffBaseMs: 0
 * so retries never sleep.
 */
class OpenLibraryClient
{
    private const BASE = 'https://openlibrary.org';

    private const COVER_URL = 'https://covers.openlibrary.org/b/id/%d-L.jpg';

    private const MAX_RETRIES = 3;

    public function __construct(
        private int $delayMs = 0,
        private int $backoffBaseMs = 1000,
    ) {}

    /**
     * Resolve an ISBN-13 to its Open Library work. Returns null when the
     * edition is unknown (404) or carries no work.
     *
     * @return array{work_key: string, isbn13s: array<string>, cover_url: ?string}|null
     */
    public function resolveIsbn(string $isbn13): ?array
    {
        $response = $this->get(self::BASE."/isbn/{$isbn13}.json");
        if ($response === null) {
            return null;
        }

        $edition = $response->json() ?? [];
        $workKey = $this->workKeyFrom($edition);
        if ($workKey === null) {
            return null;
        }

        return [
            'work_key' => $workKey,
            'isbn13s' => $this->normalizedIsbns([$isbn13], $edition),
            'cover_url' => $this->coverUrlFrom($edition),
        ];
    }

    /**
     * Collect edition ISBNs and a cover for a work, following Open Library's
     * paginated editions feed up to $maxPages pages.
     *
     * @return array{isbn13s: array<string>, cover_url: ?string}
     */
    public function workEditions(string $workKey, int $maxPages = 2): array
    {
        $workKey = basename($workKey); // accept 'OL45804W' or '/works/OL45804W'
        $url = self::BASE."/works/{$workKey}/editions.json";

        $isbn13s = [];
        $coverUrl = null;

        for ($page = 0; $page < $maxPages && $url !== null; $page++) {
            $body = $this->get($url)?->json() ?? [];

            foreach ($body['entries'] ?? [] as $edition) {
                $isbn13s = $this->normalizedIsbns($isbn13s, $edition);
                $coverUrl ??= $this->coverUrlFrom($edition);
            }

            $next = $body['links']['next'] ?? null;
            $url = is_string($next)
                ? (str_starts_with($next, '/') ? self::BASE.$next : $next)
                : null;
        }

        return ['isbn13s' => $isbn13s, 'cover_url' => $coverUrl];
    }

    /**
     * GET a JSON endpoint; null on 404, retry transient failures
     * (429/5xx/connection) with exponential backoff, throw on the rest.
     * 429 exhaustion raises OpenLibraryRateLimitedException; everything
     * else throws plain RuntimeException.
     */
    private function get(string $url): ?Response
    {
        for ($attempt = 0; $attempt < self::MAX_RETRIES; $attempt++) {
            if ($this->delayMs > 0) {
                usleep($this->delayMs * 1000);
            }

            try {
                $response = Http::timeout(30)->get($url);
            } catch (ConnectionException $e) {
                // Transport-level failure (DNS, connect, or read timeout):
                // treat as transient and retry rather than aborting the run.
                // Typed so commands clean-stop on a sustained outage (like a
                // 429) instead of failing the run loudly.
                if ($attempt === self::MAX_RETRIES - 1) {
                    throw new OpenLibraryConnectionException(
                        "Open Library connection failed on {$url} after retries: {$e->getMessage()}",
                        previous: $e,
                    );
                }
                $this->backoff($attempt);

                continue;
            }

            if ($response->status() === 404) {
                return null;
            }
            if ($response->successful()) {
                return $response;
            }

            // Retry on rate-limit and transient upstream errors
            if ($response->status() === 429 || $response->status() >= 500) {
                if ($attempt === self::MAX_RETRIES - 1) {
                    if ($response->status() === 429) {
                        throw new OpenLibraryRateLimitedException(
                            "Open Library rate limited (429) after retries on {$url}"
                        );
                    }

                    throw new RuntimeException(
                        "Open Library request failed ({$response->status()}) on {$url}"
                    );
                }
                $this->backoff($attempt);

                continue;
            }

            // Non-retryable 4xx — fail fast
            throw new RuntimeException(
                "Open Library request failed ({$response->status()}) on {$url}"
            );
        }

        throw new RuntimeException("Open Library request failed after retries on {$url}");
    }

    private function backoff(int $attempt): void
    {
        if ($this->backoffBaseMs > 0) {
            usleep($this->backoffBaseMs * (2 ** $attempt) * 1000); // 1s, 2s
        }
    }

    private function workKeyFrom(array $edition): ?string
    {
        $key = $edition['works'][0]['key'] ?? null;

        return is_string($key) && $key !== '' ? basename($key) : null;
    }

    /** Union $base with the edition's isbn_13/isbn_10 values, normalized. */
    private function normalizedIsbns(array $base, array $edition): array
    {
        $raw = array_merge($edition['isbn_13'] ?? [], $edition['isbn_10'] ?? []);
        $normalized = array_filter(array_map(
            fn ($isbn) => is_string($isbn) ? Normalizer::isbn13($isbn) : null,
            $raw
        ));

        return array_values(array_unique(array_merge($base, $normalized)));
    }

    private function coverUrlFrom(array $edition): ?string
    {
        foreach ($edition['covers'] ?? [] as $coverId) {
            if (is_int($coverId) && $coverId > 0) {
                return sprintf(self::COVER_URL, $coverId);
            }
        }

        return null;
    }
}
