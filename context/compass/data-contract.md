# Data Contract
> Last validated: 2026-05-21

## Quick Commands
- View schema: `database/migrations/`
- Check published data: `php artisan tinker` → `Report::with('categoryGroups', 'ratings')->first()`

## Key Files
- `database/migrations/` — all table definitions
- `app/Models/Report.php` — core model with relationships
- `app/Models/CategoryGroup.php` — section/group-level groupings
- `app/Models/Rating.php` — individual subcategory ratings

## Non-Obvious Patterns
**This database is write-only from the admin app's perspective.** The parent app has zero write endpoints. All data arrives via the admin's `publish_to_parent_db()` which writes directly to this database. The parent app is purely read-only.

**Reports dedup on (content_type, title, year).** Re-publishing from admin upserts on this composite key. The parent app never needs to handle conflicts.

**Three-table normalized structure.** `reports` → `category_groups` (section_key, group_key) → `ratings` (subcategory_key, present, level, evidence). This mirrors the admin's `PARENT_TAXONOMY` allowlist — only explicitly opted-in fields appear here.

**Fields that must NEVER appear here:** review source names, reasoning fields, cached subtitles, QA issues. The admin's allowlist transform strips these before writing. If you see them, it's a publish pipeline bug in the admin app.

**Streaming tables are independent.** `streaming_*` tables have no foreign keys to `reports`. They're linked at query time via `imdb_id`/`tmdb_id` matching, not database-level joins. Four tables: `streaming_services`, `streaming_titles`, `streaming_title_offers`, `streaming_sync_log`.

**`streaming_titles` uses soft-deletes** (`deleted_at`) to purge TMDB-404 spam without losing the row for inspection. Offers are stored in `streaming_title_offers` (normalized, one row per title+service+region+type+quality). See `context/compass/watchmode.md` for historical context.

**Offer rows can represent future availability.** `streaming_title_offers.available_from` is non-null for upcoming drops captured by `/changes?change_type=upcoming` (Apple/Disney/Max/Netflix/Prime only). Read-side queries must treat `available_from IS NULL OR available_from <= NOW()` as "available now" — otherwise upcoming offers leak into "where to watch right now" responses.

## See Also
- `context/compass/api-and-auth.md` — how this data is served
- Admin app's `context/compass/data-isolation.md` — the publish pipeline that writes here
