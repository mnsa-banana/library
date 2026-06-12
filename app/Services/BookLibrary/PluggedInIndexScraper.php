<?php

namespace App\Services\BookLibrary;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use SimpleXMLElement;

/**
 * Plugged In (Focus on the Family) review-index scraper (spec §Seed sources,
 * pluggedin). Symmetrical with CsmIndexScraper: sitemap-driven URL discovery
 * + per-review-page metadata fetch — Plugged In's listing pages carry NO
 * authors, so the per-page fetch is the rule.
 *
 * Sitemap walk (verified live 2026-06-11): sitemap_index.xml is a Yoast
 * per-post-type <sitemapindex> (post-, page-, movie-reviews-, tv-reviews-,
 * book-reviews-, …). Book review URLs live exclusively in the
 * book-reviews-sitemap*.xml children, so only those are fetched — the
 * URL-level /book-reviews/ filter on every fetched child remains the source
 * of truth. robots.txt allows the sitemaps and /book-reviews/ pages.
 *
 * Unlike CSM, review pages carry no JSON-LD book schema and no
 * machine-readable age — metadata is title + author only, parsed from the
 * Elementor post header (h1 + post-info byline) with an og:title fallback.
 *
 * Politeness: constructor-injected delay between requests (default 1000ms
 * ≈ 1 req/s; 0 in tests); plain generic-browser UA, mirroring CSM.
 */
class PluggedInIndexScraper
{
    /** www host directly — the apex domain 301s every request to www. */
    private const BASE = 'https://www.pluggedin.com';

    /**
     * Literal byline placeholders that mark roundup/listicle posts — pages
     * in the book-reviews sitemap that review no single book fill the
     * post-info author slot with these instead of an author name.
     */
    private const PLACEHOLDER_BYLINES = ['none', 'unknown', 'n/a'];

    /** Plain library/browser UA — never anything AI/bot-labeled. */
    private const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Safari/537.36';

    private bool $hasCalled = false;

    public function __construct(private int $delayMs = 1000) {}

    /**
     * Walk sitemap_index.xml → book-reviews-sitemap*.xml and collect every
     * slugged /book-reviews/ page URL. The slug requirement excludes the
     * /book-reviews/ archive root, which the sitemap lists but which is a
     * listing page, not a review (it would otherwise ingest a junk title).
     *
     * @return array<string> sorted unique review page URLs
     */
    public function reviewUrls(): array
    {
        $index = $this->fetchXml(self::BASE.'/sitemap_index.xml');

        $urls = [];
        foreach ($this->locs($index, 'sitemap') as $childUrl) {
            // Child-name filter (documented choice): of Yoast's per-post-type
            // children, only book-reviews-sitemap*.xml can contain
            // /book-reviews/ URLs — verified live; the URL filter below still
            // decides what is kept.
            if (! str_contains($childUrl, 'book-reviews-sitemap')) {
                continue;
            }
            foreach ($this->locs($this->fetchXml($childUrl), 'url') as $loc) {
                if (preg_match('~/book-reviews/[^/?#]+~', $loc)) {
                    $urls[] = $loc;
                }
            }
        }

        $urls = array_values(array_unique($urls));
        sort($urls);

        return $urls;
    }

    /**
     * Fetch one review page and extract its book metadata — title + author
     * only (no JSON-LD book schema, no ISBN, no machine-readable age on
     * Plugged In pages). Title: the Elementor post-title h1, og:title
     * fallback. Author: the post-info byline item right after the
     * "Book Review" label. Connection error, non-200, or no parsable title
     * → log + null (callers skip).
     *
     * @return array{title: string, author: ?string}|null
     */
    public function reviewPageMeta(string $url): ?array
    {
        try {
            $response = $this->get($url);
        } catch (ConnectionException $e) {
            // Transient timeout/DNS blip on a single page must never kill a
            // multi-hour run — log + skip, matching the non-200 path.
            Log::warning('book-library: Plugged In review page connection failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('book-library: Plugged In review page fetch failed', [
                'url' => $url,
                'status' => $response->status(),
            ]);

            return null;
        }

        $html = $response->body();

        $title = $this->h1Title($html) ?? $this->ogTitle($html);
        if ($title === null) {
            Log::warning('book-library: Plugged In review page carries no parsable title', ['url' => $url]);

            return null;
        }

