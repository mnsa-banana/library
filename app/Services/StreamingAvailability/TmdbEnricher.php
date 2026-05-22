<?php

namespace App\Services\StreamingAvailability;

use App\Models\StreamingTitle;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TmdbEnricher
{
    private string $apiKey;
    private int $requestCount = 0;
    private float $windowStart = 0;

    public function __construct()
    {
        $this->apiKey = config('services.tmdb.api_key')
            ?? throw new RuntimeException('TMDB_API_KEY is not configured');
    }

    /**
     * Enrich a single title's us_certification (and trailer_url if missing).
     * Returns true if enriched, false if soft-deleted or skipped.
     */
    public function enrich(StreamingTitle $title): bool
    {
        if (!$title->tmdb_id || !$title->tmdb_type) {
            return false;
        }

        $this->rateLimit();

        try {
            $data = $title->tmdb_type === 'movie'
                ? $this->fetchMovie((int) $title->tmdb_id)
                : $this->fetchTv((int) $title->tmdb_id);
        } catch (RuntimeException $e) {
            if (str_starts_with($e->getMessage(), 'TMDB 404:') && $title->tmdb_id > 0) {
                $title->delete(); // soft delete
                return false;
            }
            throw $e;
        }

        $title->update([
            'us_certification' => $title->tmdb_type === 'movie'
                ? $this->extractMovieCertification($data)
                : $this->extractTvCertification($data),
            'trailer_url' => $title->trailer_url ?? $this->extractTrailerUrl($data),
        ]);

        return true;
    }

    private function fetchMovie(int $tmdbId): array
    {
        return $this->tmdbGet("/movie/{$tmdbId}", ['append_to_response' => 'release_dates,videos']);
    }

    private function fetchTv(int $tmdbId): array
    {
        return $this->tmdbGet("/tv/{$tmdbId}", ['append_to_response' => 'content_ratings,videos']);
    }

    private function extractMovieCertification(array $data): string
    {
        $usEntry = collect($data['release_dates']['results'] ?? [])->firstWhere('iso_3166_1', 'US');
        if (!$usEntry) return 'NR';

        $releases = $usEntry['release_dates'] ?? [];
        $theatrical = collect($releases)->firstWhere('type', 3);
        if ($theatrical && !empty($theatrical['certification'])) return $theatrical['certification'];

        foreach ($releases as $r) {
            if (!empty($r['certification'])) return $r['certification'];
        }
        return 'NR';
    }

    private function extractTvCertification(array $data): string
    {
        $usEntry = collect($data['content_ratings']['results'] ?? [])->firstWhere('iso_3166_1', 'US');
        return $usEntry['rating'] ?? 'NR';
    }

    private function extractTrailerUrl(array $data): ?string
    {
        $videos = $data['videos']['results'] ?? [];
        $trailer = collect($videos)->first(fn ($v) =>
            $v['site'] === 'YouTube' && $v['type'] === 'Trailer' && ($v['official'] ?? false)
        ) ?? collect($videos)->first(fn ($v) =>
            $v['site'] === 'YouTube' && $v['type'] === 'Trailer'
        ) ?? collect($videos)->first(fn ($v) => $v['site'] === 'YouTube');

        return $trailer ? "https://www.youtube.com/watch?v={$trailer['key']}" : null;
    }

    private function tmdbGet(string $path, array $params = []): array
    {
        $params['api_key'] = $this->apiKey;
        $resp = Http::timeout(15)->get("https://api.themoviedb.org/3{$path}", $params);
        if (!$resp->successful()) {
            throw new RuntimeException("TMDB {$resp->status()}: {$resp->body()}");
        }
        return $resp->json();
    }

    private function rateLimit(): void
    {
        $this->requestCount++;
        if ($this->windowStart === 0.0) {
            $this->windowStart = microtime(true);
            return;
        }
        if ($this->requestCount >= 38) {
            $elapsed = microtime(true) - $this->windowStart;
            if ($elapsed < 10.0) {
                usleep((int) ((10.0 - $elapsed) * 1_000_000));
            }
            $this->requestCount = 0;
            $this->windowStart = microtime(true);
        }
    }
}
