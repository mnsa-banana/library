# Book Library
> Last validated: 2026-06-11

A parent-side catalog of children's/YA books aggregated from curated list
sources (bestseller lists, review indexes, reading reports, award canons).
Standalone in v1 — no link to `reports`; the admin-side interactive
acquisition flow that consumes this catalog (candidate picker, manual confirm)
ships with this feature — only batch analysis + bulk actions are a follow-up
PRD.

## Quick Commands
- Weekly NYT sync (scheduled): `php artisan book:weekly`
- Seed a source: `php artisan book:seed --source={csm|pluggedin|nyt-history|wkar|award} [--file=...] [--limit=N] [--resume]`
- Enrich metadata: `php artisan book:enrich [--limit=N] [--force]`
- Health report: `php artisan book:status`
- Review skipped dedup matches: `php artisan book:status --ambiguous`

## Key Files
- `database/migrations/2026_06_10_100001_create_book_library_tables.php` — `book_library_titles` (work-level rows), `book_list_memberships`, `book_sync_log`
- `app/Models/BookLibraryTitle.php` — `saving` hook recomputes `normalized_title`/`normalized_author` on every save; `BookListMembership.php`, `BookSyncLog.php`
- `app/Services/BookLibrary/Normalizer.php` — deterministic title/author/ISBN-13 normalization
- `app/Services/BookLibrary/WorkResolver.php` — 4-step work-level dedup
- `app/Services/BookLibrary/IngestService.php` — source-agnostic ingest: resolve → min_age provenance write → membership upsert
- `app/Services/BookLibrary/SyncRun.php` — lifecycle wrapper around a `book_sync_log` row
- `app/Services/BookLibrary/OpenLibraryClient.php`, `NytClient.php`, `GoogleBooksClient.php` — HTTP clients (Laravel Http, fakeable) + typed `*RateLimitedException` per client
- `app/Services/BookLibrary/CsmIndexScraper.php`, `PluggedInIndexScraper.php` — review-index scrapers (sitemap walk + per-page metadata fetch)
- `app/Console/Commands/BookWeekly.php`, `BookSeed.php`, `BookEnrich.php`, `BookStatus.php`
- `routes/console.php` — `book:weekly` Thu 09:00 + `book:enrich` Thu 10:00, both `withoutOverlapping()`
- `config/services.php` — `nyt.books_key`, `google_books.key`
- `database/data/book_library/awards/`, `database/data/book_library/wkar/` — import files + authoring READMEs

## Non-Obvious Patterns
**Three tables, no FK to reports.** `book_library_titles` is one row per *work*
(not edition); `book_list_memberships` is unique per
(library_title_id, list_source, list_key) and upserts newest-wins — a dated
overwrite is skipped when its `as_of_date` is strictly older than the stored
one (the NYT backfill walks newest→oldest), null-dated upserts always apply —
so every seed re-run is safe; `book_sync_log` mirrors `streaming_sync_log` (status, api_calls_used,
titles_processed, last_cursor, metadata). `isbn13s` is jsonb with a GIN
containment index — pgsql only, sqlite test runs skip it.

**Source keys are fixed strings.** `list_source` ∈ `csm_index` /
`pluggedin_index` / `nyt` / `wkar` / `award`. `list_key` per source: `index`
for both scrapers, the NYT list slug (`picture-books`, …), the WKAR report
year, the award file slug (`newbery`/`caldecott`/`printz`). Rank /
weeks_on_list / as_of_date / review_url / metadata ride on the membership.

**Resolver order: ISBN → OL work → normalized title+author → ambiguous-skip.**
Step 1 matches incoming ISBN-13s against stored `isbn13s` (jsonb containment).
Step 2 resolves ISBN→work via Open Library and matches `work_key` — skipped
when step 1 already matched a row carrying `work_key` (resume must not redo OL
lookups). Step 3 is exact `normalized_title` equality + author *last-name*
agreement: a null author on either side allows the match (and fills it); a
conflicting last name rejects it. More than one candidate at step 1 or 3 →
ambiguous: logged to the run's `metadata['ambiguous']` and skipped, never
guessed. Non-Latin titles normalize to `''` and never match by title.

**A match is a fill-null merge, never an overwrite.** author/year/cover_url/
description fill nulls only; `isbn13s` unions; `work_key` (unique) is stamped
only when no other row carries it — a collision means two rows are duplicates
of one OL work and is logged as a warning instead of silently dropped. The
same collision policy repeats in `BookEnrich::openLibraryPass`.

**min_age provenance: `csm_index` > `wkar` > `nyt`.** `IngestService` writes
`min_age` only when the incoming source's rank ≥ the stored source's rank, so
the outcome is deterministic regardless of seed order. `pluggedin_index` and
`award` carry no age signal. NYT ages come from the list band (picture/chapter
4, middle-grade 8, YA 12; `series-books` spans bands → none); WKAR from the
grade band (`K-2`→5, `3-5`→8, `6-8`→11, `9-12`→14); CSM from JSON-LD
`typicalAgeRange` ("7+" → 7).

