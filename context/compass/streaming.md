# Streaming Availability Integration
> Last validated: 2026-06-11

## Quick Commands
- Full daily pipeline: `php artisan streaming:update` (sync → enrich → verify-kids, fail-fast)
- Check status: `php artisan streaming:status`
- Refresh service catalog: `php artisan streaming:refresh-services`
- Initial backfill: `php artisan streaming:backfill`
- Daily sync: `php artisan streaming:sync`
- TMDB enrich: `php artisan streaming:enrich`
- Smoke test API: `php artisan streaming:smoke`

## Key Files
- `app/Console/Commands/StreamingRefreshServices.php`, `StreamingBackfill.php`, `StreamingSync.php`, `StreamingEnrich.php`, `StreamingStatus.php`, `StreamingSmoke.php` — 6 artisan commands
- `app/Console/Commands/StreamingVerifyKids.php` — verifies which US Netflix titles surface in the Kids profile, sets `netflix_kids_surfaced` (`streaming:verify-kids`)
- `app/Services/NetflixKids/NetflixKidsClient.php` — scrapes the /Kids page for session facts (auth, member API base, build id), runs the GraphQL Kids search and the falcor maturity lookups
- `app/Console/Commands/StreamingUpdate.php` — orchestrator chaining the four pipeline steps fail-fast (`streaming:update`); the only command scheduled daily
- `routes/console.php` — schedule: `streaming:update` daily 03:00, `streaming:refresh-services` monthly
- `app/Services/StreamingAvailability/Client.php` — HTTP wrapper, retries 429/5xx + connection timeouts, configurable QPS throttle and request timeout
- `app/Services/StreamingAvailability/CatalogService.php` — read-side grouping/dedup for `/streaming` endpoint
- `app/Services/StreamingAvailability/TmdbEnricher.php` — TMDB pass for us_certification + trailer fallback
- `config/services.php` — `streaming_availability` block (api_key, base_url, qps)

## Non-Obvious Patterns
**Requests are the constraint, not credits.** The Mega plan is $199/mo for 1M requests. Each `/shows/search/filters` page is one request (20 shows). `streaming_sync_log.api_calls_used` counts requests per run. `streaming:status` shows monthly aggregate.

**Service IDs are slugs.** Streaming Availability uses string slugs (`netflix`, `prime`, `disney`, `hbo`, `hulu`, `apple`, `peacock`, `paramount`, `starz`) rather than numeric IDs. Use these directly in API requests AND in our DB.

**TMDB ID parsing.** API returns `tmdbId` as a string like `"tv/124339"` or `"movie/12345"`. We split that into `tmdb_type` (string) and `tmdb_id` (int) on insert. The `/shows/{id}` endpoint accepts the slash format directly when looking up by TMDB.

**Quality enum is lowercase.** API returns `quality` (not `videoQuality`) with values `uhd`/`hd`/`sd`. Stored as `streaming_title_offers.video_quality`. CatalogService dedups by service+type keeping highest quality (uhd > hd > sd).

**Addon collapses to subscription.** API has 5 streaming option types: `subscription`, `free`, `rent`, `buy`, `addon`. The `addon` type (e.g., HBO via Hulu addon) collapses into the `subscription` group in the response so parents see "available on Hulu" without surfacing the addon-vs-direct distinction.

**Tracked catalogs live in two `const CATALOGS` arrays.** `StreamingBackfill.php` and `StreamingSync.php` each declare their own `private const CATALOGS` of `<service_id>.<type>` slugs. They must stay in sync — sync would otherwise apply daily /changes to catalogs the backfill never seeded (or vice versa). Currently 24 catalog slots across 14 services (netflix, prime, disney, hbo, hulu, apple, peacock, paramount, starz, tubi, plutotv, crunchyroll, discovery, curiosity, britbox, mubi, criterion, zee5). The /shows search and /changes responses always return ALL streaming options per title regardless of which catalog you queried, so non-tracked services accumulate *incidental* offers — backfilling a service only adds the titles that don't overlap with already-tracked catalogs.

**Out-of-catalog services are seeded on demand from offer payloads.** `streaming:refresh-services` only seeds what `/countries/us` returns (~18 services). But individual show payloads reference services outside that catalog (e.g. `roku` / The Roku Channel), and `streaming_title_offers.service_id` has a FK to `streaming_services`. `StreamingService::ensureFromPayload()` (called from both `replaceUsOffers()` implementations and `StreamingSync::applyUpcoming()`) creates the missing service row from the offer's embedded `service` object (id/name/themeColorCode/imageSet) before the offer is written, so incidental offers persist and surface to parents instead of being FK-rejected. It keeps an in-process `$ensuredIds` cache to avoid re-upserting on every offer. Without this, an unseeded service id throws mid-`replaceUsOffers()` and — because that method DELETEs the title's offers then re-inserts — half-wipes the title (the DELETE + re-inserts are now wrapped in `DB::transaction()` to make the swap atomic regardless).

**Title-attribute extraction is centralized in `StreamingBackfill::titleAttrs()`.** Both `StreamingBackfill::upsertTitle()` and `StreamingSync::upsertTitle()` call this single static helper to build the column map from a show response. Add new title columns here; both ingest paths pick them up automatically. Posters prefer `imageSet.verticalPoster.w720` (falls back to w480); `backdrop_url` comes from `imageSet.horizontalBackdrop.w1080`.

**Trailers come from TMDB only.** The Streaming Availability API's `videos` field is always null/missing in responses (confirmed for both `/shows/{id}` and `/shows/search/filters`). Don't try to extract trailers from show payloads. `TmdbEnricher` is the sole source for `trailer_url` and `us_certification`.

