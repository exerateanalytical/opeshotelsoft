# Fixes to do — medium and low findings (run-1, 2026-11-04)

Every confirmed critical/high finding was fixed in this run (see REPORT.md). This file lists the confirmed medium and low findings with the smallest fix. Items marked **done** in run-1 were cheap and obviously safe. Workstream SEC2 (same night) implemented the remaining safe ones with least-privilege defaults where a product decision was missing; each default is written in the "SEC2 defaults" section below. Regression tests: `tests/Feature/Security/Fix2AccessTest.php`, `Fix2PublicMoneyTest.php`, `Fix2MemberTest.php`. Schema change: one additive migration `database/migrations/2027_02_01_000100_sec2_hardening_columns.php`.

## Medium

| Fingerprint | Proposed fix | Status |
|---|---|---|
| `authz:scheduled-reports:runs-as-creator-after-edit-by-other-user` | In `ScheduledReportController::update()` re-own the report (`created_by = actor`) or forbid editing another user's report; in the runner execute as the actor who pressed Run. | **done** (SEC2: the editor becomes the owner; the runner always runs as the owner, also for Run now) |
| `tenancy:webhooks:hotel-id-not-checked-against-allowed-hotels` | `WebhookController::store/update`: validate `hotel_id` with `Rule::exists('hotels','id')->where('group_id', …)`, require it to be in `allowedHotelIds()` when that list is not null, and disallow null (whole group) for hotel-scoped users. | **done** (SEC2; there is no update endpoint) |
| `tenancy:edge:child-row-parent-check-accepts-sister-hotel` | `CloudSync::tenantViolation`: parenthesise the parent check so a parent with a `hotel_id` must match the node's hotel (group match only when the parent has no hotel_id). | **done** |
| `public:booking:corporate-code-low-entropy-bills-company` | Generate corporate booking codes server-side (>= 64 bits) instead of staff-chosen short strings; throttle `corporate_code` lookups per IP+code; optionally require the company e-mail domain. Product decision. | **done** (SEC2: `PREFIX-` + 13 random base-32 characters = 65 bits; existing codes keep working; throttle 10/min per IP+code and 20 failed lookups/hour per IP, 429 beyond; e-mail domain check not done) |
| `business-logic:pos:sync-bypasses-line-and-payment-validation` | In `PosController::sync` validate `items.*.quantity` `min:0.001`, `payments.*.method` required string, `payments.*.amount` integer `min:1`. | **done** |
| `business-logic:folio:transfer-bypasses-city-ledger-credit-limit-and-sign` | `FolioController::transfer`: `amount` `min:1`; refuse `to->kind === 'city_ledger'` (route through `transferToCityLedger`). | **done** (amount sign and city-ledger target) |
| `race:folio:refund-check-on-stale-balance` | Move the credit-balance check for `type=refund` into `FolioService::post()` under the folio row lock (same pattern as the payment refund fix). | **done** (SEC2: re-checked on the locked row; the early check stays for a fast answer) |
| `authz:payroll:sod-check-only-against-original-creator` | Record `computed_by` on every compute; refuse approval when `prepared_by` or `computed_by` equals the actor. | **done** (SEC2; new column `payroll_runs.computed_by`) |
| `authz:hr:self-modification-of-own-salary-bank-and-advances` | Reuse `LifecycleService::assertSod`: abort in `updateEmployee` (salary/bank/momo fields), `grantAdvance` and `SelfService::decide` when `$employee->user_id === actor->id`. | **done** (SEC2; `assertSod` is now public; also covers allowances and payment method) |

## Low

