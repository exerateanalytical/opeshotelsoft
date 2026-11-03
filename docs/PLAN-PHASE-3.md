# Plan: phase 3 — close every code gap (mobile app excluded)

Scope: everything still missing that code can fix. Out of scope: the mobile app (deferred by the owner), and items that need outside parties (accountant/payroll sign-off, provider keys, partner certification, pen test, door-lock and biometric vendor specs, fiscal e-invoicing spec).

## Hard rules (same as phase 2)
- Max 6 agents at once. Each owns the files listed; anything else is read-only.
- New web routes go in `routes/web/<ws>.php`; new permissions in `database/seeders/permissions/<ws>.php`; new UI strings in `lang/{fr,en}/<ws>.json` (identical keys).
- Every staff route has a `can:` guard; new tenant tables use `BelongsToHotel`/`BelongsToGroup`; no DELETE on financial records; no cross-module `Http` imports.
- Money flows post balanced GL entries and tests assert the trial balance.
- Flat UI: no glow, shadows, gradients; existing font sizes.
- Tests run on the agent's own database: `DB_DATABASE=opeshotelsoft_test_<ws> php artisan test --filter=<YourTests>` (create it if missing). Do not run the full suite; the lead does.
- No new heavy dependencies without stating why in the report.

## Wave 1

| WS | Deliverable | Owns | Done when |
|---|---|---|---|
| P1 Loyalty cards | Printable/digital card with QR (PDF + page), card block/replace/expire, POS earn & redeem by card scan or member search (points as a tender, balanced GL), promotion rules (multiplier by date/outlet, birthday bonus, enrol bonus), member points statement (PDF + email via Notifications), staff screens FR/EN | `app/Modules/Loyalty/**`, `resources/js/Pages/Loyalty/**`, loyalty hooks in POS via a Loyalty application service called from POS (smallest possible edit in `PosService` settle path), `routes/web/loyalty.php`, `permissions/p1.php`, `lang/*/loyalty.json`, `tests/Feature/Loyalty/**` | ≥10 tests: issue/block/replace, scan lookup, POS earn, POS redeem with GL balance, promo multiplier, birthday bonus, expiry, statement PDF, tenant isolation |
| P2 Auth hardening | 2FA on API password grant/token issue (challenge step, recovery codes), login throttling, security headers middleware (CSP, HSTS in prod, X-Frame-Options, Referrer-Policy), session hardening, password policy, account lockout + audit events | `app/Modules/Admin/**` auth parts, `app/Http/Middleware/SecurityHeaders.php`, `bootstrap/app.php` (middleware registration only), `config/auth.php`, `tests/Feature/Security/**` | Tests: API token refused without OTP for 2FA users, accepted with OTP/recovery code, throttle 429, headers present, lockout logged |
| P3 Server-side translation | Laravel translator loads `lang/<locale>/*.json`; translate server-sent labels: cash-flow lines, DSF lines, report titles/exports, validation & problem+json messages, PDF documents for guest language, notification templates defaults | `app/Providers/AppServiceProvider.php` (translator loading only), `lang/{fr,en}/server.json`, label sources in `Accounting/Application/FinancialStatements.php`, `Reports/**`, `Documents/**`, `app/Exceptions` / problem renderer, `lang/fr/validation.php` etc. | Test: same endpoint returns FR then EN labels by `Accept-Language`/user locale; parity test passes |
| P4 Quality & CI | GitHub Actions (PHP 8.3 + Postgres 16 service: composer install, migrate, full tests; node: tsc + build), Pint/ESLint config, raise thin modules to ≥5 tests each: Housekeeping, Maintenance, Booking engine, Reports, Loyalty base | `.github/**`, `tests/Feature/Housekeeping/**`, `tests/Feature/Maintenance/**`, `tests/Feature/GuestPortal/BookingEngineMoreTest.php`, `tests/Feature/Reports/**`, `pint.json`, eslint config | Workflow file valid; new tests pass; bugs found are fixed in the smallest edit and listed |
| P5 Production operations | Docker (php-fpm + nginx + postgres + redis + queue worker + scheduler), `docs/DEPLOY.md`, health endpoint (`/up` + DB/queue/redis checks), structured logging, daily encrypted DB backup command with retention + restore drill doc, Supervisor/systemd units, `.env.production.example`, rate limits on public API | `docker/**`, `docker-compose.yml`, `Dockerfile`, `deploy/**`, `docs/DEPLOY.md`, `app/Modules/Platform/Ops/**`, `routes/console.php` (append only), `tests/Feature/Ops/**` | `docker compose config` valid; health + backup commands tested |
| P6 Hotel edge node | Offline-first hotel server (SPEC §7.5a): same app in `EDGE_MODE`, local Postgres, change-log capture per tenant table, push/pull sync API with cursors, idempotent replay, conflict rules (server-wins for finance/master data, last-writer for operational status), sync status screen, docs | `app/Modules/Edge/**`, `routes/web/edge.php`, `permissions/p6.php`, `lang/*/edge.json`, `resources/js/Pages/Edge/**`, `docs/EDGE.md`, `tests/Feature/Edge/**` | Tests: offline check-in + POS sale on edge sync to cloud exactly once; conflict rules; cursor resume after interruption; tenant isolation |

