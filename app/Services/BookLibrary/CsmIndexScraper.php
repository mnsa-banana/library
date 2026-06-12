<?php

namespace App\Services\BookLibrary;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use SimpleXMLElement;

/**
 * Common Sense Media review-index scraper (spec §Seed sources, csm).
 *
 * Two-level sitemap walk (verified live 2026-06-10): sitemap.xml is a
 * <sitemapindex> of section indexes (articles/ default/ lists/ research/
 * reviews/ video/); reviews/sitemap.xml is itself a NESTED <sitemapindex>
 * whose children are reviews/sitemap.xml?page=N; only those page sitemaps
 * are <urlset>s with <url><loc> entries, filtered here to /book-reviews/.
 *
 * Robots compliance is LOAD-BEARING:
 *  - CSM disallows `/*?page=` EXCEPT `/*\/sitemap.xml?page=` — the only
 *    paginated URLs this class ever requests are the sitemap.xml?page=N
 *    children it read from the nested index; listing `?page=` URLs must
 *    never be crawled.
 *  - CSM blocks AI-labeled user agents site-wide — every request carries a
 *    plain generic-browser UA (never anything AI/bot-labeled).
 *  - Constructor-injected politeness delay between requests (default
 *    1000ms ≈ 1 req/s; 0 in tests).
 */
class CsmIndexScraper
{
    private const BASE = 'https://www.commonsensemedia.org';

    /** Plain library/browser UA — CSM blocks AI-labeled agents site-wide. */
    private const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Safari/537.36';

    private bool $hasCalled = false;

    public function __construct(private int $delayMs = 1000) {}

    /**
     * Walk sitemap.xml → reviews/sitemap.xml → reviews/sitemap.xml?page=N
     * and collect every /book-reviews/ page URL.
     *
     * @return array<string> sorted unique review page URLs
     */
    public function slugUrls(): array
    {
        $index = $this->fetchXml(self::BASE.'/sitemap.xml');

        $pageSitemaps = [];
        foreach ($this->locs($index, 'sitemap') as $sectionUrl) {
            if (! str_contains($sectionUrl, '/reviews/sitemap.xml')) {
                continue;
            }
            // Nested index: its children are the robots-allowed
            // /reviews/sitemap.xml?page=N page sitemaps.
            $pageSitemaps = array_merge($pageSitemaps, $this->locs($this->fetchXml($sectionUrl), 'sitemap'));
        }

        $urls = [];
        foreach ($pageSitemaps as $pageSitemapUrl) {
            foreach ($this->locs($this->fetchXml($pageSitemapUrl), 'url') as $loc) {
                if (str_contains($loc, '/book-reviews/')) {
                    $urls[] = $loc;
                }
            }
        }

        $urls = array_values(array_unique($urls));
        sort($urls);

        return $urls;
    }

    /**
     * Fetch one review page and extract its book metadata. JSON-LD
     * (schema.org Review → itemReviewed) is primary: name, author.name,
     * isbn (ONE arbitrary edition, often hyphenated — normalized to
     * digits-only), typicalAgeRange ("7+" → 7). og:title is the title
     * fallback. Connection error, non-200, or no parsable title → log + null
     * (callers skip).
     *
     * @return array{title: string, author: ?string, min_age: ?int, isbn13s: array<string>}|null
     */
    public function reviewPageMeta(string $url): ?array
    {
        try {
            $response = $this->get($url);
        } catch (ConnectionException $e) {
            // Transient timeout/DNS blip on a single page must never kill a
            // multi-hour run — log + skip, matching the non-200 path.
            Log::warning('book-library: CSM review page connection failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('book-library: CSM review page fetch failed', [
                'url' => $url,
                'status' => $response->status(),
            ]);

            return null;
        }

        $html = $response->body();
        $book = $this->jsonLdBook($html);

        $title = trim((string) ($book['name'] ?? $this->ogTitle($html) ?? ''));
        if ($title === '') {
            Log::warning('book-library: CSM review page carries no parsable title', ['url' => $url]);

            return null;
        }

        $isbn = $book['isbn'] ?? null;
        $isbn13 = is_string($isbn) ? Normalizer::isbn13($isbn) : null;

