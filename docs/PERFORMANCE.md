# Performance

Workstream P1, 2026-11-04. The owner reported the platform feels sluggish. This page records how it was measured, what changed, and how to keep it fast.

## Headline

| | Before (`php artisan serve`) | After, same server | After, `scripts/serve-fast` |
|---|---|---|---|
| Page navigation (Inertia visit), median | 1,080 to 1,920 ms | 550 to 770 ms | **130 to 190 ms** |
| Page navigation payload | 275 kB | **14.5 kB** | 14.5 kB |
| API list calls, median | 390 to 720 ms | 310 to 490 ms | **27 to 78 ms** |
| First full page load (/dashboard), median | 1,305 ms | 1,711 ms (noisy, see load) | **315 ms** |

## Method

- Bench: `scripts/perf/bench.php` logs in with the demo login (`admin`), then times GET requests only: the login page, a full /dashboard load, Inertia XHR visits (with `X-Inertia` headers and, like the browser, `X-Inertia-Except-Once-Props`) of /dashboard, /reservations, /billing/folios, /pos, /finance, /stock, /hr, /tasks, and the API calls those pages make. One warm-up request per target, then N timed runs; prints median, p90, bytes, queries and query time.
- Query counts: start the server with `PERF_DEBUG=true` (local environment only). `App\Http\Middleware\PerfDebug` adds `X-Debug-Queries`, `X-Debug-Query-Ms`, `X-Debug-Time-Ms`. `PERF_DEBUG=sql` also appends each request's SQL to `storage/logs/perf-sql.log`. The middleware does nothing otherwise.
- Data: the demo database (12 hotels, 1,000 rooms, 1,053 reservations, 1,630 POS checks, 4,800 ledger entries, 103 employees), read only (GET requests; the demo login and the database session driver write session rows, as any browser does).
- Load: the machine was saturated by other agents' test runs and builds the whole time (Windows CPU load 100%). The before and after runs were taken under the same conditions minutes apart; absolute numbers on an idle machine are lower. Runs: 7 (before, after on artisan serve) and 9 (serve-fast).

Reproduce:

```sh
PERF_DEBUG=true php artisan serve --port=8190          # or: PERF_DEBUG=true bash scripts/serve-fast.sh 8190
php scripts/perf/bench.php http://127.0.0.1:8190 9 admin [filter]
```

## Measurements (median / p90 ms, bytes, queries / query ms)

| Target | Before: artisan serve | After: artisan serve | After: serve-fast |
|---|---|---|---|
| login page (anonymous) | 1135 / 1841, 309 kB, 4 q | 1321 / 1417, 315 kB, 4 q | 358 / 517, 315 kB, 4 q / 18 ms |
| dashboard full load | 1305 / 1574, 278 kB, 10 q | 1711 / 2667, 282 kB, 9 q | 315 / 426, 282 kB, 9 q / 23 ms |
| Inertia /dashboard | 1473 / 1986, 275 kB, 10 q | 686 / 889, 14.5 kB, 9 q | 188 / 216, 14.5 kB, 9 q |
| Inertia /reservations | 1442 / 1779, 275 kB | 769 / 1464, 14.5 kB | 168 / 254, 14.5 kB |
| Inertia /billing/folios | 1080 / 1614, 275 kB | 589 / 852 * | 137 / 164, 14.5 kB |
| Inertia /pos | 1442 / 1782, 275 kB | 552 / 685 * | 140 / 209, 14.5 kB |
| Inertia /finance | 1630 / 1916, 275 kB | 583 / 695 * | 177 / 215, 14.5 kB |
| Inertia /stock | 1917 / 2368, 275 kB | 703 / 877 * | 133 / 156, 14.5 kB |
| Inertia /hr | 1347 / 1958, 275 kB | 627 / 1004 * | 159 / 174, 14.5 kB |
| Inertia /tasks | 1413 / 1757, 275 kB | 672 / 988 * | 164 / 296, 14.5 kB |
| api reservations | 542 / 679, 58.5 kB, 11 q / 191 ms | 488 / 603 | 78 / 101, 11 q / 7 ms |
| api folios | 389 / 760, 17.6 kB, 7 q | 346 / 454 | 48 / 100, 7 q / 7 ms |
| api dashboard compose | 453 / 505, 10 q | 373 / 636 | 34 / 42, 10 q / 6 ms |
| api tape chart (14 days) | 592 / 1103, 35 kB, 10 q | 429 / 623 | 54 / 63, 10 q / 8 ms |
| api housekeeping board | 396 / 438, 7 q | 365 / 400 | 38 / 43, 7 q / 5 ms |
| api stock balances | 408 / 627, 6 q | 313 / 408 | 27 / 31, 6 q / 4 ms |
| api journal entries | 503 / 573, 70.7 kB, 8 q | 478 / 615 | 59 / 69, 8 q / 7 ms |
| api guests | 499 / 1299, 36.8 kB, 7 q | 398 / 507 | 48 / 78, 7 q / 5 ms |
| api payroll runs | 724 / 1090, 7 q | 384 / 430 | 42 / 50, 7 q / 6 ms |

