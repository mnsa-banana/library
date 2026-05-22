# API & Auth
> Last validated: 2026-05-22

## Quick Commands
- View routes: `php artisan route:list --path=api`
- Test endpoint: `curl -H "Authorization: Bearer $TOKEN" http://127.0.0.1:8001/api/v1/reports`

## Key Files
- `routes/api.php` — all route definitions
- `app/Http/Controllers/Api/V1/ReportController.php` — report list/detail/streaming
- `app/Http/Controllers/Api/V1/AuthController.php` — register, login, logout, /me
- `app/Http/Controllers/Api/V1/AccountController.php` — change password, request email change, confirm email change, delete account
- `app/Http/Controllers/Api/V1/PasswordResetController.php` — forgot/reset password (signed-link flow)
- `app/Http/Controllers/Api/V1/BillingController.php` — RevenueCat status, checkout URL, manage URL (customer center)
- `app/Http/Controllers/Api/V1/RestrictionController.php` — LGBTQ content filtering
- `app/Http/Middleware/EnsureSubscribed.php` — subscription gate middleware
- `app/Services/RevenueCatService.php` — RC API integration

## Non-Obvious Patterns
**Two middleware layers gate data access.** `auth:sanctum` checks the bearer token exists and is valid. `subscribed` (EnsureSubscribed) then checks RevenueCat for an active entitlement. Both must pass before any report data is served.

**RevenueCat lifetime subscriptions.** `expires_date === null` in the RC response means lifetime — it's not an error. The service checks `expires_date > now() OR expires_date === null`.

**Checkout is out-of-app.** `BillingController::checkoutUrl()` returns a URL that opens in the browser. The user completes purchase on RevenueCat's hosted page, then returns to the app and taps "Check Status" to verify.

**Streaming endpoint returns empty, never errors.** If no matching title is found in `streaming_titles` for a report's `imdb_id`/`tmdb_id`, the streaming endpoint returns `{subscription: [], free: [], rent: [], buy: []}` — not a 404. Data is served via `CatalogService::getStreamingOptions()` from the `streaming_*` tables.

**Report pagination.** Default 20 per page, max 50. Supports `?content_type=movie|tv|book` filter and `?search=` for case-insensitive title substring matching (PostgreSQL `ilike`).

**Restrictions endpoint is specialized.** Filters reports that have LGBTQ-related ratings (explicit_characters_or_relationships, implied_or_coded) AND are available on a given streaming platform. Used by the Netflix Chrome extension.

## See Also
- `context/compass/data-contract.md` — the data these endpoints serve
- `context/compass/mobile-app.md` — the client that consumes these endpoints
