# Security audit report — OpesHotelSoft, run-1 (2026-11-04)

Workstream SEC1 (skill `security-audit`, full audit mode, profile `standard`). Target `C:/laragon/www/opeshotelsoft`, not under git at audit time, live worktree. No prior ledger exists, so this is the first pass: it does not exhaust the target. Output in `docs/security-audit/` at the owner's request.

## Coverage statement

- 4 hunters (h1 tenancy/IDOR/mass-assignment/SQL, h2 authn/authz/session/config/supply-chain, h3 public surfaces/webhooks/files/PDF/XSS, h4 money logic/DoS/PII) covered 18 of 20 ledger units; 2 units are deferred and disclosed (Flutter client, host runtime hardening). `coverage-ledger.json` validates (20 units).
- 49 candidates were raised; 3 duplicates merged; 46 records in `findings.json` (validates): **42 confirmed, 4 needs_validation, 0 rejected**.
- Validation: the 19 medium candidates were re-derived by an independent verifier agent (`v1-medium`); the 10 high candidates and the lows were re-derived by the parent from source, and every high finding is additionally demonstrated by a regression test that exercises the pre-fix path (Phase 5 evidence). Local checks ran only on the scratch database `opeshotelsoft_test_sec1` with provider HTTP faked; no deployed endpoint, other project or the dev database was touched.
- Supply chain: `composer audit` 0 advisories / 0 abandoned; `npm audit` 0 vulnerabilities (prod and all). CI workflows use pinned major actions and no deploy secrets.
- Validator note: the skill validators refuse every input on Windows (no `O_NOFOLLOW`); they were run through a parent-side shim that only replaces the open flags. Both report PASS.

## Severity counts (confirmed)

| Critical | High | Medium | Low | Informational | Needs validation |
|---|---|---|---|---|---|
| 0 | 10 | 9 | 21 | 2 | 4 |

All 10 high findings were fixed in Phase 2 of this run with the smallest correct change and a regression test each (`tests/Feature/Security/Audit*Test.php`, 20 Security tests green). 8 cheap medium/low fixes were applied too; the remaining mediums and lows are listed with proposed fixes in `FIXES-TODO.md`.

## High findings, fix and proof