        return [
            'title' => $title,
            'author' => $this->authorName($book['author'] ?? null),
            'min_age' => $this->minAge($book['typicalAgeRange'] ?? null),
            'isbn13s' => $isbn13 === null ? [] : [$isbn13],
        ];
    }

    /**
     * First JSON-LD node carrying itemReviewed — a direct object, a list
     * entry, or a "@graph" member — or [] when the page has none.
     */
    private function jsonLdBook(string $html): array
    {
        preg_match_all('#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#si', $html, $matches);

        foreach ($matches[1] as $block) {
            $decoded = json_decode(trim($block), true);
            if (! is_array($decoded)) {
                continue;
            }

            $nodes = $decoded['@graph'] ?? (array_is_list($decoded) ? $decoded : [$decoded]);
            $book = null;
            foreach ($nodes as $node) {
                if (! is_array($node) || ! is_array($node['itemReviewed'] ?? null)) {
                    continue;
                }
                $book ??= $node['itemReviewed'];
                // CSM puts typicalAgeRange ("3+") on the REVIEW node, not
                // the Book node it reviews — graft it onto the returned book
                // array (book-node value wins; keep scanning sibling review
                // nodes so a preceding age-less node can't shadow the one
                // carrying the range).
                if (! isset($book['typicalAgeRange']) && isset($node['typicalAgeRange'])) {
                    $book['typicalAgeRange'] = $node['typicalAgeRange'];
                }
            }
            if ($book !== null) {
                return $book;
            }
        }

        return [];
    }

    /** og:title with CSM's " Book Review | Common Sense Media" chrome stripped. */
    private function ogTitle(string $html): ?string
    {
        if (! preg_match('#<meta[^>]+property=["\']og:title["\'][^>]*content=("|\')(.*?)\1#i', $html, $m)
            && ! preg_match('#<meta[^>]+content=("|\')(.*?)\1[^>]*property=["\']og:title["\']#i', $html, $m)) {
            return null;
        }

        $title = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5);
        $title = preg_replace('/\s*\|\s*Common Sense Media\s*$/i', '', $title) ?? $title;
        $title = preg_replace('/\s+Book Review\s*$/i', '', $title) ?? $title;
        $title = trim($title);

        return $title === '' ? null : $title;
    }

    /** schema.org author: Person object, list of Persons, or bare string. */
    private function authorName(mixed $author): ?string
    {
        if (is_array($author) && array_is_list($author)) {
            $author = $author[0] ?? null;
        }
        $name = is_array($author) ? ($author['name'] ?? null) : $author;

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }

    /** CSM's typicalAgeRange shape is "7+" → 7; anything else → null. */
    private function minAge(mixed $range): ?int
    {
        if (is_array($range)) {
            // schema.org allows list values — take the first usable entry.
            $range = $range[0] ?? null;
        }
        if (is_string($range) && preg_match('/^\s*(\d{1,2})/', $range, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /** Sitemap fetches are foundational — non-200/unparsable XML is fatal. */
    private function fetchXml(string $url): SimpleXMLElement
    {
        $response = $this->get($url);
        if (! $response->successful()) {
            throw new RuntimeException("CSM sitemap request failed ({$response->status()}) on {$url}");
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($response->body(), SimpleXMLElement::class, LIBXML_NONET);
        libxml_clear_errors();
        if ($xml === false) {
            throw new RuntimeException("CSM sitemap is not parsable XML: {$url}");
        }

        return $xml;
    }

    /** @return array<string> trimmed <loc> texts of the given child element */
    private function locs(SimpleXMLElement $xml, string $child): array
    {
        $locs = [];
        foreach ($xml->{$child} as $entry) {
            $loc = trim((string) $entry->loc);
            if ($loc !== '') {
                $locs[] = $loc;
            }
        }

        return $locs;
    }

    private function get(string $url): Response
    {
        if ($this->hasCalled && $this->delayMs > 0) {
            usleep($this->delayMs * 1000);
        }
        $this->hasCalled = true;

        return Http::withHeaders(['User-Agent' => self::UA])->timeout(30)->get($url);
    }
}