## Wave 2 (after wave 1 is verified)

| WS | Deliverable |
|---|---|
| P7 SSO | OIDC (and SAML via OIDC bridge note) login per hotel group, JIT user with role mapping, tests with a fake IdP |
| P8 Browser flow tests | Playwright end-to-end: booking → check-in → POS charge → check-out → invoice; purchasing; payroll run; per-role menu smoke |

## Lead verification
Full `php artisan test`, `tsc`, `npm run build`, architecture + RBAC matrix, browser check of every new screen in FR and EN, update the certification register and BLOCKERS, commit and push.

## Wave 2 (replaces SSO/browser tests, which move to wave 3) — owner decision 2026-11-03

Goal: features that set OpesHotelSoft apart in CEMAC — simple, solves real pain. Same hard rules as wave 1. Max 6 agents at once (Q6 starts when P5 finishes).

| WS | Deliverable | Owns |
|---|---|---|
| Q1 Onboarding & branding | Setup wizard (group → hotel → rooms/types → rates → outlets → taxes → users → go-live checklist), letterhead per hotel (logo, colours, NIU, RCCM, address, bank, footer) used by every PDF, document-template editor (invoice, quote, registration card, confirmation, letters) with safe placeholders and preview, Excel/CSV import (rooms, guests, staff, opening balances, stock items), copy setup hotel → hotel | `app/Modules/Onboarding/**`, `app/Modules/Documents/**` (letterhead + templates), hotel branding columns (new migration), `resources/js/Pages/Onboarding/**`, `routes/web/onboarding.php`, `permissions/q1.php`, `lang/*/onboarding.json`, `tests/Feature/Onboarding/**` |
| Q2 HR employment lifecycle | E-contracts from templates (CDI/CDD/stage), e-signature on phone (OTP + drawn signature, signed PDF with hash and audit trail), raises & promotions with approval and history, bonuses/allowances (one-off, recurring, service-charge share) feeding payroll, transfers between hotels keeping seniority | `app/Modules/HR/**` (contracts, compensation, transfers), `resources/js/Pages/HR/**`, `routes/web/hr.php` (append), `permissions/q2.php`, `lang/*/hr2.json`, `tests/Feature/HR/Lifecycle*` |
| Q3 People performance | Objectives & reviews (cycles, self + manager, ratings), guest-feedback links to staff, general task assignment (to person/team, due, checklist, photo proof), discipline (warnings, sanctions), exit (end-of-contract settlement calc, work certificate PDF) | new `app/Modules/People/**`, `resources/js/Pages/People/**`, `routes/web/people.php`, `permissions/q3.php`, `lang/*/people.json`, `tests/Feature/People/**` |
| Q4 Owner view & leakage alerts | Owner daily digest (WhatsApp/email via Notifications), mobile-friendly owner page, anomaly rules: voids, comps, discounts, room moves without charge, occupied-not-billed, cash variance, stock vs sales gap at bars, late check-outs not charged, rate overrides; thresholds per hotel; alert inbox with acknowledge | new `app/Modules/Insights/**`, `resources/js/Pages/Insights/**`, `routes/web/insights.php`, `permissions/q4.php`, `lang/*/insights.json`, `tests/Feature/Insights/**` |
| Q5 Mobile Money everywhere | Pay-by-QR on folio, POS bill, invoice, booking (link + QR, MTN/Orange), automatic matching of incoming MoMo/bank statement lines to bills, salary payout run (MoMo bulk + bank file) from approved payroll with balanced GL | `app/Modules/Payments/**`, `resources/js/Pages/Payments/**`, `routes/web/payments.php` (append), `permissions/q5.php`, `lang/*/payments2.json`, `tests/Feature/Payments/**` new files |
| Q6 WhatsApp guest journey | Confirmation, pre-arrival with online check-in link, in-stay menu ordering to POS, bill and pay link, post-stay review request; two-way inbox for staff; FR/EN; template registry for Meta approval | `app/Modules/Notifications/**` (WhatsApp), `app/Modules/GuestPortal/**` (ordering), `resources/js/Pages/Messaging/**`, `routes/web/messaging.php`, `permissions/q6.php`, `lang/*/messaging.json`, `tests/Feature/Messaging/**` |

Wave 3: SSO (OIDC), Playwright end-to-end flows, expired-points GL release (accountant to confirm), POS settle-screen points button.