| # | Finding | Fix | Test |
|---|---|---|---|
| 1 | `tenancy:users:hotel-scoped-admin-grants-all-hotels`: a hotel-scoped users.manage holder (e.g. a Douala GM) could set `all_hotels=true`, add hotels outside their scope or assign group-owner to anyone including themself, gaining every hotel of the group. | `UserController::assertWithinActorScope()` on store/update: no all_hotels, no foreign hotel, no role the actor does not hold, for actors with a hotel allow-list. | `AuditTenancyTest::test_hotel_scoped_manager_cannot_escalate_to_group_wide_access` |
| 2 | `authn:web-login:suspended-status-ignored`: `/login` and the 2FA step never read `users.status`; a suspended account signed in and received an API cookie. | Status check in `AuthenticatedSessionController::store` and `TwoFactorController::verify` (same rule as `ApiTokenIssuer`). | `AuditAuthTest::test_suspended_user_cannot_sign_in_on_the_web` |
| 3 | `session:suspension-does-not-invalidate-web-sessions`: suspension only revoked Passport tokens; live sessions and remember-me cookies survived. | `HandleInertiaRequests` logs out any non-active user on the next request; `UserController::update` rotates `remember_token` and deletes the user's database sessions. | `AuditAuthTest::test_suspension_ends_a_live_web_session_and_revokes_tokens` |
| 4 | `auth:mfa:required-roles-not-enforced-on-api` (blocker #20): required roles (owner, GM, finance director, accountant) not yet enrolled got a full API token with the password alone; the web block was cosmetic. | `TwoFactor::mustEnrol()`; `ApiTokenIssuer` refuses with problem `mfa-enrolment-required` (403); `RequireMfaEnrolment` middleware (registered by `AdminModule` into the `api` group, acting only on `auth:api` routes) blocks cookie/bearer calls except `/me` and logout. Seeded demo accounts stay exempt while `DEMO_LOGINS` is on. | `AuditAuthTest::test_required_role_without_2fa_enrolment_gets_no_api_access` |
| 5 | `secrets:seeder:demo-admin-fixed-password-in-default-seeder`: `php artisan db:seed` created `admin@opeshotelsoft.test` with a published password (group-owner) in any environment. | `DatabaseSeeder` skips `DemoSeeder` in production unless `ALLOW_DEMO_SEED=true`. | `AuditAuthTest::test_default_seeder_skips_demo_accounts_in_production` |
| 6 | `public:booking:guest-profile-takeover-by-email`: the public booking engine attached a booking to any existing guest matched by e-mail (or member number) alone; the lookup then revealed that guest's loyalty number, tier, points and QR code, and the manage token could overwrite their stored ID data via digital check-in. | `BookingEngineController::book` reuses a profile only when e-mail (or member number) **and** last name match; otherwise a fresh profile is created. | `AuditPublicBookingTest` |
| 7 | `money:payments:refund-toctou-double-refund`: refundable amount and folio credit were checked on unlocked rows and the gateway was called before the lock; concurrent refunds paid out twice. | `PaymentService::refund` runs checks, provider call and posting inside one transaction with `lockForUpdate` on the intent and the folio. | `AuditMoneyTest::test_refund_is_atomic_and_rechecked_under_lock` |
| 8 | `money:payouts:process-race-double-disbursement`: `PayoutService::process` selected pending salary items without a lock and disbursed before updating status; overlapping calls paid salaries twice. | Run and items locked for the pass; each item is claimed as `sent` (attempt counted) before the provider call. | `AuditMoneyTest::test_payout_processing_is_serialised_and_destination_changes_are_controlled` |
| 9 | `money:payouts:single-role-destination-diversion`: one payments.payouts holder could reject a bank item, re-point it to any account and re-run, with no audit trail. | `updateDestination` accepts only a destination that matches the employee record (HR-owned) unless the actor holds `hr.manage`, and records `payout.item_destination_changed` with old/new/actor. | same test |
| 10 | `authz:pos:view-permission-can-settle-and-charge-rooms`: `PosController::can()` accepted `pos.view` for every write; auditors/owners (`*.view`) could open checks, settle for cash and charge any in-house room. | `pos.view` is accepted for GET only; writes need `pos.manage`. | `AuditMoneyTest::test_pos_viewer_can_read_but_not_write` |

## Medium and low fixes applied in this run (no design change)

- Edge sync: parent-row tenant check precedence fixed (`CloudSync::tenantViolation`).
- POS offline sync: quantity > 0 and payment lines validated like the online endpoints.
- Folio transfer: amount must be positive; city-ledger targets must use the credit-billing route.
- Spreadsheet formula injection neutralised in `Exporter::cell()` and `Accounting\Support\Sheets::csv()`.
- Session id regenerated before the 2FA pending state is stored.
- Password change logs out other devices and revokes API tokens.
- Guest in-stay orders: `client_uuid` deduplication scoped to the reservation.
- Public deposit `return_url` restricted to this site's host.

## Not fixed and why

- `needs_validation` (4): `DEMO_LOGINS` env gate, `trustProxies('*')`, dry-run gateway on non-production hosts, edge replication of user rows: decided by deployment facts, not source. Hardening is recommended in FIXES-TODO.md (allow-list `APP_ENV`, real proxy addresses, explicit `PAYMENTS_DRY_RUN` flag). `trustProxies` lives in `bootstrap/app.php`, which the performance workstream owns tonight, so it was deliberately left for the owner.
- Mediums needing a product or HR decision (payroll/HR segregation of duties, accounting self-approval, corporate code entropy, scheduled report ownership, webhook hotel scope) and lows needing a migration or client change (TOTP replay, edge nonce, Passport route pruning) are listed in FIXES-TODO.md with the proposed change.

## Residual risk

1. Blocker #19 stands: MTN callbacks are unsigned by the provider; the relay HMAC is the only protection.
2. IP-keyed throttles are only as good as the proxy configuration (`trustProxies('*')`).
3. Staff with `payments.payouts` can still process payouts alone (SoD on run creation vs processing is not enforced).
4. Public booking lookup by sequential confirmation + surname remains an enumeration surface (low), mitigated by throttles.
5. Erasure requests leave copies of guest names in financial records (low, documented).
6. The Flutter client and host runtime were not reviewed.
7. This is one pass over a live, concurrently edited worktree: files changed by other workstreams after a hunter read them were not re-read.

## Files

`run-metadata.json`, `architecture.md`, `coverage-ledger.json`, `findings.json`, `FINDINGS-DETAIL.md`, `NEEDS-VALIDATION.md`, `FIXES-TODO.md`, hunter and verifier outputs under `agents/*/scratch/`.

## Regression run (database opeshotelsoft_test_sec1, one folder at a time, after all fixes)

Security 20/20 (incl. the 4 new Audit*Test files, 10 tests), Platform 64/64, Payments 17/17, GuestPortal 24/24, POS 6/6, FrontOffice 25/25, Accounting 66/66, HR 65/65, Member 42/42, Documents 40/40, Edge 7/7, Tenancy 1/1, Admin 3/3, Loyalty 16/16, Guests 11/11. `php -l` clean on every changed PHP file; `php artisan list` boots. No existing test was weakened; two of the new controls were adjusted to the workflows existing tests encode (Mobile Money numbers may be corrected after a provider failure, audited; loopback return URLs allowed outside production).
