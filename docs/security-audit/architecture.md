# Architecture map (security view) — run-1, 2026-11-04

Target: `C:/laragon/www/opeshotelsoft` (Laravel 13.39, PHP 8.3, PostgreSQL 16, Inertia React TS, Passport 13.10, spatie/permission, dompdf). Not a git repository at audit time; worktree is live (other agents editing UI files). Profile `standard`, no prior ledger.

## Trust boundaries

| # | Lower-trust principal | Enters through | Intended control |
|---|---|---|---|
| B1 | Anonymous internet | `app/Modules/*/public-routes.php` (`/api/v1`, middleware `api`,`idempotent`), `routes/web/public.php` (`/book/{group}`), `routes/web/landing.php`, `talent.php` (careers), `member.php` (`/account/*` public steps), `/login`, `/demo-login/*`, `/verify/*`, pay links, webhooks | Throttles `public-read` 300/min/IP, `public-write` 20/min/IP+uri, `auth-token` 10/min; HMAC manage tokens, pay-link signatures, provider callback signatures, edge node HMAC; OTP codes for members |
| B2 | Authenticated staff (Passport bearer or `laravel_token` cookie) | `app/Modules/*/routes.php` mounted with `api`,`auth:api`,`tenant`,`idempotent` (now also `RequireMfaEnrolment`) | spatie permissions checked in controllers (`abort_unless($user->can(...))` helpers), `can:` middleware on Inertia pages |
| B3 | Staff of hotel A vs hotel B (same group) | `X-Hotel-Id` header -> `ResolveTenant` -> `TenantContext` | `BelongsToHotel` global scope (`group_id` + `hotel_id`/allowed list); `allowedHotelIds()` null = all hotels |
| B4 | Group A vs group B | same | `BelongsToGroup` scope; `withoutScope()` for platform jobs only |
| B5 | Loyalty member (session guard `member`, `MemberAccount`) | `/account/*` with `MemberAuth` middleware | all reads keyed on `guest_id`; separate guard from staff |
| B6 | Edge node (hotel appliance) | `app/Modules/Edge/public-routes.php` | per-node HMAC, `TablePolicy` column whitelist, tenant check per row |
| B7 | Third-party providers (MTN, Orange, card, WhatsApp, OTAs) | Payments/Notifications/Channels public routes | per-provider signature (`hash_equals`), replay dedupe by body hash; MTN unsigned by design (blocker 19) |
| B8 | Operator / deployment | `.env`, seeders, docker, deploy | `APP_ENV` gates (`demo_logins`, dry-run gateways, HSTS) |

## Key mechanisms (verified from source)

- `bootstrap/app.php`: `trustProxies('*')`, `SecurityHeaders` (CSP nonce, frame-ancestors self, HSTS in prod), `CreateFreshApiToken` on web (cookie-authenticated API with Passport CSRF header check), `ProblemDetails`/`ErrorPages` (no message leak).
- `app/Platform/ModuleServiceProvider.php`: route mounting and rate limiters.
- `app/Platform/Tenancy/*`: context, scopes, `ResolveTenant`.
- `app/Modules/Admin`: `ApiTokenIssuer` (password grant with LoginGuard throttle/lockout and TOTP challenge), `TwoFactor` (required roles: group-owner, general-manager, finance-director, accountant), `LoginGuard`.
- `app/Http/Controllers/Auth/*`: web login, 2FA challenge, demo logins (`DEMO_LOGINS` and `APP_ENV !== production`).
- `app/Modules/GuestPortal`: public booking engine, `PublicBookingResolver` (public_code / confirmation + last name), HMAC manage tokens bound to group|hotel|id.
- `app/Modules/Payments`: intents, callbacks, pay links (signed), refunds, payouts (salary disbursement), `DryRunGateway` when credentials missing and not production.
- `app/Modules/Documents`: `TemplateRenderer` (escaped placeholders, no Blade compile), `Exporter` (xlsx/csv), dompdf on vendor defaults (remote off, chroot).
- `app/Modules/Edge`: `CloudSync` (HMAC, 300 s skew), `RowStore`, `TablePolicy`.

## Local build/test path

`DB_DATABASE=opeshotelsoft_test_sec1 php artisan test tests/Feature/<Folder>/` (PHP at `C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64`); `RefreshDatabase` on a scratch database created for this run. No external network: provider HTTP is faked in tests.

## Coverage units

See `coverage-ledger.json` (20 units: 18 covered/candidate across four hunters, 2 deferred: Flutter client, host runtime hardening).