| Fingerprint | Proposed fix | Status |
|---|---|---|
| `export:csv:formula-injection` | `Exporter::cell()` and `Accounting\Support\Sheets::csv()`: prefix strings starting with `= + - @ \t \r` with a single quote. | **done** |
| `session:2fa-pending-state-stored-before-session-regenerate` | `AuthenticatedSessionController::store`: `session()->regenerate()` before storing `2fa.user_id`; expire the pending state after 5 minutes. | **done** (regenerate) |
| `session:password-change-keeps-other-sessions-and-tokens` | After a password change: `Auth::logoutOtherDevices($password)` and revoke Passport tokens. | **done** |
| `authn:totp:no-replay-protection` | Store the last accepted TOTP step on the user and reject steps <= that value. | **done** (SEC2; column `users.mfa_last_step`, atomic update; applies to sign-in checks, not to the enrolment confirmation code) |
| `authz:passport:oauth-management-routes-open-to-all-staff` | `Passport::ignoreRoutes()` in `AppServiceProvider::register()` and register only `POST /oauth/token` (and refresh) with explicit throttle; or wrap client management under `can:integrations.manage`. | **done** (SEC2: only `POST /oauth/token` (throttle kept) and `POST /oauth/token/refresh`; authorize and device routes removed. Passport 13 has no client-management routes) |
| `authz:tenancy:hotels-and-staff-directory-without-permission` | `HotelController@index/show`: limit columns to id/code/name/city for users without `hotels.view`; `StaffController`: limit to id/name. | **done** (SEC2; see defaults) |
| `tenancy:guests:export-and-merge-ignore-allowed-hotels` | Reuse the scoped helper (group + allowed hotels) in `GuestController::export()`; restrict `merge()` re-pointing to allowed hotels. | **done** (SEC2; see defaults) |
| `idor:booking-api:sequential-confirmation-plus-surname-yields-manage-token` | Prefer the 8-char `public_code` for lookups; add a per-confirmation failure counter; stop returning loyalty points from `lookup()`. | todo (public site UX; not in the SEC2 scope) |
| `tenancy:memberportal:phone-suffix-match-links-other-guest` | Match on the full E.164 number; when several guests match, do not auto-link. | **done** (SEC2) |
| `authn:member:register-overwrites-pending-account` | Do not overwrite names of an existing pending account; add a per-contact daily OTP cap. | **done** (SEC2; cap 10 codes per contact per day, all purposes) |
| `public:checkin:id-photo-no-magic-check` | Reuse the finfo-based mime check from `FrontDeskExtrasController` in `DigitalCheckin::storePhoto`. | **done** (SEC2; existing 2.5 MB data-URL cap kept) |
| `public:guest-order:client-uuid-cross-reservation` | Add `->where('reservation_id', $r->id)` to the client_uuid lookup. | **done** |
| `public:channels:sandbox-unsigned-inbound` | Generate a webhook secret at connection creation and require it for every inbound booking, sandbox included. | **done** (SEC2; column `channel_connections.inbound_secret`, generated for existing connections by the migration) |
| `public:deposit:return-url-forwarded-to-provider` | Validate `return_url` host against `config('app.url')` (or accept paths only). | **done** |
| `authn:edge-sync:signed-request-replay-window` | Add a nonce header to the signature and reject (node, nonce) seen within the skew window. | skipped (needs edge client changes: nodes must send the nonce first) |
| `pii:hr:employee-model-returns-bank-tax-ids-to-view-roles` | Hide `bank_account`, `momo_number`, `cnps_number`, `tax_number` for users without `hr.manage`/`payroll.manage` (API resource or `$hidden` + explicit endpoint). | **done** (SEC2; `Employee::toArray()`; the 360 profile hides CNPS and tax numbers from hr.view-only users too) |
| `dos:accounting:general-ledger-unbounded-date-range` | Cap the general-ledger range (366 days) and paginate lines. | **done** (SEC2; `page`/`per_page`, default and max 5,000 lines) |
| `race:folio:open-number-sequence-outside-transaction` | Wrap `FolioService::open()` (and `PosService::open`) in a transaction; lock the reservation row in `guestFolioFor`. | **done** (SEC2) |
| `authz:accounting:manual-entry-self-approved-and-reverse-system-entries` | Leave `approved_by` null on post; restrict `reverse()` to manual/reversal sources; approval step with `accounting.approve`. | skipped by SEC2 (redesign already analysed in docs/ACCOUNTING-CONTROLS.md, accounting workstream) |
| `business-logic:loyalty:unbounded-manual-point-credit` | Per-adjustment cap in the programme settings; second approver above it. | **done** (SEC2; cap `loyalty_programs.max_manual_adjustment`, default 5,000, permission `loyalty.adjust_large` above it instead of a second approver) |
| `retention:guests:erasure-does-not-cover-financial-and-payment-copies-of-personal-data` | Extend `PrivacyService::anonymise()` to folios.name, invoice bill-to (no company), payment intents/links payer fields, online check-ins. | **done** (SEC2; amounts, accounts, numbers untouched) |

