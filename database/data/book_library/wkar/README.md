# WKAR ("What Kids Are Reading") import files

Input files for `php artisan book:seed --source=wkar --file=<path>`. No report
data is bundled — Renaissance publishes the annual "What Kids Are Reading"
report as a PDF/interactive page, and its lists must be extracted manually per
edition (the spec rules out AR BookFinder scraping).

## Format

A JSON array of entries:

```json
[
  {"title": "Dog Man: Mothering Heights", "author": "Dav Pilkey", "grade_band": "3-5", "rank": 1, "year": 2024}
]
```

- `title` (required), `author`, `year` (required — becomes the membership
  `list_key`, one list per report year), `rank` (optional, position within the
  grade band's list), `grade_band` (stored in membership `metadata`).
- `grade_band` → `min_age` mapping (written with `min_age_source='wkar'`;
  provenance precedence `csm_index > wkar > nyt` still applies):
  `K-2` → 5, `3-5` → 8, `6-8` → 11, `9-12` → 14. Any other band value keeps
  `min_age` null but is still recorded in metadata.

## Extracting a report

1. Get the current report from renaissance.com ("What Kids Are Reading", free
   download after registration).
2. For each grade-band top-books table, transcribe title/author/rank into the
   JSON shape above, normalizing grade bands to `K-2` / `3-5` / `6-8` / `9-12`
   (the report sometimes lists per-grade tables — collapse grades 1/2 into
   `K-2`, etc., keeping each book's best rank).
3. Set `year` to the report edition year for every entry.
4. Run `php artisan book:seed --source=wkar --file=...` (re-runs upsert; safe).

`tests/fixtures/book_library/wkar_sample.json` shows the expected shape with
illustrative (not real-report) entries.
