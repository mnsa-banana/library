<?php

namespace App\Services\BookLibrary;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Minimal Google Books volumes client for book:enrich. Mirrors
 * OpenLibraryClient's idioms: all HTTP through Laravel Http (fakeable),
 * constructor-injected politeness delay (0 in tests), exponential backoff on
 * transient failures, plain error split. Quota errors (429 after retries, 403
 * rateLimitExceeded) raise GoogleBooksRateLimitedException — the enrich run's
 * clean-stop signal; any other 4xx fails fast.
 */
class GoogleBooksClient
{
    private const BASE = 'https://www.googleapis.com/books/v1';

    private const MAX_RETRIES = 3;

    /** Cap per-title ISBN queries; the title+author fallback covers the rest. */
    private const MAX_ISBN_QUERIES = 3;

    private int $callsUsed = 0;

    public function __construct(
        private int $delayMs = 250,
        private int $backoffBaseMs = 1000,
    ) {}

    /** HTTP requests made so far — book:enrich charges them against its call ceiling. */
    public function callsUsed(): int
    {
        return $this->callsUsed;
    }

    /**
     * Find the best volume for a book: `isbn:` queries first (an ISBN hit is
     * trusted), then one title+author query whose result is accepted only when
     * its normalized title equals the search title — a fuzzy text match must
     * not enrich a row with the wrong book's metadata.
     *
     * @param  array<string>  $isbn13s  digits-only ISBN-13s
     * @return array{description: ?string, categories: ?array, page_count: ?int,
     *               preview_available: ?bool, google_books_id: ?string, cover_url: ?string,
     *               year: ?int}|null
     */
    public function lookup(array $isbn13s, string $title, ?string $author): ?array
    {
        foreach (array_slice(array_values($isbn13s), 0, self::MAX_ISBN_QUERIES) as $isbn) {
            $volume = $this->firstVolume("isbn:{$isbn}");
            if ($volume !== null) {
                return $this->mapVolume($volume);
            }
        }

        // Strip embedded quotes — they'd terminate the phrase early and
        // malform the query.
        $query = 'intitle:"'.str_replace('"', '', $title).'"';
        if ($author !== null && trim($author) !== '') {
            $query .= ' inauthor:"'.str_replace('"', '', $author).'"';
        }

        $volume = $this->firstVolume($query);
        if ($volume === null) {
            return null;
        }

        $volumeTitle = $volume['volumeInfo']['title'] ?? null;
        $normalized = Normalizer::title($title);
        if (! is_string($volumeTitle) || $normalized === '' || Normalizer::title($volumeTitle) !== $normalized) {
            return null;
        }

        return $this->mapVolume($volume);
    }

    /** @return ?array the first item of a volumes search, null on no results */
    private function firstVolume(string $query): ?array
    {
        $params = ['q' => $query, 'maxResults' => 5];
        if (config('services.google_books.key')) {
            $params['key'] = config('services.google_books.key');
        }

        $items = $this->get(self::BASE.'/volumes', $params)?->json('items') ?? [];

        return $items[0] ?? null;
    }

    /**
     * @return array{description: ?string, categories: ?array, page_count: ?int,
     *               preview_available: ?bool, google_books_id: ?string, cover_url: ?string,
     *               year: ?int}
     */
    private function mapVolume(array $volume): array
    {
        $info = $volume['volumeInfo'] ?? [];

        $description = $info['description'] ?? null;
        $categories = $info['categories'] ?? null;
        $pageCount = $info['pageCount'] ?? null;
        $id = $volume['id'] ?? null;

        // publishedDate comes as "2004", "2004-05", or "2004-05-01" — the
        // leading 4-digit year is the publication year.
        $published = $info['publishedDate'] ?? null;
        $year = is_string($published) && preg_match('/^(\d{4})/', $published, $m)
            ? (int) $m[1]
            : null;

        $cover = $info['imageLinks']['thumbnail'] ?? $info['imageLinks']['smallThumbnail'] ?? null;
        if (is_string($cover)) {
            $cover = preg_replace('/^http:/', 'https:', $cover);
        }

        // preview_available from accessInfo.viewability ONLY — previewLink
        // exists even for NO_PAGES volumes and must never be consulted.
        $previewAvailable = match ($volume['accessInfo']['viewability'] ?? null) {
            'PARTIAL', 'ALL_PAGES' => true,
            'NO_PAGES' => false,
            default => null,
        };

        return [
            'description' => is_string($description) && $description !== '' ? $description : null,
            'categories' => is_array($categories) && $categories !== [] ? $categories : null,
            'page_count' => is_int($pageCount) && $pageCount > 0 ? $pageCount : null,
            'preview_available' => $previewAvailable,
            'google_books_id' => is_string($id) && $id !== '' ? $id : null,
            'cover_url' => is_string($cover) && $cover !== '' ? $cover : null,
            'year' => $year,
        ];
    }

    /**
     * GET with retry on transient failures (429/5xx/connection) and the quota
     * split: 429 exhaustion and 403 rateLimitExceeded raise the rate-limit
     * exception; any other 4xx (including a plain 403) throws RuntimeException.
     */
    private function get(string $url, array $params): ?Response
    {
        for ($attempt = 0; $attempt < self::MAX_RETRIES; $attempt++) {
            if ($this->delayMs > 0) {
                usleep($this->delayMs * 1000);
            }

            $this->callsUsed++;

            try {
                $response = Http::timeout(30)->get($url, $params);
            } catch (ConnectionException $e) {
                if ($attempt === self::MAX_RETRIES - 1) {
                    throw new RuntimeException(
                        "Google Books connection failed on {$url} after retries: {$e->getMessage()}",
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

            if ($response->status() === 403) {
                if ($this->isRateLimit403($response)) {
                    // Daily quota — retrying within the run cannot recover.
                    throw new GoogleBooksRateLimitedException(
                        'Google Books quota exhausted (403 rateLimitExceeded)'
                    );
                }

                throw new RuntimeException(
                    "Google Books request failed (403, non-quota) on {$url}: ".$response->body()
                );
            }

            if ($response->status() === 429 || $response->status() >= 500) {
                if ($attempt === self::MAX_RETRIES - 1) {
                    if ($response->status() === 429) {
                        throw new GoogleBooksRateLimitedException(
                            'Google Books rate limited (429) after retries'
                        );
                    }

                    throw new RuntimeException(
                        "Google Books request failed ({$response->status()}) on {$url}"
                    );
                }
                $this->backoff($attempt);

                continue;
            }

            throw new RuntimeException(
                "Google Books request failed ({$response->status()}) on {$url}"
            );
        }

        throw new RuntimeException("Google Books request failed after retries on {$url}");
    }

    /** GB quota errors are 403s whose error body carries reason=rateLimitExceeded. */
    private function isRateLimit403(Response $response): bool
    {
        foreach ($response->json('error.errors') ?? [] as $error) {
            if (($error['reason'] ?? null) === 'rateLimitExceeded') {
                return true;
            }
        }

        return false;
    }

    private function backoff(int $attempt): void
    {
        if ($this->backoffBaseMs > 0) {
            usleep($this->backoffBaseMs * (2 ** $attempt) * 1000); // 1s, 2s
        }
    }
}