## Needs validation (deployment facts)

See NEEDS-VALIDATION.md: `config:demo-logins:env-gate-and-mfa-bypass`, `rate-limit:trust-proxies-star-xff-spoof`, `public:paylink:dryrun-autosucceed-nonprod`, `tenancy:edge:pull-replicates-group-users-with-secrets`.

SEC2 hardening done regardless of the deployment facts:
- `trustProxies('*')` removed. Trusted proxies come from env `TRUSTED_PROXIES` (config/trustedproxy.php); default none, so `X-Forwarded-For` is ignored unless configured. `docker-compose.yml` sets the private Docker ranges for the app container (nginx is only published on 127.0.0.1), `.env.production.example` sets `127.0.0.1`, docs/DEPLOY.md explains it.
- `DEMO_LOGINS` refuses to enable when `APP_ENV=production` unless `ALLOW_DEMO_LOGINS_IN_PRODUCTION=true` is also set; a warning is logged either way when DEMO_LOGINS meets production.
- Not done: `DryRunGateway` behind a `PAYMENTS_DRY_RUN` flag, and the edge pull of user rows (both outside the SEC2 list).

## SEC2 defaults (least privilege, reversible)

- Scheduled reports: editing a report makes the editor its owner (the report then runs with the editor's rights). Alternative not chosen: forbid editing another user's report.
- Webhooks: a hotel-scoped user must name one of their hotels; only group-wide users (all hotels) may create a whole-group subscription.
- Corporate codes: the code typed by staff is only a prefix (8 characters at most, letters and digits); the full code is shown in the contract list and must be given to the company. Old codes such as `SONARA` keep working until the contract is replaced.
- Payroll: whoever last computed a run, and whoever first prepared it, cannot approve it.
- HR: nobody changes their own salary, allowances, payment method, bank or Mobile Money details, grants themselves an advance or decides their own self-service request (also a general manager linked to an employee record).
- TOTP: a sign-in code is accepted once; the enrolment confirmation code does not burn the step (so the first sign-in right after enrolment still works with the same code).
- Passport: the authorization-code and device-code screens are gone; no client of this platform used them. To bring them back remove `Passport::ignoreRoutes()` from AppServiceProvider.
- Hotels list without `hotels.view`/`hotels.manage`: id, code, name, city. The single-hotel read also returns the identity printed on invoices (legal name, tax id, trade register, address, phone, e-mail) so cashiers can still print. Staff directory without `users.view`/`users.manage`: id and name (no roles); the role filter still works.
- Guest export by a hotel-scoped user: only reservations of their hotels. Merge by a hotel-scoped user: refused when either profile has history (reservations, folios, invoices, register, POS, lost and found, waitlist) in another hotel; a group-wide user merges.
- Member portal: a phone sign-up links an existing profile only when exactly one profile has the same full number (local numbers get the group's CEMAC calling code, Cameroon by default); otherwise a new profile is created and staff merge later. A repeated sign-up never renames the pending account; at most 10 one-time codes per contact per day.
- Channels: every inbound booking must be signed (X-Signature HMAC-SHA256 of the body) with the provider's `webhook_secret` or, when none is set, the connection's generated inbound secret. The generated secret is returned once in the create response; existing connections got a new secret from the migration that nobody has seen yet, so the owner must set `webhook_secret` in the connection credentials (or rotate) before sandbox traffic is accepted again.
- Employee API: bank account, Mobile Money, CNPS and tax numbers are left out of employee JSON unless the actor holds `hr.manage` or `payroll.manage` or is the employee. Payroll, payouts and documents read the attributes directly and are unaffected.
- General ledger API: at most 366 days per request; `per_page` default and maximum 5,000 lines; `closing` is the balance after the returned page (equal to the period closing on the last page); `has_more` and `total_lines` tell the client to page.
- Loyalty: one manual adjustment above `max_manual_adjustment` (default 5,000 points, settable with PUT /loyalty/program) needs `loyalty.adjust_large`. Only roles with every permission (group-owner, general-manager) hold it by default; run the permission seeder on existing databases to create it.
- Erasure: folio names of the guest's own folios, bill-to of invoices without a company, payer phone and e-mail of payment intents and links of the guest's folios, reservations and invoices, and the identity fields and ID photo of online check-ins are cleared. Amounts, accounts, document numbers and verify codes are kept.