**Cursor/resume semantics differ per command.** `SyncRun::cursor()` saves the
whole log row immediately (it's the resume checkpoint); counters and ambiguous
entries persist lazily on the next cursor/complete/fail.
- *csm / pluggedin seeds:* cursor = last **processed** URL within the sorted
  URL list — advanced on fetch/parse skips too, so `--resume` never re-grinds
  a permanently broken page. `--resume` skips every URL ≤ cursor (string order
  matches the sorted walk). An OL 429 *before the first checkpoint* persists
  the `''` sentinel cursor: non-null (shields older runs' cursors from
  `lastCursor`) and ≤ every URL, so `--resume` starts from the beginning.
- *nyt-history:* cursor = `{list}|{date}`. Walks each list from its
  `lists/names` newest date following `previous_published_date` — never date
  arithmetic — down to that list's oldest date. Resume re-fetches the cursor
  page; harmless, memberships upsert.
- *enrich:* no `--resume` flag — the default `whereNull('enriched_at')`
  selection naturally resumes. `enriched_at` is stamped even when both lookups
  whiff (a whiffing row must not be re-queried every run); `--force`
  re-processes stamped rows, still fill-null. Ids are snapshotted up front
  because processing mutates the very column the selection filters on.
- *wkar / award:* local-file imports, no cursor — re-runs upsert.

`--resume` footgun: after a run that completed `exhausted=true`, `--resume`
skips everything ≤ the final cursor — a refresh pass must be a fresh full run
(upserts make it safe).

**Rate limits stop runs cleanly; `exhausted` is the retry signal.** Each
client raises its typed exception (`NytRateLimitedException`,
`OpenLibraryRateLimitedException`, `GoogleBooksRateLimitedException` — the
latter on 429 after retries *or* 403 `rateLimitExceeded`; any other 403 fails
fast). Commands catch it, persist the cursor, complete the run with
`metadata.exhausted=false`, and exit 0 — rerun later with `--resume`.
`exhausted=true` means the source was fully walked. An empty sitemap walk or
empty NYT `lists/names` **fails** the run instead — completing would be
indistinguishable from a finished seed.

**Call budgets.** NYT enforces 5 req/min + ~500/day → 12s between calls and a
450-call cap per nyt-history run (headroom for `book:weekly`). `book:enrich`
has a 900-call OL+GB ceiling checked *between* rows (a row may overshoot by
its own handful, never more); GB calls are charged to the run log even when
the lookup throws mid-row. OL and GB retry transient 429/5xx/connection
failures with exponential backoff (1s → 2s, 3 attempts).

**CSM robots compliance is load-bearing.** Every request carries a plain
generic-browser UA — CSM blocks AI-labeled agents site-wide. CSM disallows
`/*?page=` EXCEPT `/*/sitemap.xml?page=`: the only paginated URLs the scraper
may request are the `reviews/sitemap.xml?page=N` children it read from the
nested sitemap index (sitemap.xml → reviews/sitemap.xml → page sitemaps,
filtered to `/book-reviews/`). Listing `?page=` URLs must never be crawled.
Default 1 req/s politeness delay. Page metadata comes from JSON-LD
(`itemReviewed`: name, author, one arbitrary edition ISBN, typicalAgeRange)
with og:title fallback; a failed page is logged + skipped, never fatal —
only sitemap fetches are fatal.

**Plugged In: Yoast walk, byline digit guard.** `sitemap_index.xml` is a Yoast
per-post-type index; only `book-reviews-sitemap*.xml` children are fetched
(URL-level `/book-reviews/<slug>` filter still decides; the slugless archive
root is excluded — it's a listing page, not a review). Pages carry no JSON-LD,
no ISBN, no machine-readable age — title (Elementor h1, og:title fallback) +
author only. The author is the post-info byline item right after the "Book
Review" label; digit-leading candidates (age band "8 to 12", year) yield null
— a wrong author would poison work resolution with no ISBN to rescue it, while
a null back-fills via the resolver's null-author path.

**The ambiguous loop is manual.** Resolver skip → entry (step, incoming,
candidates, list context) in `book_sync_log.metadata['ambiguous']` →
`book:status --ambiguous` dedupes by incoming title + list_source across the
last 50 runs → resolution = manually edit the candidate rows (v1 has no admin
UI). The skipped item's membership was never written; scraper cursors have
already advanced past its page, so after fixing the rows, re-ingest it via a
fresh (non-`--resume`) run or insert the membership by hand.

**Award data conventions.** Authored from ALA primary sources into
`database/data/book_library/awards/` (one JSON file per award; see its README
for coverage + source URLs). For Caldecott entries crediting both an illustrator and a
writer, `author` is the **text writer** — the resolver matches NYT/CSM-style
author credits, not medal recipients. Pseudonyms stay as printed on the book.
`list_key` = file slug; year + winner|honor ride in membership metadata.

**WKAR is manual extraction.** Renaissance publishes "What Kids Are Reading"
as a PDF — lists are transcribed by hand per edition (the spec rules out AR
BookFinder scraping; see `database/data/book_library/wkar/README.md`). `year`
is required and becomes the `list_key`; grade band is stored in membership
metadata and drives min_age.

**Enrich is fill-null discipline end to end.** OL pass: resolve a work for
ISBN-bearing rows missing `work_key`, then union edition ISBNs + fill a
missing cover from the editions feed (charged as 2 calls). GB pass: skipped
entirely when nothing is left to fill (quota is the scarce resource); `isbn:`
queries are trusted, the title+author fallback is accepted only on exact
normalized-title equality; `preview_available` comes from
`accessInfo.viewability` ONLY — `previewLink` exists even for NO_PAGES volumes
and must never be consulted.

**Env keys:** `NYT_BOOKS_API_KEY` (`book:weekly` no-ops gracefully — exit 0
plus a failed sync-log row — when unset; `book:seed --source=nyt-history`
fails), `GOOGLE_BOOKS_API_KEY` (optional — Google Books works keyless at a
lower quota; the key is appended only when configured).

## See Also
- `database/data/book_library/awards/README.md`, `.../wkar/README.md` — import file authoring
- The original design spec and implementation plan live in the sponge-kids repo's local docs/ tree (gitignored; not referenced by path here)
