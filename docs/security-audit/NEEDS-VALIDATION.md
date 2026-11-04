# Needs validation — run-1 (2026-11-04)

Source-grounded hypotheses whose decisive fact lives outside the repository. No severity is assigned. None of these is a confirmed vulnerability.

## DEMO_LOGINS one-click sign-in (including the '*' admin) is gated by APP_ENV !== 'production' only, and demo sessions skip mandatory MFA

- Fingerprint: `config:demo-logins:env-gate-and-mfa-bypass`
- Claimed root cause: app/Http/Controllers/Auth/DemoLoginController.php:180 - Auth::guard('web')->login($user) for admin or any DemoUsersSeeder::USERS login; session 'demo_login'=true

Any non-'production' APP_ENV string with DEMO_LOGINS=true (common on staging that holds copied real data) grants anonymous users an admin session; MFA enrolment is skipped for that session

**Blockers.**
- Decisive fact is outside source

**Validation plan.** Local: Review the deployment environment values (APP_ENV, DEMO_LOGINS, TRUSTED_PROXIES, payment gateway credentials) on every internet-facing instance and confirm the gate holds; no code path change is needed for validation. Deployment: Owner confirms the production and staging environment files and the edge proxy configuration.

**Trace.**
- entrypoint: `routes/web.php:18` — POST /demo-login/{login} (guest middleware, throttle:20,1, CSRF)
- propagation: `config/app.php:22` — env('DEMO_LOGINS', false) && env('APP_ENV') !== 'production' - any other APP_ENV string (staging, prod, uat, demo) passes
- propagation: `app/Http/Controllers/Auth/DemoLoginController.php:29` — abort_unless(config('app.demo_logins'),404); login name must be 'admin' or a DemoUsersSeeder::USERS key (line 30)
- propagation: `app/Http/Middleware/HandleInertiaRequests.php:125` — demo_login session flag (set at DemoLoginController line 39) disables mandatory MFA enrolment while the flag is on
- sink: `app/Http/Controllers/Auth/DemoLoginController.php:36` — Auth::guard('web')->login($user) - admin@opeshotelsoft.test (group-owner, role '*') without password or MFA

---

## Outside APP_ENV=production, providers without credentials fall back to DryRunGateway whose status() auto-succeeds: the public status poll settles a deposit/pay-link as paid with no money

- Fingerprint: `public:paylink:dryrun-autosucceed-nonprod`
- Claimed root cause: app/Modules/Payments/Application/PaymentService.php:144 - refresh(): $status from DryRunGateway::status() (Drivers/DryRunGateway.php line 158-162 returns 'succeeded') -> settle() posts the folio payment and increments deposit_paid

Any staging/demo/UAT deployment (APP_ENV=staging, demo, local) lets guests mark bookings as paid; a production host misconfigured with APP_ENV != production is fully exposed.

**Blockers.**
- Decisive fact is outside source

**Validation plan.** Local: Review the deployment environment values (APP_ENV, DEMO_LOGINS, TRUSTED_PROXIES, payment gateway credentials) on every internet-facing instance and confirm the gate holds; no code path change is needed for validation. Deployment: Owner confirms the production and staging environment files and the edge proxy configuration.

**Trace.**
- entrypoint: `app/Modules/Payments/Http/PublicPaymentController.php:55` — public GET status with manage token -> payments->refresh($intent) (line 61); same via PublicPayLinkController::status line 50-56
- propagation: `app/Modules/Payments/Application/PaymentService.php:42` — no credentials: throws only when app()->isProduction(); otherwise returns new DryRunGateway (line 46); driverOf (line 59) also returns DryRun for any intent stored with driver='dry_run'
- propagation: `app/Modules/Payments/Application/Drivers/DryRunGateway.php:37` — returns 'succeeded' (or 'failed' for the simulated-failure case)
- propagation: `app/Modules/Payments/Application/PaymentService.php:144` — non-pending status -> settle($intent, $status)
- sink: `app/Modules/Payments/Application/PaymentService.php:224` — folios->payment(...) posts the folio payment line and Reservation deposit_paid is incremented (line 230-231) with no money received

---

