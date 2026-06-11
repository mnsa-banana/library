<?php

namespace App\Services\BookLibrary;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Minimal NYT Books API client (spec §Seed sources, nyt-history / book:weekly).
 *
 * All HTTP goes through Laravel Http so tests can fake it. NYT enforces
 * 5 req/min + ~500/day, so the constructor-injected delay (default 12s,
 * 0 in tests) sleeps BETWEEN calls; a 429 raises NytRateLimitedException
 * so callers can stop their run cleanly instead of burning the quota.
 * Transient failures (5xx/connection) are retried with exponential backoff,
 * mirroring OpenLibraryClient — tests pass backoffBaseMs: 0 so retries
 * never sleep. A 429 is deliberately NOT retried: it means the day's quota
 * is gone, and the budget contract is to stop on it.
 */
class NytClient
{
    private const BASE = 'https://api.nytimes.com/svc/books/v3';

    private const MAX_RETRIES = 3;

    /** Current children's list slugs synced by book:weekly. */
    public const CURRENT_LISTS = [
        'picture-books',
        'childrens-middle-grade-hardcover',
        'young-adult-hardcover',
        'series-books',
    ];

    /**
     * Backfill slugs: the current four plus their pre-2015 predecessors
     * (the hardcover slugs only exist from the Aug-2015 split).
     */
    public const HISTORY_LISTS = [
        ...self::CURRENT_LISTS,
        'chapter-books',
        'childrens-middle-grade',
        'young-adult',
    ];

    private bool $hasCalled = false;

    public function __construct(
        private int $delayMs = 12_000,
        private int $backoffBaseMs = 1000,
    ) {}

    /**
     * Per-list date bounds from /lists/names.json — each list's history depth
     * differs, so never assume a fixed start year.
     *
     * @return array<string, array{oldest_published_date: string, newest_published_date: string}>
     */
    public function listNames(): array
    {
        $names = [];
        foreach ($this->get(self::BASE.'/lists/names.json')->json('results') ?? [] as $entry) {
            $names[$entry['list_name_encoded']] = [
                'oldest_published_date' => $entry['oldest_published_date'],
                'newest_published_date' => $entry['newest_published_date'],
            ];
        }

        return $names;
    }

    /**
     * One list page: /lists/{date}/{list}.json ('current' allowed as $date).
     * Returns the `results` object (published_date, previous_published_date,
     * books[]).
     */
    public function listForDate(string $list, string $date): array
    {
        return $this->get(self::BASE."/lists/{$date}/{$list}.json")->json('results') ?? [];
    }

    /**
     * Map an NYT book payload to an IngestService item (spec §NYT mapping):
     * Str::title the ALL-CAPS title, union primary_isbn13 + isbns[].isbn13
     * (normalized, unique), as_of_date = the page's published_date, min_age
     * from the list's age band with min_age_source='nyt'.
     */
    public static function ingestItem(array $book, string $list, ?string $asOfDate): array
    {
        $isbn13s = [];
        $entries = array_merge(
            [['isbn13' => $book['primary_isbn13'] ?? null]],
            is_array($book['isbns'] ?? null) ? $book['isbns'] : [],
        );
        foreach ($entries as $entry) {
            $raw = $entry['isbn13'] ?? null;
            $normalized = is_string($raw) ? Normalizer::isbn13($raw) : null;
            if ($normalized !== null) {
                $isbn13s[] = $normalized;
            }
        }

        $minAge = self::minAgeForList($list);

        return [
            'title' => Str::title(trim((string) ($book['title'] ?? ''))),
            'author' => self::presence($book['author'] ?? null),
            'isbn13s' => array_values(array_unique($isbn13s)),
            'cover_url' => self::presence($book['book_image'] ?? null),
            'description' => self::presence($book['description'] ?? null),
            'min_age' => $minAge,
            'min_age_source' => $minAge === null ? null : 'nyt',
            'list_source' => 'nyt',
            'list_key' => $list,
            'rank' => $book['rank'] ?? null,
            'weeks_on_list' => $book['weeks_on_list'] ?? null,
            'as_of_date' => $asOfDate,
        ];
    }

    /** Age band per list slug; series-books spans bands → no signal. */
    public static function minAgeForList(string $list): ?int
    {
        return match (true) {
            $list === 'picture-books', $list === 'chapter-books' => 4,
            str_starts_with($list, 'childrens-middle-grade') => 8,
            str_starts_with($list, 'young-adult') => 12,
            default => null,
        };
    }

    private static function presence(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function get(string $url): Response
    {
        for ($attempt = 0; $attempt < self::MAX_RETRIES; $attempt++) {
            if ($this->hasCalled && $this->delayMs > 0) {
                usleep($this->delayMs * 1000);
            }
            $this->hasCalled = true;

            try {
                $response = Http::timeout(30)->get($url, [
                    'api-key' => (string) config('services.nyt.books_key'),
                ]);
            } catch (ConnectionException $e) {
                // Transport-level failure (DNS, connect, or read timeout):
                // treat as transient and retry rather than aborting the run.
                if ($attempt === self::MAX_RETRIES - 1) {
                    throw new RuntimeException(
                        "NYT connection failed on {$url} after retries: {$e->getMessage()}",
                        previous: $e,
                    );
                }
                $this->backoff($attempt);

                continue;
            }

            // 429 means the day's quota is gone — never retried (the budget
            // contract: callers stop their run cleanly on this exception).
            if ($response->status() === 429) {
                throw new NytRateLimitedException("NYT rate limited (429) on {$url}");
            }
            if ($response->successful()) {
                return $response;
            }

            // Retry transient upstream errors; other 4xx fail fast below.
            if ($response->status() >= 500) {
                if ($attempt === self::MAX_RETRIES - 1) {
                    throw new RuntimeException(
                        "NYT request failed ({$response->status()}) on {$url}"
                    );
                }
                $this->backoff($attempt);

                continue;
            }

            throw new RuntimeException("NYT request failed ({$response->status()}) on {$url}");
        }

        throw new RuntimeException("NYT request failed after retries on {$url}");
    }

    private function backoff(int $attempt): void
    {
        if ($this->backoffBaseMs > 0) {
            usleep($this->backoffBaseMs * (2 ** $attempt) * 1000); // 1s, 2s
        }
    }
}