        $candidate = $this->bylineAuthorCandidate($html);
        if ($candidate !== null && in_array(strtolower($candidate), self::PLACEHOLDER_BYLINES, true)) {
            // Roundup/listicle posts ("10 Family-Friendly Picture Books from
            // 2008") live in the book-reviews sitemap but review no single
            // book — their post-info byline carries literal placeholders
            // ("None"/"Unknown") where a real review carries the book's
            // author. The h1 on such a page is an article headline, not a
            // book title, so skip the page entirely.
            Log::info('book-library: Plugged In page has a placeholder byline (roundup, not a review) — skipped', ['url' => $url]);

            return null;
        }

        $author = $candidate === null || $candidate === '' || preg_match('/^\d/', $candidate)
            ? null
            : $candidate;

        return [
            'title' => $title,
            'author' => $author,
            'min_age' => $this->bylineMinAge($html),
        ];
    }

    /**
     * Minimum age from the byline's age-band item ("8 to 12", "14 to 18",
     * "12 years old and up"). Scans every type-custom item after the
     * "Book Review" label for the first age-band-shaped text, so it works
     * whether or not the author item is present. Returns the band's lower
     * bound, or null when no item matches.
     */
    private function bylineMinAge(string $html): ?int
    {
        $items = $this->postInfoItems($html);

        foreach ($items as $i => $text) {
            if (strcasecmp($text, 'Book Review') !== 0) {
                continue;
            }
            // Bounded to the byline run right after the label (author, age
            // band, publisher, awards, year) — a page-wide scan could graft
            // an age band from a related-reviews card lower on the page.
            foreach (array_slice($items, $i + 1, 5) as $candidate) {
                if (preg_match('/^(\d{1,2})\s*(?:to\s*\d{1,2}|years?\s*old\s*and\s*up|and\s*up|\+)$/i', $candidate, $m)) {
                    return (int) $m[1];
                }
            }

            return null;
        }

        return null;
    }

    /** First h1 — the Elementor theme-post-title widget (one h1 per page). */
    private function h1Title(string $html): ?string
    {
        if (! preg_match('#<h1[^>]*>(.*?)</h1>#si', $html, $m)) {
            return null;
        }

        $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5));

        return $title === '' ? null : $title;
    }

    /** og:title — carries the bare post title on review pages (no site chrome). */
    private function ogTitle(string $html): ?string
    {
        if (! preg_match('#<meta[^>]+property=["\']og:title["\'][^>]*content=("|\')(.*?)\1#i', $html, $m)
            && ! preg_match('#<meta[^>]+content=("|\')(.*?)\1[^>]*property=["\']og:title["\']#i', $html, $m)) {
            return null;
        }

        $title = trim(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5));

        return $title === '' ? null : $title;
    }

    /**
     * Author from the Elementor post-info byline. Verified live 2026-06-11
     * across old and new reviews, the type-custom items run in a fixed
     * order: "Book Review" label, author, age band, publisher, awards, year
     * — so the author is the item immediately after the label. Pages
     * without the label (or with nothing after it) yield null; positional
     * guessing without the label would risk ingesting publisher/age text.
     *
     * Returns the RAW candidate ('' when the label has no following item,
     * null when the label is absent). The caller applies the guards: the
     * placeholder check (roundup pages — skip entirely) and the digit guard
     * (when the author item is missing the next item is the age band
     * "8 to 12" or a year — no real author starts with a digit; a wrong
     * author would survive normalization and poison work resolution, while
     * a null author back-fills via the resolver's null-author path).
     */
    private function bylineAuthorCandidate(string $html): ?string
    {
        $items = $this->postInfoItems($html);

        foreach ($items as $i => $text) {
            if (strcasecmp($text, 'Book Review') === 0) {
                return $items[$i + 1] ?? '';
            }
        }

        return null;
    }

    /** @return array<string> trimmed text of the post-info type-custom items */
    private function postInfoItems(string $html): array
    {
        preg_match_all(
            '#<span[^>]*class=["\'][^"\']*elementor-post-info__item--type-custom[^"\']*["\'][^>]*>(.*?)</span>#si',
            $html,
            $matches
        );

        return array_map(
            fn (string $text) => trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5)),
            $matches[1]
        );
    }

    /** Sitemap fetches are foundational — non-200/unparsable XML is fatal. */
    private function fetchXml(string $url): SimpleXMLElement
    {
        $response = $this->get($url);
        if (! $response->successful()) {
            throw new RuntimeException("Plugged In sitemap request failed ({$response->status()}) on {$url}");
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($response->body(), SimpleXMLElement::class, LIBXML_NONET);
        libxml_clear_errors();
        if ($xml === false) {
            throw new RuntimeException("Plugged In sitemap is not parsable XML: {$url}");
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