\* a front-end build by another agent changed the asset version mid-run, so these answered 409 (Inertia version mismatch, no body); timings are still a full request cycle. The bench now re-reads the version on a 409.

## Where the time went (before)

1. **Translations on every response**: `HandleInertiaRequests` shared the whole dictionary (about 2,650 keys, 270 kB) on every Inertia visit. Removing it from visits halved the visit time on the owner's server and cut the payload 19 times.
2. **Opening a PostgreSQL connection costs 150 to 250 ms on Windows** (one process per backend; measured with plain PDO: 180 to 245 ms whatever the sslmode, 0.4 ms with a persistent connection). Every request paid it on its first query: that is most of the "query time" in the before table (about 150 to 200 ms for 7 to 11 queries; the queries themselves take under 10 ms in total).
3. **Framework boot**: 1,176 routes are registered on every request, and `php artisan route:cache` / `php artisan optimize` **failed** (two routes named `guests.index` / `guests.show`), so production could not cache routes either. Without caches the boot (providers + routes) takes 700 to 1,000 ms without opcache, 340 to 600 ms with config and route caches.
4. SQL is not the bottleneck on this data: every hot list runs 6 to 11 queries totalling 4 to 8 ms once the connection is open (see the serve-fast column). `pg_stat_user_tables` did show foreign keys without indexes scanned sequentially (pos_check_lines 20,133 sequential scans, pos_payments 1,749 with no index scan, folio_lines by folio, guest_register_entries); migration `2026_11_20_000900_add_performance_indexes` adds them with `CREATE INDEX IF NOT EXISTS` (additive, guarded, reversible). It was verified on the test database; the dev database was kept read-only, so run `php artisan migrate` to apply it there. Gains grow with volume (EXPLAIN of a folio's lines on the demo data: 15 ms by sequential scan).

## What changed

| Change | Files | Why |
|---|---|---|
| Translations are an Inertia **once prop** keyed by locale (`translations.fr` / `translations.en`): sent on the first full load and whenever the locale changes; the Inertia client keeps its copy across visits and sends `X-Inertia-Except-Once-Props`. Partial reloads keep the client copy. `useT()` is unchanged. | app/Http/Middleware/HandleInertiaRequests.php | 275 kB to 14.5 kB per visit |
| The hotels list is computed once per request (it was queried twice for `property` and `hotels`). | same | one query less per page |
| `route:cache` works: the API resource names `guests.index` / `guests.show` became `api.guests.index` / `api.guests.show` (nothing referenced them; the staff pages keep `guests.index` / `guests.show`). Closures that serialised wrongly under the route cache were replaced: the /stock group (two closures on one line returned the Route object: HTTP 500 with the cache) and the closure factories of the public booking site and careers site (now `App\Http\Controllers\PublicGroupPageController`). `php artisan route:list --json` before/after: same 1,176 routes, methods, URIs and middleware; the only differences are the two API route names. A smoke run of all 145 parameter-free GET pages under the route cache returns no 5xx. | app/Modules/Guests/routes.php, routes/web.php, routes/web/public.php, routes/web/talent.php, app/Http/Controllers/PublicGroupPageController.php | `php artisan optimize` now succeeds (production too) |
| ModuleServiceProvider skips including the 26 module route files when routes are cached, and registers module migration paths only in the console. Module discovery is a single `glob` of 26 folders (well under 1 ms): no manifest was added. | app/Platform/ModuleServiceProvider.php | less boot work per request |
| Optional persistent PostgreSQL connection: `DB_PERSISTENT=true` (default false). | config/database.php | removes 150 to 250 ms per request on Windows |
| `scripts/serve-fast.sh` / `scripts/serve-fast.cmd`: PHP built-in server with opcache + JIT and realpath cache set on the command line for that process only (no php.ini, Laragon or Apache change), `php artisan optimize` written to `storage/framework/fast/` (APP_CONFIG_CACHE / APP_ROUTES_CACHE / APP_EVENTS_CACHE) so the shared `bootstrap/cache` stays empty, and `DB_PERSISTENT=true`. | scripts/ | the "after: serve-fast" column |
| Local instrumentation `PerfDebug` (PERF_DEBUG flag, local env only) and the bench script. | app/Http/Middleware/PerfDebug.php, config/app.php, bootstrap/app.php, scripts/perf/bench.php | measuring |
| Vite: React, React DOM, scheduler and Inertia in their own `vendor-react` chunk (352 kB, 109 kB gzip); the app entry dropped from 412 kB to 60 kB, so app releases no longer invalidate the framework code in browsers. Pages were already lazy-loaded per route (`import.meta.glob`), the charts chunk (350 kB) is already loaded only by pages that draw charts, `@vite` emits modulepreload for the entry imports, and fonts already use preconnect and `display=swap`. | vite.config.js | caching across releases |
| Wordmark image resized from 1200x439 (449 kB) to 600x220 (139 kB); it is shown at most 280 CSS px wide. The brand generator now writes 600 px. | public/brand/logo-wordmark.png, scripts/build-brand-assets.php | login and landing page weight |
| Web server: Apache `.htaccess` sets `Cache-Control: public, max-age=31536000, immutable` on `/build/assets/`, a week on brand images and icons, and deflate for text types; nginx (docker and bare metal) gains `gzip_vary`, level 5, min length, more types and a week of caching for brand images (it already cached `/build/` forever). | public/.htaccess, docker/nginx/default.conf, deploy/nginx/opeshotelsoft.conf | repeat visits and transfer size |
| Regression guard `tests/Feature/Platform/QueryBudgetTest.php`: query budgets for the reservations list, folios list, dashboard compose, tape chart, housekeeping board (measured 6, 2, 6, 6, 3 with 8 reservations; budgets 10, 6, 12, 10, 6) and the Inertia shell (1 in tests; budget 6); plus translations sent on the first load, not re-sent on a visit that already holds them, and re-sent on a locale change. `QUERY_BUDGET_REPORT=1` prints the counts. | tests/ | |

## Local development: recommended settings

- Use `scripts\serve-fast.cmd 8190` (or `bash scripts/serve-fast.sh 8190`) with `PHP` set to Laragon's php.exe. Code edits are picked up within 2 seconds; after editing routes or config files, restart the script. `scripts/serve-fast.sh 8190 --no-cache` skips the artisan caches.
- `DB_PERSISTENT=true` in `.env` also helps `php artisan serve` (the CLI server is a single long-lived process). Caveat: a request that dies inside an open transaction can leave that transaction on the reused connection; keep it off for queue workers you do not restart.
- Sessions: with `SESSION_DRIVER=database` each request runs a session select and update (about 1 ms and 3 ms on a warm connection, 150+ ms more when it is the request's first query on a fresh connection). `SESSION_DRIVER=file` and `CACHE_STORE=file` locally remove those 2 to 3 queries per request (the cache table is also read on every request for the spatie permission cache). Recommended, not forced: the .env was not changed.
- Never leave `bootstrap/cache/config.php` behind on a machine that runs tests: a cached config makes `php artisan test` ignore phpunit.xml's database. serve-fast writes its caches elsewhere for that reason; run `php artisan optimize:clear` if you used `php artisan optimize` directly.

## Production

- `composer install --no-dev --optimize-autoloader` then `php artisan optimize` on every deploy (docs/DEPLOY.md; the docker entrypoint already does it). This now works.
- PHP-FPM with opcache on, `opcache.validate_timestamps=0` (reload FPM on deploy), JIT tracing with 64 MB, `opcache.max_accelerated_files=20000`, `opcache.interned_strings_buffer=16`, `realpath_cache_size=16M` (docker/php/php.ini has these except realpath).
- Redis for cache, sessions and queues (`CACHE_STORE=redis`, `SESSION_DRIVER=redis`, `QUEUE_CONNECTION=redis`) and a supervised `php artisan queue:work` (deploy/supervisor). That takes the session and permission-cache reads off PostgreSQL.
- PostgreSQL on Linux opens connections in a few ms, so persistent connections are optional there; with many FPM workers prefer PgBouncer in transaction mode.
- nginx gzip (or brotli) for HTML and JSON: the first full page carries the translations, the login page is 315 kB raw and 85 kB with gzip level 5.

## Follow-ups (not done here)

- Shared props still carry `auth.permissions` (the full permission name list) and `nav` on every visit; most of the remaining 14.5 kB. They could become once props too, but they must refresh when roles change, so it needs a version key (for example a hash of the role and permission ids). Not done to avoid stale access in the UI.
- Navigation prefetch on hover (`<Link prefetch>`) in the sidebar: Components/layout is being edited by another agent; with visits now around 150 ms the gain is small, measure before adding.
- Charts chunk (350 kB): already lazy, but pages that import the chart kit at the top level load it before first paint. Loading charts with `React.lazy` inside the pages (page files and the chart kit are owned by other agents) would let the page shell paint first.
- `public/brand/emblem.png` (193 kB, 512 px; used only above 64 px), `og-image.png` (243 kB, must stay 1200x630) and `icon-512.png` (180 kB, PWA icon size): WebP variants need a change in Components/ui/BrandLogo.tsx (owned by another agent). Palette PNG was tried and rejected: it breaks the transparency.
- Edge capture listens to every `eloquent.saved`; it only matters on writes (the bench is read only) and exits early for non-module models. Memoising the per-class decision is a small win left for a write benchmark.
- Spatie permission cache uses the database cache store locally: one `cache` table read per request. Redis in production removes it.
