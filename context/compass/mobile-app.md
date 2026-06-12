# Frontend (Web SPA)
> Last validated: 2026-06-11 (rewritten after the MNSA repo split — this app is single-brand Sponge Kids; all brand detection/multiplexing, the MNSA Chrome-extension handshake, and the Blade landing now live in the sibling `mnsa` repo)

The parent-facing frontend is a **Vite + React 19 + TypeScript** single-page app (`react-router-dom` v7) under `frontend/`. Single brand: Sponge Kids. (It used to be an Expo/React-Native app, then a multi-brand SPA shared with MNSA; both are gone.)

## Quick Commands
- Start everything (all 4 servers): `imbuo` from the workspace root
- Frontend only: `npm run dev --prefix frontend` — Vite dev server on port 5173, proxies `/api` → `http://127.0.0.1:8001`
- Build: `npm run build --prefix frontend` — runs `tsc -b && vite build`, outputs to `public/build/` with `base: '/build/'`; Laravel serves the built shell via `SpaShell` (`Route::fallback`)
- (No test runner in this package.)

## Key Files
- `frontend/src/main.tsx` — entry; mounts `<App/>` in `StrictMode`, imports `global.css`
- `frontend/src/App.tsx` — sets `document.title = 'Sponge Kids'`, imports `theme.css`, wraps the tree in `AuthProvider` → `AuthSync` → `BrowserRouter` → `AppRouter`. `AuthSync` re-verifies subscription on load via `fetchMe()`. Also bootstraps `_token` in `api.ts` synchronously from `localStorage.auth_token` at module load — without this, child useEffects (e.g. `Account`/`Home` calling APIs on mount) fire before `AuthSync`'s effect can sync the token, causing a 401 → auto-logout → redirect on hard refresh of any authed page.
- `frontend/src/router.tsx` — `AppRouter`: routes + guards. `RequireAuth` → `/login`; `RequireSubscription` → `/subscribe`; `RedirectIfAuthenticated` → `/home`. Routes: `/` (Splash, public), `/login`, `/register`, `/forgot`, `/reset`, `/email/confirm`, `/account` (auth), `/subscribe` (auth), `/home` + `/reports/:id` (auth + subscription), `*` → `NotFound`.
- `frontend/src/shared/auth-context.tsx` — `AuthProvider` / `useAuth()`; token + user + subscription flag persisted in `localStorage`; `login`/`logout`/`setSubscribed`
- `frontend/src/shared/api.ts` — fetch wrapper; `setApiToken`, `setOnUnauthorized` (401 auto-logout); `apiLogin`/`apiRegister`/`apiLogout`, `fetchMe`, `fetchReports`/`fetchReport`, `fetchBillingStatus`/`fetchCheckoutUrl`/`apiManageUrl`, password/email-change/account-deletion calls
- `frontend/src/shared/pages/` — `Login.tsx`, `Register.tsx`, `Forgot.tsx`, `Reset.tsx`, `EmailConfirm.tsx`, `Account.tsx`, `Subscribe.tsx` (RevenueCat: opens `checkout_url` in a tab, polls billing status), `NotFound.tsx`
- `frontend/src/pages/` — `Splash.tsx` (public landing), `Home.tsx` (search-first report list, debounced), `Report.tsx`
- `frontend/src/global.css` — shared utility classes (`page-center`, `auth-card`, `form-input`, `btn-primary`, …) — all consume `var(--color-*)`
- `frontend/src/theme.css` — Sponge Kids CSS-var tokens on `:root`

## Non-Obvious Patterns
**Route gating, not screen gating.** Auth/subscription gates live in `router.tsx` (`RequireAuth`, `RequireSubscription`), not inside screens. Logged-out → `/login`; logged-in-but-unsubscribed → `/subscribe`; the splash and auth pages are public.

**Auth state in localStorage, re-verified on load.** Token, user object, and subscription flag persist in `localStorage`. On app load `AuthSync` calls `fetchMe()` to re-confirm the subscription every time. Any 401 triggers `setOnUnauthorized` → full logout — no token-refresh logic.

**Styling is plain CSS files + inline styles — no CSS-in-JS, no CSS modules, no Tailwind.** Layers: `global.css` (utility classes) → `theme.css` (CSS-var tokens). Components also use inline `style={{…}}` liberally.

**Laravel never renders pages.** Every non-API route falls through to `SpaShell`, which returns the prebuilt `public/build/index.html` (500 with a hint if the frontend hasn't been built). In dev the SPA runs on Vite's origin (5173) and proxies `/api`.

## See Also
- `context/compass/api-and-auth.md` — the API these screens consume
- `context/compass/data-contract.md` — the shape of published reports
- `context/compass/streaming.md` — streaming-availability data shown on report screens
