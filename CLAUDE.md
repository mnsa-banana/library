# Imbuo Library

Standalone, headless catalog service: the streaming/book library extracted from
sponge-kids (Phase 2, 2026-06-12). **Single writer: this repo's cron. Everyone
else reads** via the versioned API. The API is read-only, forever — product
writes (e.g. sponge's future "request a report") belong in the product apps.

## What runs here

- Daily `streaming:update` (sync → enrich → verify-kids), monthly
  `streaming:refresh-services`, weekly `book:weekly` + `book:enrich`
  (schedule in `routes/console.php`; overlap guards are cache locks on the
  database cache store).
- Read API under `/api/v1/` behind named bearer tokens
  (`LIBRARY_READ_TOKENS=name:token,...`):
  - `GET /api/v1/netflix/availability` — Netflix-US imdb_id set + Kids subset
  - `GET /api/v1/status` — last successful sync per pipeline (staleness check)

## Commands

- Serve API: `php artisan serve --host=127.0.0.1 --port=8003`
- Local DB: `docker compose up -d` (Postgres 15 on :5434, db `imbuo_library`)
- Tests: `php artisan test --compact`
- Pipeline status: `php artisan streaming:status` / `php artisan book:status`

## Deploy

Railway project `imbuo-library`: `web` service (read API), `scheduler` service
(`php artisan schedule:work`), Postgres. Consumers use the generated
`*.up.railway.app` hostname over HTTPS — no custom domain.

## Git remotes

This repo lives in **two GitHub accounts** and every push must reach both:

- `tdikun/imbuo-library` (primary, `origin` fetch)
- `mnsa-banana/library` (mirror; named remote `mnsa`)

`origin` is configured with two push URLs, so a plain **`git push`** mirrors to
both automatically — always push via `origin` (or `git push --all` style to
origin), never push to only one. The `mnsa-banana/library` URL uses the `github-mnsa` SSH
host alias (`~/.ssh/config`) so it authenticates with the mnsa-banana key.
Verify with `git remote -v`: `origin` should list one fetch URL and **two**
push URLs.

## Context

`context/compass/streaming.md` and `context/compass/book-library.md` carry the
non-obvious pipeline knowledge (API quirks, quotas, memory limits). Read them
before touching the pipelines.