**Upcoming sweep writes future-dated offers.** `StreamingSync` runs a separate `change_type=upcoming` pass over `UPCOMING_CATALOGS` (Apple/Disney/Max/Netflix/Prime only — the 5 services that support upcoming per docs). It creates `streaming_title_offers` rows with `available_from` set to the future timestamp and `link` set to the future deep link. Quality/price are unknown until the title actually drops.

**`replaceUsOffers()` preserves upcoming rows.** The DELETE in `replaceUsOffers()` (in both `StreamingBackfill` and `StreamingSync`) is scoped to `WHERE available_from IS NULL`, so a 'new'/'updated' change for one service does not wipe pending upcoming offers on *other* services. Upcoming rows for the same service+type may coexist with a freshly-arrived real offer (different `video_quality` → no unique-constraint collision); read-side filtering should still respect `available_from <= NOW()`.

**Spam titles get soft-deleted on TMDB 404.** `streaming:enrich` soft-deletes a title when TMDB returns 404 with `tmdb_id > 0`. `streaming:backfill` and `streaming:sync` use `withTrashed()->updateOrCreate()` so soft-deleted spam isn't re-inserted.

**Sync window is 72 hours, not 24.** `streaming:sync` covers a 72-hour overlap so a missed daily run self-heals on the next run.

**`streaming:update` is the only scheduled daily job; it runs three steps fail-fast.** Order is sync → enrich → verify-kids; the first non-zero exit (or thrown exception, mapped to exit 1) stops the chain and `streaming:update` returns that code (remaining steps skipped). Only `--hours` is forwarded (to `sync`, default 72, validated 1–720 — exit 2 consistently means invalid input, in `streaming:sync` too). The command holds a 12h cache lock for the duration of a run so scheduled and manual invocations can't overlap (a second run exits 1 immediately; the database cache store needs the `cache`/`cache_locks` tables from the 2026_06_10 migration). Each run writes a `streaming_sync_log` row with `sync_type='pipeline'`, so failed pipelines surface in `streaming:status`. Consequence: when the Netflix Kids cookie is stale, the daily run fails at `verify-kids`. The individual sub-commands are still runnable standalone for targeted re-runs (e.g. `streaming:verify-kids --force`).

**Netflix maturity lookups go through the nodequark member API, not the legacy shakti URL.** Netflix retired `/api/shakti/{id}/pathEvaluator` on 2026-06-11 (404; the build-id form 421s). `NetflixKidsClient::probeSession()` scrapes the `"memberapi"` config object from the /Kids page (`https://www.netflix.com/nq/website/memberapi/release`) and `maturityLevels()` POSTs to `{base}/pathEvaluator?original_path=/shakti/mre/pathEvaluator` — the `original_path` param is mandatory (412 without it). The response is falcor jsonGraph: `jsonGraph.videos.{id}.maturity.value.rating.maturityLevel` (maturity is an atom; the old shape was `value.videos.{id}.maturity.rating.…`). The page still advertises the dead shakti URL under `"apiUrl"` — don't scrape that. If Netflix rotates again, the verify-kids session gate aborts loudly before any write.

**Rate limits hit fast.** Free tier (500 req/mo) returns 429 after about 50 sequential requests. Mega tier rate limit isn't documented, so the Client throttles to 5 QPS by default (`STREAMING_AVAILABILITY_QPS` env var) and retries 429/5xx with exponential backoff (1s → 2s → 4s, max 3 attempts). It also retries transport-level timeouts (cURL error 28 → `ConnectionException`) with the same backoff. The `/changes` feed computes the whole response server-side before sending a byte, and that latency is erratic — most calls return in <10s but some spike to ~85s for the same ~300KB payload (it's upstream load variance, not payload size or window width — measured: a 48h window took 85s while a 60h window took 10s). The per-request timeout therefore defaults to 120s (`STREAMING_AVAILABILITY_TIMEOUT` env var) to sit above that tail; retries catch the rarer outlier. Without this, a single slow request aborts the whole daily `streaming:update` pipeline (it's fail-fast — one catalog's failure stops sync and all downstream steps).

**Backfill is resume-safe.** `streaming_sync_log.last_cursor` is updated after every page so a 429 storm or process kill doesn't waste the run. Re-running `streaming:backfill` continues from where it left off.

**Both backfill and sync bump PHP memory to 512M.** `/shows/search/filters` and `/changes` responses include full show payloads (cast, directors, streamingOptions, imageSet variants). PHP's allocator high-water mark accumulates across hundreds of paginated requests and the 128M default OOMs partway through large catalogs (Prime, Tubi). Both commands also call `DB::connection()->disableQueryLog()` so query history doesn't pile up either.

**Reports link by imdb_id/tmdb_id only.** No FK between `reports` and `streaming_titles`. CatalogService matches by IMDB first, then TMDB+tmdb_type. A report with no matching streaming_title returns empty groups.

**Smoke fixture for TV is Chernobyl, not The Office.** `streaming:smoke` uses `tv/87108` (Chernobyl, 5 episodes, ~1MB response) instead of The Office, which returns ~49MB of episode-level JSON and exhausts PHP's 512MB memory limit.

**Env vars:** `STREAMING_AVAILABILITY_API_KEY` (required), `STREAMING_AVAILABILITY_BASE_URL` (default https://api.movieofthenight.com/v4), `STREAMING_AVAILABILITY_QPS` (default 5), `TMDB_API_KEY` (for enrichment only).

## See Also
- `docs/superpowers/specs/2026-04-29-streaming-availability-migration-design.md` — original design doc
- `docs/superpowers/plans/2026-04-29-streaming-availability-migration.md` — implementation plan