## trustProxies('*') lets clients choose $request->ip(), defeating every IP-keyed throttle and the email+IP login guard

- Fingerprint: `rate-limit:trust-proxies-star-xff-spoof`
- Claimed root cause: app/Modules/Admin/Application/LoginGuard.php:178 - RateLimiter key 'login:'.email.'|'.$ip — attacker rotates ip per request

All IP-based limits are bypassable; residual protection is the per-account lock (10 failures -> 15 min), i.e. ~40 password guesses/hour/account for staff, and unlimited attempts against endpoints with no per-account counter (public booking lookup by confirmation+last name, /verify/{code}, OTP send floods, audit-log noise). Audit logs also record attacker-chosen IPs

**Blockers.**
- Decisive fact is outside source

**Validation plan.** Local: Review the deployment environment values (APP_ENV, DEMO_LOGINS, TRUSTED_PROXIES, payment gateway credentials) on every internet-facing instance and confirm the gate holds; no code path change is needed for validation. Deployment: Owner confirms the production and staging environment files and the edge proxy configuration.

**Trace.**
- entrypoint: `bootstrap/app.php:20` — $middleware->trustProxies(at: '*') with default headers - X-Forwarded-For from any peer is honoured for $request->ip()
- propagation: `app/Modules/Admin/Application/LoginGuard.php:28` — 'login:'.email.'|'.$ip - email+IP throttle key; attacker rotates the XFF value per request
- propagation: `app/Platform/ModuleServiceProvider.php:31` — public-write (20/min), public-read (300/min) and api limiters keyed by $r->ip()
- propagation: `app/Modules/Admin/AdminModule.php:22` — auth-token limiter keyed by ip|route
- propagation: `app/Modules/MemberPortal/Application/MemberAuthService.php:283` — member-login limiter identifier|ip
- sink: `routes/web.php:19` — throttle:10,1 on POST /login (and two-factor-challenge line 23) keyed by IP becomes per-attacker-chosen string; residual control is the per-account lockout in LoginGuard

---

## Edge pull sends every group-level row (hotel_id null) incl. users rows with password/pos_pin hashes and encrypted MFA secrets of all hotels' staff to each node

- Fingerprint: `tenancy:edge:pull-replicates-group-users-with-secrets`
- Claimed root cause: app/Modules/Edge/Application/ChangeFeed.php:30 - RowStore::read returns the full raw row (no $hidden filtering) for each change

Operator of hotel A's edge box can dump users of hotel B (emails, bcrypt hashes, recovery codes) and attempt offline cracking; not verified whether ChangeCapture records users at all (only capture of models with group_id)

**Blockers.**
- ChangeCapture coverage of the users table was not established from source; whether user rows (password hashes, encrypted mfa secrets) are ever written into the change feed decides the finding.

**Validation plan.** Local: Local: run the Edge pull test fixture on a scratch database after creating a user of another hotel and inspect the feed rows for the users table. Deployment: Owner confirms the production and staging environment files and the edge proxy configuration.

**Trace.**
- entrypoint: `app/Modules/Edge/Application/CloudSync.php:89` — pull(): edge_changes where hotel_id = node.hotel_id OR (hotel_id null AND group_id = node.group_id)
- propagation: `app/Modules/Edge/Application/TablePolicy.php:22` — 'users' is a replicated master table; users has group_id only, so its changes are group-level
- propagation: `app/Models/User.php:10` — columns password, pos_pin (hashed), mfa_secret (encrypted with APP_KEY), mfa_recovery_codes
- sink: `app/Modules/Edge/Application/ChangeFeed.php:30` — RowStore::read returns the full raw row (no $hidden filtering) for each change

---

## Sandbox limitation (disclosed)

The skill validators (`validate-findings.cjs`, `validate-coverage-ledger.cjs`) refuse all input on Windows because Node exposes no `O_NOFOLLOW`/`O_NONBLOCK`. They were run through a parent-side shim that substitutes a plain read-only open for parent-authored files; every content rule ran unchanged and both report PASS. Local checks ran only on the scratch database `opeshotelsoft_test_sec1` with faked provider HTTP; no deployed endpoint was probed.
