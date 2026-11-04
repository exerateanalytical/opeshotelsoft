# Findings detail — run-1 (2026-11-04)

Derived from `findings.json` (46 records: 42 confirmed, 4 needs_validation, 0 rejected; 3 hunter duplicates merged). Records marked FIXED were changed in Phase 2 of this run and carry a regression test; the record keeps the pre-fix source lines as evidence.

## [HIGH] Mandatory 2FA for sensitive roles is enforced only by the Inertia web middleware; API access via bearer token or laravel_token cookie bypasses enrolment

- Fingerprint: `auth:mfa:required-roles-not-enforced-on-api`  — **FIXED in this run** (test: `tests/Feature/Security/AuditAuthTest.php`)
- Likelihood medium / impact high; confidence high
- Intended behaviour: TwoFactor::REQUIRED_ROLES must enrol before using the application (HandleInertiaRequests::MFA_ALLOWED)
- Root cause: app/Http/Middleware/HandleInertiaRequests.php:36 - mustEnrol() redirect runs only inside the web middleware group; api group never checks TwoFactor::required()

**Description.** Unenrolled required-role users obtain full API access with password only; the web UI block is cosmetic

**Trace.**
- entrypoint: `app/Modules/Admin/public-routes.php:7` (routes) — POST /api/v1/auth/token (unauthenticated)
- propagation: `app/Modules/Admin/Application/ApiTokenIssuer.php:45` (ApiTokenIssuer::authenticate) — A code is demanded only when the user already enrolled; required-but-unenrolled roles get a token with the password alone
- sink: `app/Http/Middleware/HandleInertiaRequests.php:36` (HandleInertiaRequests::handle) — The enrolment block only redirects Inertia pages; API calls with the laravel_token cookie or a bearer token are not blocked

**Conditions.** authorization_role: required-role user not yet enrolled; data_state: attacker has the password or the user wants to skip MFA

**Reproduction (local fixture only).** A group-owner / general-manager / finance-director / accountant who has not enrolled in TOTP (or whose password alone was phished)
1. POST /api/v1/auth/token (and /api/v1/auth/login) issues a Passport token with password only when the user is not enrolled
1. mustEnrol() redirect runs only inside the web middleware group; api group never checks TwoFactor::required()

Observed/expected: Unenrolled required-role users obtain full API access with password only; the web UI block is cosmetic

**Remediation.** In ApiTokenIssuer::authenticate refuse (403 mfa-required) when TwoFactor::required($user) && !enabled($user); add an api-group middleware that returns 403 for the same condition on cookie-authenticated requests (except /me and profile endpoints)

---

## [HIGH] Suspended staff accounts can still sign in through the web session login

- Fingerprint: `authn:web-login:suspended-status-ignored`  — **FIXED in this run** (test: `tests/Feature/Security/AuditAuthTest.php`)
- Likelihood high / impact high; confidence high
- Intended behaviour: UserController@update sets status='suspended' and revokes Passport tokens (app/Modules/Tenancy/Http/UserController.php:81-83) intending to lock the person out
- Root cause: app/Http/Controllers/Auth/AuthenticatedSessionController.php:45 - Auth::guard('web')->login($user) after Auth::guard('web')->validate($credentials) (line 31); neither checks $user->status

**Description.** A suspended user (or anyone holding their password) logs in at /login, receives a web session and a Passport cookie, and keeps full access to pages and /api/v1; only the bearer-token path is closed

**Trace.**
- entrypoint: `routes/web.php:19` (routes) — POST /login (guest, throttle 10/min)
- propagation: `app/Http/Controllers/Auth/AuthenticatedSessionController.php:31` (AuthenticatedSessionController::store) — Credentials validated with Auth::validate; user status is never read
- sink: `app/Http/Controllers/Auth/AuthenticatedSessionController.php:45` (AuthenticatedSessionController::store) — Auth::login() for a suspended user; CreateFreshApiToken then issues an API cookie

**Conditions.** data_state: user.status = 'suspended'; data_state: user knows the password (no password rotation on suspension)

**Reproduction (local fixture only).** A staff user whose status was set to 'suspended' by users.manage (ex-employee, compromised account)
1. POST /login -> AuthenticatedSessionController@store (guest, throttle:10,1)
1. Auth::guard('web')->login($user) after Auth::guard('web')->validate($credentials) (line 31); neither checks $user->status

Observed/expected: A suspended user (or anyone holding their password) logs in at /login, receives a web session and a Passport cookie, and keeps full access to pages and /api/v1; only the bearer-token path is closed

**Remediation.** In AuthenticatedSessionController@store (and TwoFactorController@verify) reject when $user->status !== 'active' (same as ApiTokenIssuer:41), ideally via a shared LoginGuard::active() check; alternatively add a web middleware that logs out non-active users on every request

---

## [HIGH] PosController::can() accepts pos.view for every write action: a read-only POS user can open checks, add items, settle and charge to any in-house guest folio

- Fingerprint: `authz:pos:view-permission-can-settle-and-charge-rooms`  — **FIXED in this run** (test: `tests/Feature/Security/AuditMoneyTest.php`)
- Likelihood high / impact high; confidence high
- Intended behaviour: pos.view = read-only; pos.manage required to write
- Root cause: app/Modules/POS/Application/PosService.php:216 - chargeToRoom posts charges to the guest folio via FolioService::charge (no folios.manage needed); settle (line 178) records payments

**Description.** A viewer account (e.g. auditor, trainee) can create checks and charge them to any checked-in reservation, or settle checks for cash without a cashier shift being theirs (settle requires an open shift only for cash/card/mobile kinds).

**Trace.**
- entrypoint: `app/Modules/POS/routes.php:29` (routes) — POST /api/v1/pos/checks/{check}/settle, charge-to-room, items, sync...
- propagation: `app/Modules/POS/Http/PosController.php:20` (PosController) — Every action calls the same can() helper with no ability argument
- sink: `app/Modules/POS/Http/PosController.php:288` (PosController::can) — pos.view is accepted for all write actions

**Conditions.** data_state: user has pos.view but not pos.manage

**Reproduction (local fixture only).** Staff user holding only pos.view
1. private can(): abort_unless(pos.manage || pos.view) used by store, addItem, fire, updateLine, removeLine, split, settle, chargeToRoom, sync, transfer, comp
1. chargeToRoom posts charges to the guest folio via FolioService::charge (no folios.manage needed); settle (line 178) records payments

Observed/expected: A viewer account (e.g. auditor, trainee) can create checks and charge them to any checked-in reservation, or settle checks for cash without a cashier shift being theirs (settle requires an open shift only for cash/card/mobile kinds).

**Remediation.** Give can() an $ability argument like the other controllers and require pos.manage for all non-GET actions.

---

## [HIGH] Gateway refund amount is checked on an unlocked intent and sent to the provider before the row lock, so concurrent refunds exceed the paid amount

- Fingerprint: `money:payments:refund-toctou-double-refund`  — **FIXED in this run** (test: `tests/Feature/Security/AuditMoneyTest.php`)
- Likelihood medium / impact high; confidence high
- Intended behaviour: amount <= intent.amount - refunded_amount and refund <= folio credit balance
- Root cause: app/Modules/Payments/Application/PaymentService.php:254 - driverOf($intent)->refund(...) sends money back through MTN/Orange/card BEFORE the transaction and lockForUpdate at line 256-257; after the lock no re-check of refunded_amount is done

**Description.** Two simultaneous refund requests of the full refundable amount both pass the checks, both call the provider refund API, both post a refund line; the hotel refunds up to 2x the payment and the folio goes into debit.

**Trace.**
- entrypoint: `app/Modules/Payments/Http/PaymentController.php:56` (PaymentController::refund) — POST refund by a payments.refund holder
- propagation: `app/Modules/Payments/Application/PaymentService.php:244` (PaymentService::refund) — Refundable amount and folio credit checked on unlocked rows
- sink: `app/Modules/Payments/Application/PaymentService.php:254` (PaymentService::refund) — Gateway refund called before the lockForUpdate transaction; two concurrent calls both pass and both pay out

**Conditions.** timing_dependency: two requests in flight concurrently (no Idempotency-Key header or different keys); system_configuration: real gateway credentials configured (otherwise dry-run refund succeeds too)

**Reproduction (local fixture only).** Staff user holding payments.refund (or an idempotency-less retry/duplicate click from the UI)
1. POST /api/v1/payments/intents/{intent}/refunds validates only amount min:1 and calls PaymentService::refund with an unlocked PaymentIntent
1. driverOf($intent)->refund(...) sends money back through MTN/Orange/card BEFORE the transaction and lockForUpdate at line 256-257; after the lock no re-check of refunded_amount is done

Observed/expected: Two simultaneous refund requests of the full refundable amount both pass the checks, both call the provider refund API, both post a refund line; the hotel refunds up to 2x the payment and the folio goes into debit.

**Remediation.** Open the DB::transaction and PaymentIntent::lockForUpdate() first, re-read refunded_amount and Folio::lockForUpdate()->balance inside it, insert the PaymentRefund row as pending, then call the gateway; mark succeeded afterwards.

---

## [HIGH] PayoutService::process reads pending MoMo items without any lock, so two concurrent /process calls disburse each salary twice

- Fingerprint: `money:payouts:process-race-double-disbursement`  — **FIXED in this run** (test: `tests/Feature/Security/AuditMoneyTest.php`)
- Likelihood medium / impact high; confidence high
- Intended behaviour: item.status transitions pending -> sent/succeeded exactly once; attempts counter
- Root cause: app/Modules/Payments/Application/Payouts/PayoutService.php:110 - $driver->disburse($item, ...) called for every item still 'pending' in the unlocked collection fetched at line 97-98; status update at line 111 happens after the provider call

**Description.** Both workers see the same pending items and call MtnDisbursement/OrangeDisbursement twice per employee; the second update overwrites the first provider_reference. Salaries are paid twice; GL posts once.

**Trace.**
- entrypoint: `app/Modules/Payments/Http/MobileMoneyController.php:135` (MobileMoneyController::processPayout) — POST process by a payments.payouts holder
- propagation: `app/Modules/Payments/Application/Payouts/PayoutService.php:97` (PayoutService::process) — Pending items selected without lock or transaction
- sink: `app/Modules/Payments/Application/Payouts/PayoutService.php:110` (PayoutService::process) — Provider disburse() called before the item status is updated; two overlapping calls disburse twice

**Conditions.** timing_dependency: two concurrent requests (no Idempotency-Key); system_configuration: MoMo credentials configured (in dry run the duplicate is only visible in logs)

**Reproduction (local fixture only).** Staff user holding payments.payouts (or UI double-submit / retry)
1. POST /api/v1/payments/payouts/{payout}/process
1. $driver->disburse($item, ...) called for every item still 'pending' in the unlocked collection fetched at line 97-98; status update at line 111 happens after the provider call

Observed/expected: Both workers see the same pending items and call MtnDisbursement/OrangeDisbursement twice per employee; the second update overwrites the first provider_reference. Salaries are paid twice; GL posts once.

**Remediation.** Wrap process() in DB::transaction with PayoutRun::lockForUpdate() (or a cache lock per run) and re-select items with lockForUpdate(); mark an item 'sent' with its reference before calling the provider.

---

## [HIGH] One payments.payouts user can reject a bank/cash payout item, rewrite its destination to any string and re-run it (no four-eyes, no audit event)

- Fingerprint: `money:payouts:single-role-destination-diversion`  — **FIXED in this run** (test: `tests/Feature/Security/AuditMoneyTest.php`)
- Likelihood medium / impact high; confidence high
- Intended behaviour: Salary destinations come from the employee record; sensitive changes go through HR approval (SelfService::decide)
- Root cause: app/Modules/Payments/Application/Payouts/PayoutService.php:167 - updateDestination() writes destination/channel on the item and re-queues it; bankFile() (line 123) and process() (line 94) then pay to it

**Description.** Attacker rejects a colleague's bank item, sets destination to their own account/number, downloads the bank CSV or processes the MoMo item; salary is diverted. Only the payout_run row changes; no audit trail of the edit.

**Trace.**
- entrypoint: `app/Modules/Payments/Http/MobileMoneyController.php:168` (MobileMoneyController::updatePayoutItem) — PUT destination by a payments.payouts holder
- propagation: `app/Modules/Payments/Http/MobileMoneyController.php:160` (MobileMoneyController::rejectPayoutItems) — The same role can first mark any bank/cash item failed
- sink: `app/Modules/Payments/Application/Payouts/PayoutService.php:167` (PayoutService::updateDestination) — Any destination string accepted, channel re-derived, no event recorded, item re-queued

**Conditions.** data_state: user has payments.payouts; data_state: payout run in pending/processing state

**Reproduction (local fixture only).** Staff user holding only payments.payouts
1. PUT /api/v1/payments/payouts/{payout}/items/{item} validates destination as free string max:60
1. updateDestination() writes destination/channel on the item and re-queues it; bankFile() (line 123) and process() (line 94) then pay to it

Observed/expected: Attacker rejects a colleague's bank item, sets destination to their own account/number, downloads the bank CSV or processes the MoMo item; salary is diverted. Only the payout_run row changes; no audit trail of the edit.

**Remediation.** Restrict updateDestination to the value currently on the Employee record (or require hr.manage + record an event with old/new), validate format per channel, and forbid reject+update by the run creator.

---

## [HIGH] Public booking attaches a reservation to any existing guest profile matched by email; lookup then discloses that guest's loyalty number, points and QR text, and online check-in overwrites the guest's ID data

- Fingerprint: `public:booking:guest-profile-takeover-by-email`  — **FIXED in this run** (test: `tests/Feature/Security/AuditPublicBookingTest.php`)
- Likelihood high / impact high; confidence high
- Intended behaviour: Guest identity on the public site is proven by confirmation number + last name; loyalty data should only be returned to the member
- Root cause: app/Modules/GuestPortal/Http/BookingEngineController.php:165 - lookup(): returns loyalty number, tier, points_balance and 'OPES-LOYALTY:<number>' QR text for the guest attached to the booking (and guest first/last name on line 162)

**Description.** Attacker POSTs /api/v1/booking/{group}/reservations with guest.email = victim email and guest.last_name = victim last name (first name arbitrary), receives confirmation + manage token; POST reservations/lookup with that confirmation + victim last name returns the victim's loyalty card number, tier and points balance plus the QR string staff scan for earn/redeem; the attacker can also submit online check-in that rewrites the victim's stored ID number / birth date / address, and the fake booking appears under the victim's profile (member portal, history, marketing consent flag on the shared profile is untouched but the reservation and any special_requests are theirs). Cancellation later is free when the plan is refundable.

**Trace.**
- entrypoint: `app/Modules/GuestPortal/Http/BookingEngineController.php:97` (BookingEngineController::book) — Unauthenticated POST /api/v1/booking/{group}/reservations
- propagation: `app/Modules/GuestPortal/Http/BookingEngineController.php:120` (BookingEngineController::book) — Existing Guest matched by email alone (or by member_number alone) and attached to the new booking
- sink: `app/Modules/GuestPortal/Http/BookingEngineController.php:165` (BookingEngineController::lookup) — lookup with confirmation + the victim's last name returns the victim's loyalty number, tier, points and QR text, plus a manage token

**Conditions.** data_state: Victim has a Guest row (any prior stay) in the group; loyalty disclosure additionally requires an active membership; authentication_level: Attacker knows victim email + last name (both commonly public)

**Reproduction (local fixture only).** Anonymous internet user who knows a guest's email address and last name
1. book(): Guest::where('email', $data['guest']['email'])->first() reuses an existing guest row with no proof that the caller owns that email; member_number on line 118 likewise binds the booking to Membership->guest without the last-name check used elsewhere
1. lookup(): returns loyalty number, tier, points_balance and 'OPES-LOYALTY:<number>' QR text for the guest attached to the booking (and guest first/last name on line 162)

Observed/expected: Attacker POSTs /api/v1/booking/{group}/reservations with guest.email = victim email and guest.last_name = victim last name (first name arbitrary), receives confirmation + manage token; POST reservations/lookup with that confirmation + victim last name returns the victim's loyalty card number, tier and points balance plus the QR string staff scan for earn/redeem; the attacker can also submit online check-in that rewrites the victim's stored ID number / birth date / address, and the fake booking appears under the victim's profile (member portal, history, marketing consent flag on the shared profile is untouched but the reservation and any special_requests are theirs). Cancellation later is free when the plan is refundable.

**Remediation.** In book(): never attach a public booking to an existing guest by email alone; create a new guest row (or only reuse when the member_number + last name pair matches, as member() does on line 228-236) and require last-name match for member_number on line 118. In lookup(): drop the 'loyalty' block (or only return it when the request also carries the member's own credential).

---

## [HIGH] DatabaseSeeder always seeds admin@opeshotelsoft.test / Admin@2026! and 20+ demo staff with Demo@2026!

- Fingerprint: `secrets:seeder:demo-admin-fixed-password-in-default-seeder`  — **FIXED in this run** (test: `tests/Feature/Security/AuditAuthTest.php`)
- Likelihood medium / impact high; confidence high
- Intended behaviour: Demo data only for demos; production is expected to create its own owner account
- Root cause: database/seeders/DemoUsersSeeder.php:48 - admin password 'Admin@2026!' (line 52: all demo users 'Demo@2026!')

**Description.** Any operator running the default seeder in production creates a known-password super-admin that works even with DEMO_LOGINS=false (only 2FA enrolment prompt would stand in the way, and only in the web UI)

**Trace.**
- entrypoint: `docs/DEPLOY.md:39` (Production install steps (docker line 39, bare-metal line 105)) — 'php artisan db:seed --force  # once, first install only' - the documented production install runs the default seeder
- propagation: `database/seeders/DatabaseSeeder.php:13` (DatabaseSeeder::run) — calls DemoSeeder unconditionally (no environment guard)
- propagation: `database/seeders/DemoSeeder.php:34` (DemoSeeder::run) — Group::firstOrCreate(['slug'=>'opes-demo']) - this is the ONLY code path in the repository that creates a Group (grep app/ database/), so a production install's real tenant is this group
- propagation: `database/seeders/DemoSeeder.php:52` (DemoSeeder::run) — calls DemoUsersSeeder
- sink: `database/seeders/DemoUsersSeeder.php:48` (DemoUsersSeeder::run) — admin@opeshotelsoft.test created with password 'Admin@2026!' and role group-owner ('*', line 49); 20+ staff with 'Demo@2026!' (line 52)

**Conditions.** system_configuration: Operator followed docs/DEPLOY.md and ran db:seed on the production database (the documented first-install step) and did not change the admin password; user_interaction: None beyond knowing the public seeder

**Reproduction (local fixture only).** Anonymous internet user who has read the public repository
1. Submit the login form or token request on the target host
1. If MFA enrolment is required in the web UI, enrol an attacker TOTP device (no second factor existed before)
1. Use the group-owner session

Observed/expected: Full group-owner access to the production tenant (users, payroll, accounting, exports)

**Remediation.** Remove DemoSeeder from DatabaseSeeder (keep RolesAndPermissionsSeeder only) and provide an interactive artisan command (e.g. ops:create-owner) that creates the real group and owner with a random or prompted password; make DemoSeeder/DemoUsersSeeder return early when app()->isProduction() unless an explicit flag is passed.

---

## [HIGH] Suspending a user revokes API tokens but not live web sessions or remember-me cookies

- Fingerprint: `session:suspension-does-not-invalidate-web-sessions`  — **FIXED in this run** (test: `tests/Feature/Security/AuditAuthTest.php`)
- Likelihood high / impact high; confidence high
- Intended behaviour: Suspension should end access immediately
- Root cause: app/Modules/Tenancy/Http/UserController.php:81 - Only $user->tokens()->update(['revoked'=>true]); no session purge, no remember_token rotation

**Description.** Suspended staff keep browsing and calling /api/v1 via the laravel_token cookie (re-issued on each web response) for up to the session lifetime, or indefinitely with remember-me, since no per-request status check exists

**Trace.**
- entrypoint: `app/Modules/Tenancy/Http/UserController.php:61` (UserController::update) — PATCH /api/v1/users/{user} status=suspended (also set automatically by HR termination: app/Modules/HR/Application/HrService.php line 144)
- propagation: `app/Modules/Tenancy/Http/UserController.php:81` (UserController::update) — only $user->tokens()->update(['revoked'=>true]); no sessions purge, no remember_token rotation, no password invalidation
- propagation: `app/Http/Controllers/Auth/AuthenticatedSessionController.php:31` (AuthenticatedSessionController::store) — Auth::guard('web')->validate($credentials) then login (line 45): users.status is never consulted, so a suspended user can even sign in again with their password
- propagation: `app/Platform/Tenancy/ResolveTenant.php:21` (ResolveTenant::handle) — per-request tenant resolution reads the user but never its status; no middleware anywhere checks users.status (grep: only StaffDirectory/StaffController filter listings)
- sink: `bootstrap/app.php:33` (withMiddleware web group) — CreateFreshApiToken re-issues the laravel_token API cookie on every web response, so the suspended user keeps full /api/v1 access via the session

**Conditions.** data_state: Target account was suspended/terminated; attacker knows the password or still holds a session/remember cookie

**Reproduction (local fixture only).** Terminated employee whose HR record was closed (users.status='suspended')
1. As admin PATCH /api/v1/users/{id} {"status":"suspended"} (or terminate the employee)
1. As the suspended user, POST /login with the still-valid password, or reuse the browser session
1. Call any staff API

Observed/expected: Login succeeds (302 to dashboard) and all permitted API calls return 200; only personal access tokens issued before suspension are refused

**Remediation.** Add a status check in AuthenticatedSessionController::store, TokenController::store and DemoLoginController (refuse status==='suspended'), add a lightweight middleware on web+api groups that logs out / 401s when $request->user()->status === 'suspended', and on suspension delete sessions where user_id = $user->id and rotate remember_token.

---

## [HIGH] A hotel-scoped users.manage holder can grant all_hotels=true (to anyone, including themself) and so escape their own hotel allow-list

- Fingerprint: `tenancy:users:hotel-scoped-admin-grants-all-hotels`  — **FIXED in this run** (test: `tests/Feature/Security/AuditTenancyTest.php`)
- Likelihood high / impact high; confidence high
- Intended behaviour: Per-user hotel allow-list (users.hotels pivot / all_hotels) is the second tenancy dimension; a hotel manager should not be able to widen it beyond their own scope nor hand out higher roles
- Root cause: app/Modules/Tenancy/Http/UserController.php:148 - $user->update(collect($data)->only(['name','status','all_hotels'])) and syncRoles($data['roles']) with no check that the actor themself has all_hotels or holds the role being granted

**Description.** Hotel-A manager PATCHes their own user (or a colleague) with {"all_hotels": true, "roles": ["group-owner"]} -> next request they read/write every hotel of the group (reservations, folios, payroll, accounting) and hold group-owner permissions

**Trace.**
- entrypoint: `app/Modules/Tenancy/routes.php:16` (routes) — PATCH /api/v1/users/{user} and POST /api/v1/users reachable with users.manage only
- propagation: `app/Modules/Tenancy/Http/UserController.php:63` (UserController::update) — Only users.manage and same group are checked; the actor's own hotel scope is never compared
- sink: `app/Modules/Tenancy/Http/UserController.php:74` (UserController::update) — all_hotels and roles (any role, including group-owner) are written from the request

**Conditions.** authorization_role: actor has users.manage (hotel manager role likely has it); authorization_role: actor is not all_hotels (otherwise nothing to gain except role escalation); data_state: update() does not exclude the actor's own user id

**Reproduction (local fixture only).** Staff user with users.manage whose all_hotels=false (allowed to hotel A only)
1. PATCH /api/v1/users/{user} (update) and POST /api/v1/users (store, line 100); only checks users.manage and same group_id
1. $user->update(collect($data)->only(['name','status','all_hotels'])) and syncRoles($data['roles']) with no check that the actor themself has all_hotels or holds the role being granted

Observed/expected: Hotel-A manager PATCHes their own user (or a colleague) with {"all_hotels": true, "roles": ["group-owner"]} -> next request they read/write every hotel of the group (reservations, folios, payroll, accounting) and hold group-owner permissions

**Remediation.** In store()/update(): abort_unless($request->user()->all_hotels, 403) when all_hotels is requested true; restrict hotel_ids to the actor's allowedHotelIds(); forbid assigning roles the actor does not hold (or require a group-level permission such as users.manage_group); forbid editing own account's scope/roles

---

## [MEDIUM] hr.manage / payroll.manage / talent.requests users can change their own employee salary, bank or MoMo destination, grant themselves advances and approve their own self-service change request

- Fingerprint: `authz:hr:self-modification-of-own-salary-bank-and-advances`
- Likelihood medium / impact high; confidence high
- Intended behaviour: Sensitive changes (address, phone, bank, MoMo) are requests that HR decides; compensation changes require a different approver
- Root cause: app/Modules/HR/Application/PayrollService.php:68 - base_salary feeds proratedBase; PayoutService::create (Payouts/PayoutService.php:49-53) pays to employee.bank_account/momo_number

**Description.** An HR manager raises their own base_salary or redirects their payout destination directly; a talent.requests holder approves their own bank_change request; a payroll.manage user grants themselves a loan that the next payroll only partially recovers.

**Trace.**
- entrypoint: `app/Modules/HR/Http/HrController.php:96` (HrController::updateEmployee) — PATCH /api/v1/hr/employees/{employee} with hr.manage; base_salary, payment_method, bank_account, momo_number accepted (line 101-102); no check that $employee->user_id !== actor
- propagation: `app/Modules/HR/Application/HrService.php:130` (HrService::updateEmployee) — $e->update($data) and active contract base_salary updated (line 132)
- propagation: `app/Modules/HR/Http/HrController.php:187` (HrController::grantAdvance) — payroll.manage creates a SalaryAdvance for any employee including themselves, no SoD, no cap (line 190)
- propagation: `app/Modules/HR/SelfService/SelfService.php:90` (SelfService::decide (via TalentController::decideRequest line 432)) — approving a bank_change/momo_change request writes the payload to the employee with no requester != decider check
- sink: `app/Modules/HR/Application/PayrollService.php:68` (PayrollService::compute) — base_salary feeds proratedBase; PayoutService pays to employee.bank_account/momo_number

**Conditions.** authorization_role: hr.manage (salary/bank), payroll.manage (advances) or talent.requests (self-service decisions); data_state: Actor's user is linked to an Employee row in the same hotel

**Reproduction (local fixture only).** HR officer who is also an employee
1. Find own employee id via GET /api/v1/hr/employees
1. Send the PATCH / advance request

Observed/expected: 200/201; next payroll pays the raised salary to the attacker-chosen destination; advance recovered at 1 per month

**Remediation.** Reuse LifecycleService::assertSod semantics: in updateEmployee (when salary/bank/momo/payment_method keys are present), grantAdvance and SelfService::decide, throw DomainException('sod') when $employee->user_id === actor->id or request->employee's user_id === actor->id.

---

## [MEDIUM] Payroll preparer check compares against prepared_by set at first compute; a second user can recompute with arbitrary bonus inputs and then approve as 'not the preparer'

- Fingerprint: `authz:payroll:sod-check-only-against-original-creator`
- Likelihood medium / impact high; confidence high
- Intended behaviour: The payroll must be approved by someone other than the preparer (sod)
- Root cause: app/Modules/HR/Application/PayrollService.php:94 - approve() only refuses when prepared_by === actor; prepared_by is set once by firstOrCreate at line 51 and never updated on recompute

**Description.** User A creates the draft; user B (approver) recomputes with bonuses => 5,000,000 for themselves and approves immediately; GL posts and the run becomes payable via PayoutService.

**Trace.**
- entrypoint: `app/Modules/HR/Http/PayrollController.php:31` (PayrollController::compute) — POST /api/v1/payroll/runs with payroll.manage; 'inputs.*.bonuses' integer min:0 with no max (line 37)
- propagation: `app/Modules/HR/Application/PayrollService.php:51` (PayrollService::compute) — PayrollRun::firstOrCreate(['period'], ['prepared_by' => actor]) - prepared_by is set once by whoever created the draft and never updated on recompute
- propagation: `app/Modules/HR/Application/PayrollService.php:55` (PayrollService::compute) — payslips deleted and rebuilt with the new inputs; bonuses/service_charge flow into net (line 70)
- propagation: `app/Modules/HR/Application/PayrollService.php:94` (PayrollService::approve) — refuses only when prepared_by === actor ('sod')
- sink: `app/Modules/HR/Application/PayrollService.php:105` (PayrollService::approve) — run approved by the user who actually set the amounts; GL posting and PayoutService payment follow

**Conditions.** authorization_role: Approver also holds payroll.manage (GM, group-owner or a custom HR-manager role); data_state: Another user created the draft run for the period first; hotel has not enabled payroll_review_required (PayrollChecks line 98)

**Reproduction (local fixture only).** General manager who is also an employee, after the payroll officer created the monthly draft
1. Recompute the existing draft with the bonus input
1. Approve it

Observed/expected: Approval succeeds (prepared_by is still the officer); net pay inflated, GL posted, payout run created

**Remediation.** Set prepared_by = actor on every compute (and keep last_computed_by), and in approve() refuse when either equals the actor; optionally cap manual bonus inputs or force payroll_review_required when inputs changed.

---

## [MEDIUM] Scheduled report edited by another user still runs with the creator's identity and hotel allow-list

- Fingerprint: `authz:scheduled-reports:runs-as-creator-after-edit-by-other-user`
- Likelihood medium / impact medium; confidence high
- Intended behaviour: Comment in Exporter/InternalApi: 'a document or export is only ever as visible as the resource it is built from' (checked for the caller) - but the caller at run time is the creator, not the editor
- Root cause: app/Modules/Documents/Application/ScheduledReportRunner.php:31 - Auth::guard('api')->setUser($creator); ctx->set($s->group_id,$s->hotel_id,$creator->allowedHotelIds(),$creator); then exporter renders the report through InternalApi as that user and mails it to $s->recipients

**Description.** User B (reports.manage, hotel-limited, no payroll/accounting permission) edits a report created by the finance director: sets report to a payroll/accounting export and recipients to B's address, calls /run -> receives data B is not allowed to read

**Trace.**
- entrypoint: `app/Modules/Documents/Http/ScheduledReportController.php:31` (ScheduledReportController::update) — PUT /api/v1/scheduled-reports/{id}; only guard is can('reports.manage') (line 82-86); route-bound model is hotel-scoped via BelongsToHotel so the editor must share the hotel
- propagation: `app/Modules/Documents/Http/ScheduledReportController.php:34` (ScheduledReportController::update) — fill(validated(partial)) replaces report/params/recipients; created_by is never reset to the editor
- propagation: `app/Modules/Documents/Http/ScheduledReportController.php:63` (ScheduledReportController::validated) — 'report' may be any ReportRegistry key (payroll-run, general-ledger, police-register...); 'recipients.*' only needs to be an email (line 67)
- propagation: `app/Modules/Documents/Http/ScheduledReportController.php:50` (ScheduledReportController::run) — POST .../run lets the same reports.manage editor execute it immediately; comment says 'runs as the schedule's creator'
- propagation: `app/Modules/Documents/Application/ScheduledReportRunner.php:32` (ScheduledReportRunner::run) — Auth::guard('api')->setUser($creator) then ctx->set(group, hotel, $creator->allowedHotelIds(), $creator) (line 33)
- propagation: `app/Modules/Documents/Application/InternalApi.php:50` (InternalApi::raw) — sub-request dispatched through the router; auth:api resolves the passport RequestGuard whose user was just set to the creator, so the underlying report controller's can() checks pass with the creator's permissions
- sink: `app/Modules/Documents/Application/ScheduledReportRunner.php:36` (ScheduledReportRunner::run) — Mail::raw(...)->to($s->recipients)->attachData(rendered report): report rendered with the creator's rights is sent to editor-chosen addresses

**Conditions.** authorization_role: Attacker holds reports.manage but lacks the permission of the target report endpoint (payroll.view, accounting.view, guests/police register); data_state: A scheduled report created by a more privileged user exists in a hotel the attacker can access (hotel scope on the model)

**Reproduction (local fixture only).** Staff user B with reports.manage (no payroll/accounting permission) in hotel H where finance director A created a scheduled report
1. Authenticate as B with X-Hotel-Id: H
1. Send the PUT to repoint the report and recipients
1. Send POST .../run
1. Check the mailbox / log mailer output

Observed/expected: last_status = 'sent'; the general-ledger (or payroll-run) export rendered with A's permissions arrives at b@attacker.example although GET /api/v1/accounting/reports/general-ledger as B returns 403

**Remediation.** In update(): set created_by = $request->user()->id when report/params/recipients change (re-own the schedule), and in run(): execute as $request->user() instead of created_by; alternatively forbid editing schedules you did not create.

---

## [MEDIUM] Folio transfer accepts any destination folio (including city-ledger folios) and negative amounts, bypassing the company credit-limit/approval check

- Fingerprint: `business-logic:folio:transfer-bypasses-city-ledger-credit-limit-and-sign`
- Likelihood medium / impact medium; confidence high
- Intended behaviour: Direct billing only to approved companies within credit limit; transfers move charges, not arbitrary credit
- Root cause: app/Modules/FrontOffice/Application/FolioService.php:163 - transfer() posts transfer_out/transfer_in to any two open folios regardless of kind; no check against from-folio balance

**Description.** A guest's balance can be moved to an open city-ledger folio of a company that is not approved or already over its limit (receivable risk), and a negative transfer pulls credit from another guest's/company's folio onto the attacker's chosen folio, which can then be refunded (folios.adjust) or used to close the folio at check-out.

**Trace.**
- entrypoint: `app/Modules/FrontOffice/Http/FolioController.php:111` (FolioController::transfer) — POST /api/v1/folios/{folio}/transfer with folios.manage; 'to_folio_id' => integer, 'amount' => ['required','integer'] (line 114, no min, no sign check); to-folio loaded with Folio::findOrFail (tenant-scoped, any kind)
- propagation: `app/Modules/FrontOffice/Application/FolioService.php:159` (FolioService::transfer) — only amount === 0 or same folio rejected; both folios merely need to be open
- sink: `app/Modules/FrontOffice/Application/FolioService.php:163` (FolioService::transfer) — posts transfer_out(-amount) on from and transfer_in(+amount) on to - into any open folio including kind 'city_ledger', with no balance, credit-limit or city_ledger-approval check

**Conditions.** authorization_role: folios.manage (receptionist role has it); data_state: An open city_ledger folio for the target company exists (created by any earlier direct bill), or any second open folio for the negative-amount variant

**Reproduction (local fixture only).** Receptionist with folios.manage
1. Find an open city_ledger folio id via GET /api/v1/folios
1. Transfer the guest balance into it
1. Or transfer a negative amount to pull credit onto another folio, then refund/check-out

Observed/expected: 200 with two transfer lines; the company's receivable exceeds credit_limit (or a non-approved company is billed); the negative variant leaves the attacker's folio in credit

**Remediation.** In FolioService::transfer(): require $amount > 0 (validate 'amount' => 'integer|min:1' in the controller), and throw when $to->kind === 'city_ledger' unless called from transferToCityLedger (pass an internal flag).

---

## [MEDIUM] POS offline sync accepts negative/zero quantities and unvalidated payment arrays, letting a server lower a check total or settle with arbitrary methods/amounts

- Fingerprint: `business-logic:pos:sync-bypasses-line-and-payment-validation`
- Likelihood medium / impact medium; confidence high
- Intended behaviour: quantity >= 0.001; payments.*.amount integer min:1; voids/discounts need pos.void/pos.discount
- Root cause: app/Modules/POS/Application/PosService.php:66 - addItem stores quantity and amount = round(price*qty) with negative qty; Check::recalculate (Domain/Check.php:52) sums amounts so the total drops; settle() only requires sum(payments.amount) == total

**Description.** A server syncs a check with lines [item A x1 = 5000, item A x-0.9 = -4500], total 500, settles for 500 cash and serves 5000 worth of goods (stock consumed by pos.check_settled items). Or settles [CASH 10000, CASH -9500] making the drawer expect 500.

**Trace.**
- entrypoint: `app/Modules/POS/Http/PosController.php:138` (PosController::sync) — POST /api/v1/pos/sync, guard can() = pos.view/pos.manage; 'checks.*.items.*.quantity' => ['sometimes','numeric'] (line 149, no min) and 'checks.*.payments' => ['nullable','array'] (line 151, no element rules)
- propagation: `app/Modules/POS/Http/PosController.php:166` (PosController::sync) — each item passed straight to pos->addItem($check, menu_item_id, quantity)
- propagation: `app/Modules/POS/Application/PosService.php:68` (PosService::addItem) — line created with 'quantity' => $qty and 'amount' => (int) round(($base+$extra)*$qty) - negative/zero qty gives a negative/zero amount
- propagation: `app/Modules/POS/Domain/Check.php:54` (Check::recalculate) — subtotal = sum(amount) of non-voided lines; total = max(0, subtotal - comp - discount) - negative lines lower the total without pos.discount/pos.void
- propagation: `app/Modules/POS/Http/PosController.php:172` (PosController::sync) — raw payments array handed to pos->settle
- sink: `app/Modules/POS/Application/PosService.php:201` (PosService::settle) — only sum(amount) === total is checked (line 185-187); each payment row (including negative amounts) is stored in pos_payments and finish() posts the sale

**Conditions.** authorization_role: Any user passing PosController::can (pos.manage e.g. pos-cashier role); data_state: Open cashier shift for cash/card/mobile tenders (PosService line 194-196)

**Reproduction (local fixture only).** POS cashier without pos.void/pos.discount
1. Open a cashier shift
1. Send the sync payload
1. Inspect pos_checks.total and pos_payments

Observed/expected: Check synced and settled for 500 while a 5000 item was served; or pos_payments contains a -9500 row

**Remediation.** In sync() apply the same rules as addItem/settle: 'checks.*.items.*.quantity' => ['sometimes','numeric','min:0.001'], 'checks.*.payments.*.method' => ['required','string'], 'checks.*.payments.*.amount' => ['required','integer','min:1']; additionally reject qty <= 0 in PosService::addItem.

---

## [MEDIUM] Public booking honours staff-chosen corporate codes (e.g. 'ACME') and books on the company's contract: negotiated rates, allotment rooms and company_id on the reservation, with a 300/min validity oracle

- Fingerprint: `public:booking:corporate-code-low-entropy-bills-company`
- Likelihood medium / impact medium; confidence high
- Intended behaviour: Corporate contract bookings are restricted to the company's travellers
- Root cause: app/Modules/Reservations/Revenue/Application/CorporateService.php:173 - bookingFields(): reservation gets company_id, contract rate_plan_id, room_block_id (allotment) and source corporate:<code>

**Description.** Guessing a short code lets anyone consume a company's allotment and book at the confidential negotiated rate with company_id set (depending on folio routing, charges may route to the company's city ledger).

**Trace.**
- entrypoint: `app/Modules/GuestPortal/Http/BookingEngineController.php:108` (BookingEngineController::book) — public POST /api/v1/booking/{group}/reservations accepts corporate_code (nullable string max:24); throttle public-write 20/min/ip; availability (GET, 300/min) accepts the same input
- propagation: `app/Modules/Reservations/Revenue/Application/BookingSiteRevenue.php:32` (BookingSiteRevenue::decorate / prepare) — corporate->find(code) and, when found, corporate offers with negotiated totals are returned -> validity oracle
- propagation: `app/Modules/Reservations/Revenue/Application/CorporateService.php:126` (CorporateService::find) — CorporateContract::where('code', strtoupper(trim($code)))->where('active', true) - the code is the only credential
- propagation: `app/Modules/Reservations/Revenue/Http/OffersController.php:67` (OffersController::contracts (create)) — codes are staff-chosen, regex [A-Za-z0-9_-]{1,20}, unique per hotel - typically a company name
- sink: `app/Modules/Reservations/Revenue/Application/CorporateService.php:172` (CorporateService::bookingFields) — reservation created with company_id, contract rate_plan_id, room_block_id (allotment) and source 'corporate:<code>' (consumed by ReservationService::create line 84)

**Conditions.** data_state: An active corporate contract with a guessable code exists for the hotel; data_state: Billing to the company's city ledger additionally depends on folio routing rules for company reservations (unverified)

**Reproduction (local fixture only).** Anonymous website visitor
1. Enumerate likely company names/abbreviations against availability (300/min per IP) until a response contains 'corporate' offers
1. Book with the found code

Observed/expected: Reservation at the confidential negotiated rate, drawing from the company's allotment, with company_id set on the reservation

**Remediation.** Generate corporate booking codes server-side with >= 64 bits of entropy (or require a company email domain match on the public channel), rate-limit corporate_code lookups per IP with lockout, and leave company_id null / status pending for public corporate bookings until staff confirm.

---

## [MEDIUM] Folio refund validates the credit balance on the unlocked Folio instance; concurrent refunds exceed the credit

- Fingerprint: `race:folio:refund-check-on-stale-balance`
- Likelihood low / impact medium; confidence high
- Intended behaviour: Refund cannot exceed the credit balance
- Root cause: app/Modules/FrontOffice/Application/FolioService.php:111 - if ($folio->balance > -$amount) uses the route-bound model; post() at line 238 locks the folio only to insert the line and increment

**Description.** Two parallel refunds of a 10000 credit each pass the check; folio ends at +10000 debit and the cashier pays out 20000.

**Trace.**
- entrypoint: `app/Modules/FrontOffice/Http/FolioController.php:94` (FolioController::refund) — POST /api/v1/folios/{folio}/refunds with folios.adjust; amount integer min:1
- propagation: `app/Modules/FrontOffice/Application/FolioService.php:111` (FolioService::refund) — if ($folio->balance > -$amount) throw - evaluated on the route-bound, unlocked Folio instance outside any transaction
- propagation: `app/Modules/FrontOffice/Application/FolioService.php:238` (FolioService::post) — Folio::lockForUpdate()->findOrFail only inside post(); balance not re-validated under the lock
- sink: `app/Modules/FrontOffice/Application/FolioService.php:246` (FolioService::post) — $folio->increment('balance', $record->amount) - second concurrent refund also passes and the folio goes into debit

**Conditions.** authorization_role: folios.adjust (front-office-manager) plus an open cashier shift for the method; timing_dependency: Two refund requests must interleave between the balance check and post()

**Reproduction (local fixture only).** Front-office manager with folios.adjust refunding a guest with a 10000 credit
1. Create a folio with balance -10000
1. Fire both requests in parallel
1. Read folios.balance

Observed/expected: Both return 201; balance ends at +10000 (20000 paid out against a 10000 credit)

**Remediation.** Wrap refund() in DB::transaction, re-read the folio with lockForUpdate() before the balance comparison (or move the check into post() for type === 'refund').

---

## [MEDIUM] Edge push tenant check for child tables passes when the parent row belongs to another hotel of the same group

- Fingerprint: `tenancy:edge:child-row-parent-check-accepts-sister-hotel`
- Likelihood low / impact high; confidence high
- Intended behaviour: tenantViolation() comment: 'an edge cannot take over a row owned by another tenant'; edge context set to [$node->hotel_id]
- Root cause: app/Modules/Edge/Application/RowStore.php:28 - DB::table($table)->where('id',$id)->update/insert/delete with no tenant predicate

**Description.** Node of hotel A pushes {table:'reservation_nights', row_id:<night of hotel B reservation>, op:'upsert', data:{id, reservation_id:<B res>, amount:0}} -> accepted ('applied'), hotel B's night revenue rewritten; or op:'delete' on hotel B pos_check_lines / loyalty_cards (loyalty_transactions is append-only but insert of a fake earn row for a B membership is possible since loyalty_memberships is group-level)

**Trace.**
- entrypoint: `app/Modules/Edge/Http/EdgeSyncController.php:13` (EdgeSyncController::push) — POST /api/v1/edge/sync/push (public-routes.php line 8, throttle:api only) authenticated by node signature; changes[].table/row_id/op/data are node-chosen (raw input kept at line 31)
- propagation: `app/Modules/Edge/Application/CloudSync.php:181` (CloudSync::tenantViolation) — For tables without hotel_id/group_id columns the parent row is looked up via TablePolicy::PARENTS (line 182-183)
- propagation: `app/Modules/Edge/Application/CloudSync.php:184` (CloudSync::tenantViolation) — if (! $p || (int)($p['hotel_id'] ?? 0) !== $node->hotel_id && (int)($p['group_id'] ?? 0) !== $node->group_id) - && binds tighter than ||, so a parent in hotel B of the same group (group matches) is accepted
- propagation: `app/Modules/Edge/Application/CloudSync.php:143` (CloudSync::apply) — op=delete -> rows->delete; op=upsert -> rows->upsert for 'operational' tables (line 149)
- sink: `app/Modules/Edge/Application/RowStore.php:28` (RowStore::upsert / delete (line 69)) — DB::table($table)->where('id',$id)->update/insert/delete with no tenant predicate

**Conditions.** authentication_level: Attacker holds an active edge-node secret of any hotel in the group (compromised on-premise box or leaked secret); data_state: Target row is in a TablePolicy::PARENTS table whose parent belongs to another hotel of the same group; for upserts the changed_at must be newer than cloud changes (LWW, line 132-139)

**Reproduction (local fixture only).** Operator of hotel A's edge node (or holder of its secret) in a two-hotel group
1. Sign the request with Signature::headers(nodeId, secret, 'POST', 'api/v1/edge/sync/push', body)
1. POST to /api/v1/edge/sync/push
1. Read the hotel B row

Observed/expected: Response reports 'applied' (no tenant_rejected conflict); hotel B's reservation_night amount is rewritten / pos_check_line deleted

**Remediation.** Parenthesise and require a hotel match whenever the parent has a hotel_id column: if (! $p || (array_key_exists('hotel_id',$p) ? (int)$p['hotel_id'] !== $node->hotel_id : (int)($p['group_id'] ?? 0) !== $node->group_id)) return 'Parent row is not owned by this hotel.';

---

## [MEDIUM] Webhook subscription accepts any hotel_id (or null = whole group) without checking the actor's allowed hotels

- Fingerprint: `tenancy:webhooks:hotel-id-not-checked-against-allowed-hotels`
- Likelihood medium / impact medium; confidence high
- Intended behaviour: Per-user hotel allow-list; cross-group is already prevented by the group_id filter in QueueWebhookDeliveries
- Root cause: app/Modules/Platform/Application/QueueWebhookDeliveries.php:21 - subscriptions matched by group_id and (hotel_id null OR hotel_id = event.hotel_id) -> deliveries of hotel B's events are queued to the attacker's URL

**Description.** Hotel-A-only integrator creates {url: attacker, event_types:['*'], hotel_id: <hotel B id>} or hotel_id omitted -> receives all domain events (bookings, folio, payments, HR) of hotel B / the whole group

**Trace.**
- entrypoint: `app/Modules/Platform/Http/WebhookController.php:21` (WebhookController::store) — POST /api/v1/webhooks behind auth:api+tenant; guard is only can('integrations.manage') (line 23)
- propagation: `app/Modules/Platform/Http/WebhookController.php:28` (WebhookController::store) — 'hotel_id' => ['nullable','integer'] - no exists rule, no check against $request->user()->allowedHotelIds(); null = every hotel of the group
- propagation: `app/Modules/Platform/Http/WebhookController.php:31` (WebhookController::store) — WebhookSubscription::create($data + secret); model uses BelongsToGroup only (Domain/WebhookSubscription.php line 10), hotel_id stored verbatim
- propagation: `app/Modules/Platform/Application/QueueWebhookDeliveries.php:21` (QueueWebhookDeliveries::forEvent) — subscriptions selected where group matches and (hotel_id IS NULL OR hotel_id = event.hotel_id)
- sink: `app/Modules/Platform/Application/QueueWebhookDeliveries.php:36` (QueueWebhookDeliveries::forEvent) — DeliverWebhook::dispatch($id) posts the event envelope of hotel B (or all hotels) to the attacker's URL

**Conditions.** authorization_role: Actor holds integrations.manage; data_state: Actor has all_hotels=false (hotel-limited); otherwise group-wide subscription is legitimate

**Reproduction (local fixture only).** Hotel-A-only staff with integrations.manage wants hotel B's reservation/payment/HR events
1. Authenticate as the hotel-A user with X-Hotel-Id: A
1. Create the subscription with hotel_id of hotel B or omitted
1. Trigger any event in hotel B (e.g. a booking)
1. Observe webhook_deliveries rows and the POST at the attacker URL

Observed/expected: Subscription created (201); hotel B events are delivered to the attacker's endpoint although the user cannot send X-Hotel-Id: B

**Remediation.** Validate hotel_id with Rule::exists('hotels','id')->where('group_id', $user->group_id); when $user->allowedHotelIds() !== null require hotel_id to be in that list and reject null (or default it to the active hotel).

---

## [LOW] Edge node HMAC requests have no nonce; any captured push/pull can be replayed for 300 s

- Fingerprint: `authn:edge-sync:signed-request-replay-window`
- Likelihood medium / impact low; confidence high
- Intended behaviour: Signed edge tokens (X-Edge-Node / X-Edge-Timestamp / X-Edge-Signature)
- Root cause: app/Modules/Edge/Application/CloudSync.php:44 - accepts any timestamp within clock_skew (300 s); line 47-48 verifies HMAC over method|path|ts|body, no nonce store

**Description.** Replayed push re-applies the same change batch (idempotent by batch_key at best); replayed pull discloses the same data again; low unless TLS is broken

**Trace.**
- entrypoint: `app/Modules/Edge/public-routes.php:8` (entrypoint) — POST /api/v1/edge/sync/push|pull (no auth group; throttle:api)
- sink: `app/Modules/Edge/Application/CloudSync.php:44` (sink) — accepts any timestamp within clock_skew (300 s); line 47-48 verifies HMAC over method|path|ts|body, no nonce store

**Conditions.** data_state: request capture within 5 minutes

**Reproduction (local fixture only).** Network attacker who can capture one edge request (TLS termination point, misconfigured proxy)
1. POST /api/v1/edge/sync/push|pull (no auth group; throttle:api)
1. accepts any timestamp within clock_skew (300 s); line 47-48 verifies HMAC over method|path|ts|body, no nonce store

Observed/expected: Replayed push re-applies the same change batch (idempotent by batch_key at best); replayed pull discloses the same data again; low unless TLS is broken

**Remediation.** Cache::add("edge-sig:$sig", 1, 600) and reject if already present

---

## [LOW] Member sign-up lets anyone overwrite the pending (unverified) account's names/consent for a contact they do not control and trigger OTP sends to it

- Fingerprint: `authn:member:register-overwrites-pending-account`
- Likelihood medium / impact low; confidence high
- Intended behaviour: Identical answers whether or not the contact exists; code proves ownership before linking
- Root cause: app/Modules/MemberPortal/Application/MemberAuthService.php:85 - existing unverified account reused and fill()ed with attacker names, then issueOtp() sends a code to the contact

**Description.** No takeover (password is set only with the code), but: 1 SMS/WhatsApp per contact per minute from any IP (cost/annoyance, provider reputation), and the legitimate user's pending first/last name are replaced

**Trace.**
- entrypoint: `routes/web/member.php:369` (entrypoint) — POST /account/register (throttle:20,1 per IP — spoofable, see trust-proxies candidate)
- propagation: `app/Modules/MemberPortal/Application/MemberAuthService.php:229` (propagation) — 60 s resend cool-down per account is the only cap on OTP sends per contact
- sink: `app/Modules/MemberPortal/Application/MemberAuthService.php:85` (sink) — existing unverified account reused and fill()ed with attacker names, then issueOtp() sends a code to the contact

**Conditions.** system_configuration: SMS/WhatsApp notifier configured

**Reproduction (local fixture only).** Anonymous internet user
1. POST /account/register (throttle:20,1 per IP — spoofable, see trust-proxies candidate)
1. existing unverified account reused and fill()ed with attacker names, then issueOtp() sends a code to the contact

Observed/expected: No takeover (password is set only with the code), but: 1 SMS/WhatsApp per contact per minute from any IP (cost/annoyance, provider reputation), and the legitimate user's pending first/last name are replaced

**Remediation.** Do not refill names on an existing pending account from a new request; add a per-contact daily cap on OTP issuance (e.g. 5/day) independent of IP

---

## [LOW] TOTP codes can be replayed inside the +/-1 step window (no last-used counter), for web, API and recovery flows

- Fingerprint: `authn:totp:no-replay-protection`
- Likelihood medium / impact low; confidence high
- Intended behaviour: RFC 6238 §5.2: a verifier must not accept the same OTP twice
- Root cause: app/Modules/Admin/Application/TwoFactor.php:129 - check() returns true for a repeated code; no mfa_last_used_at/counter column

**Description.** A phished code works for both the victim and the attacker within the window (e.g. real-time phishing relay gets a session and so does the victim, hiding the attack)

**Trace.**
- entrypoint: `app/Modules/Admin/Domain/Totp.php:158` (entrypoint) — verify() accepts any of 3 steps with no record of consumed counters
- propagation: `app/Modules/Admin/Application/ApiTokenIssuer.php:49` (propagation) — same check on the API path
- sink: `app/Modules/Admin/Application/TwoFactor.php:129` (sink) — check() returns true for a repeated code; no mfa_last_used_at/counter column

**Conditions.** data_state: attacker obtains a fresh code within 90 s

**Reproduction (local fixture only).** Attacker who observes one valid 6-digit code (shoulder-surf, phishing proxy) within ~90 s
1. verify() accepts any of 3 steps with no record of consumed counters
1. check() returns true for a repeated code; no mfa_last_used_at/counter column

Observed/expected: A phished code works for both the victim and the attacker within the window (e.g. real-time phishing relay gets a session and so does the victim, hiding the attack)

**Remediation.** Store the last accepted time-step on the user (mfa_last_step) and reject steps <= that value

---

## [LOW] accounting.post_manual posts journal entries that are marked approved_by the poster, and can reverse any system-generated entry (folio, payroll, stock) without a second person

- Fingerprint: `authz:accounting:manual-entry-self-approved-and-reverse-system-entries`
- Likelihood medium / impact medium; confidence high
- Intended behaviour: Preparer/approver separation for manual journals; subledger-driven entries corrected via their source document
- Root cause: app/Modules/Accounting/Application/Ledger.php:91 - approved_by stored from meta with no distinct-user check; reverse() at line 121 reverses any entry regardless of source_type

**Description.** One user can post Dr cash / Cr revenue adjustments or reverse the automatic Cr 411 / Dr treasury entry of a payment, making the GL disagree with folios and hiding cash shortages; approved_by shows them as approver.

**Trace.**
- entrypoint: `app/Modules/Accounting/Http/AccountingController.php:75` (AccountingController::postManual) — POST /api/v1/accounting/entries requires accounting.post_manual; journal free string max:10, any date in an open period
- propagation: `app/Modules/Accounting/Http/AccountingController.php:96` (AccountingController::postManual) — meta ['source_type'=>'manual','approved_by'=>$request->user()->id] - poster recorded as approver (same in Core/ManualEntries.php line 28)
- propagation: `app/Modules/Accounting/Application/Ledger.php:91` (Ledger::post) — 'approved_by' => $meta['approved_by'] stored with no distinct-user check
- propagation: `app/Modules/Accounting/Http/AccountingController.php:103` (AccountingController::reverse) — reverse endpoint only checks accounting.post_manual
- sink: `app/Modules/Accounting/Application/Ledger.php:121` (Ledger::reverse) — reverses any entry (folio, payroll, stock, payment source types) by posting mirrored lines; only already-reversed/reversal entries are refused (line 123)

**Conditions.** authorization_role: accounting.post_manual; data_state: Target period open / year not locked

**Reproduction (local fixture only).** Accountant hiding a cash shortage
1. Post the manual entry
1. Reverse the automatic treasury entry of a payment

Observed/expected: Entries posted with approved_by = the poster; GL no longer agrees with the folio subledger; everything is in the audit trail under the poster's id

**Remediation.** Store approved_by null on manual posts and add an explicit approve step requiring a different user with a new accounting.approve permission; restrict Ledger::reverse via the API to source_type in ['manual','reversal'] (system entries are corrected through their source document).

---

## [LOW] Passport's default /oauth/* management routes (clients, personal access tokens, authorize) are registered for every web-authenticated staff user

- Fingerprint: `authz:passport:oauth-management-routes-open-to-all-staff`
- Likelihood medium / impact low; confidence high
- Intended behaviour: routes/web.php: 'Every staff page carries a can: guard'; /developer page requires integrations.view
- Root cause: vendor/laravel/passport/routes/web.php:68 - /oauth/tokens, /oauth/clients (GET/POST/PUT/DELETE), /oauth/personal-access-tokens, /oauth/authorize behind only ['web','auth']

**Description.** Any staff user can create OAuth clients (authorization-code with attacker-chosen redirect URI), approve them for their own account, and mint personal access tokens that work for 12 h outside the browser; a phisher can use the /oauth/authorize consent screen on the real domain as a token-stealing flow

**Trace.**
- entrypoint: `vendor/laravel/passport/src/PassportServiceProvider.php:55` (entrypoint) — Passport::$registersRoutes is true (no Passport::ignoreRoutes() in app/Providers)
- propagation: `app/Providers/AppServiceProvider.php:25` (propagation) — Only expiries configured; no Passport::ignoreRoutes(), no scope/role checks on client management
- sink: `vendor/laravel/passport/routes/web.php:68` (sink) — /oauth/tokens, /oauth/clients (GET/POST/PUT/DELETE), /oauth/personal-access-tokens, /oauth/authorize behind only ['web','auth']

**Conditions.** data_state: authenticated web session

**Reproduction (local fixture only).** Any authenticated staff user (e.g. room-attendant) with a web session
1. Passport::$registersRoutes is true (no Passport::ignoreRoutes() in app/Providers)
1. /oauth/tokens, /oauth/clients (GET/POST/PUT/DELETE), /oauth/personal-access-tokens, /oauth/authorize behind only ['web','auth']

Observed/expected: Any staff user can create OAuth clients (authorization-code with attacker-chosen redirect URI), approve them for their own account, and mint personal access tokens that work for 12 h outside the browser; a phisher can use the /oauth/authorize consent screen on the real domain as a token-stealing flow

**Remediation.** Call Passport::ignoreRoutes() in AppServiceProvider::register() and re-register only the grants actually needed (POST /oauth/token for partners) with explicit middleware; or wrap client management routes with can:integrations.manage

---

## [LOW] GET /api/v1/hotels, /hotels/{id} and /staff return group-wide hotel records and the staff directory with roles to any authenticated user

- Fingerprint: `authz:tenancy:hotels-and-staff-directory-without-permission`
- Likelihood medium / impact low; confidence high
- Intended behaviour: routes/web.php:129 /settings/hotels requires hotels.view; /settings/users requires users.view
- Root cause: app/Modules/Tenancy/Http/HotelController.php:13 - index()/show() have no can() check (store/update do: lines 25, 38)

**Description.** Any staff user enumerates every hotel in the group (all columns of the Hotel model, incl. settings/contact data) and the full staff list with role names (useful for targeting admins)

**Trace.**
- entrypoint: `app/Modules/Tenancy/routes.php:12` (entrypoint) — Route::apiResource('hotels', HotelController::class)->except('destroy'); line 13 GET staff
- propagation: `app/Modules/Tenancy/Http/StaffController.php:13` (propagation) — index() lists id, name, roles of every active user scoped to the hotel without permission
- sink: `app/Modules/Tenancy/Http/HotelController.php:13` (sink) — index()/show() have no can() check (store/update do: lines 25, 38)

**Conditions.** authorization_role: any staff token/session

**Reproduction (local fixture only).** Lowest-privilege staff (room-attendant: housekeeping.view only)
1. Route::apiResource('hotels', HotelController::class)->except('destroy'); line 13 GET staff
1. index()/show() have no can() check (store/update do: lines 25, 38)

Observed/expected: Any staff user enumerates every hotel in the group (all columns of the Hotel model, incl. settings/contact data) and the full staff list with role names (useful for targeting admins)

**Remediation.** HotelController@index/show: abort_unless(can hotels.view || hotels.manage) or restrict columns to id/code/name/city; StaffController: require a permission in the pickers' family (e.g. any *.manage) or limit to id/name

---

## [LOW] loyalty.adjust_points can credit an unlimited number of points in one call; points are cash-equivalent via folio/POS redemption

- Fingerprint: `business-logic:loyalty:unbounded-manual-point-credit`
- Likelihood low / impact medium; confidence high
- Intended behaviour: Manual adjustments should be bounded and/or approved
- Root cause: app/Modules/Loyalty/Application/LoyaltyService.php:185 - credit() adds the points; redeemOnFolio (line 141) converts them to a PTS folio payment and PosLoyalty::redeemTender (PosLoyalty.php:55) to a POS tender

**Description.** Staff credits 1,000,000 points to an accomplice member, who pays a folio or bar check with points; revenue is offset against loyalty liability that never corresponded to earned points.

**Trace.**
- entrypoint: `app/Modules/Loyalty/Http/LoyaltyController.php:96` (LoyaltyController::adjust) — POST /api/v1/loyalty/members/{member}/adjust requires loyalty.adjust_points; points integer not_in:0, reason string max:255 (line 99) - no max
- propagation: `app/Modules/Loyalty/Application/LoyaltyService.php:171` (LoyaltyService::adjust) — positive branch skips the lock/limit logic of the negative branch and calls credit()
- sink: `app/Modules/Loyalty/Application/LoyaltyService.php:185` (LoyaltyService::adjust -> credit (line 227)) — points added to the membership; redeemOnFolio (line 141) and PosLoyalty::redeemTender convert them to folio/POS tender

**Conditions.** authorization_role: loyalty.adjust_points plus loyalty.manage or POS access to redeem

**Reproduction (local fixture only).** Manager with loyalty.adjust_points colluding with a member
1. Credit the points
1. Member pays a folio or bar check with points

Observed/expected: Points credited and redeemable; loyalty liability created without a sale (attributed to the actor in loyalty_transactions)

**Remediation.** Add a program-level per-adjustment cap (e.g. Program.max_manual_adjustment) above which a second approver is required, and emit a loyalty.points_adjusted event for alerting.

---

## [LOW] General-ledger report loads every journal line of an account for an arbitrary date range into memory with no cap

- Fingerprint: `dos:accounting:general-ledger-unbounded-date-range`
- Likelihood medium / impact low; confidence high
- Intended behaviour: Paginate or cap reporting ranges
- Root cause: app/Modules/Accounting/Http/AccountingController.php:136 - ->get() of all journal_lines for the account between from and to, then PHP loop computing running balance

**Description.** Repeated requests for account 411100 from 2000-01-01 to 2100-01-01 on a busy hotel (tens of thousands of lines/month) exhaust worker memory/time; 1200/min api limit does not help.

**Trace.**
- entrypoint: `app/Modules/Accounting/Http/AccountingController.php:130` (entrypoint) — validate from/to only as dates; no max span
- propagation: `app/Modules/Accounting/Http/AccountingController.php:109` (propagation) — trialBalance/incomeStatement aggregate in SQL (safe); generalLedger does not
- sink: `app/Modules/Accounting/Http/AccountingController.php:136` (sink) — ->get() of all journal_lines for the account between from and to, then PHP loop computing running balance

**Conditions.** data_state: large journal_lines table; authorization_role: user with accounting.view

**Reproduction (local fixture only).** Staff user holding accounting.view
1. validate from/to only as dates; no max span
1. ->get() of all journal_lines for the account between from and to, then PHP loop computing running balance

Observed/expected: Repeated requests for account 411100 from 2000-01-01 to 2100-01-01 on a busy hotel (tens of thousands of lines/month) exhaust worker memory/time; 1200/min api limit does not help.

**Remediation.** Cap the range (e.g. 366 days) and paginate or stream lines with a cursor.

---

## [LOW] CSV/XLSX exports write guest- and public-supplied text (names, special requests, cover notes, review comments) unescaped: cells starting with = + - @ become formulas in Excel

- Fingerprint: `export:csv:formula-injection`
- Likelihood medium / impact low; confidence high
- Intended behaviour: Exports are trusted spreadsheets for staff
- Root cause: app/Modules/Documents/Application/Exporter.php:79 - csv(): fputcsv($out, $r, ';', '"', '') with cell() (line 64) passing strings through unchanged; same in app/Modules/Accounting/Support/Sheets.php line 41 and the xlsx writers

**Description.** A guest named '=HYPERLINK("http://evil","click")' or '=cmd|/C calc!A0' (with DDE prompts) lands in a report export; Excel evaluates it on the staff machine.

**Trace.**
- entrypoint: `app/Modules/GuestPortal/Http/BookingEngineController.php:104` (BookingEngineController::book) — guest.first_name / last_name (max:100) and special_requests (max:1000) accepted as free strings from the public booking API
- propagation: `app/Modules/Documents/Application/Exporter.php:64` (Exporter::cell) — cell() passes strings through unchanged (only bools/arrays are converted)
- sink: `app/Modules/Documents/Application/Exporter.php:79` (Exporter::csv) — fputcsv($out, $r, ';', '"', '') with no neutralisation of leading = + - @ \t \r; same in app/Modules/Accounting/Support/Sheets.php line 40 and the OpenSpout xlsx writer (line 95)

**Conditions.** user_interaction: Staff opens the export in Excel/LibreOffice and accepts the DDE/external-content prompt (CSV formulas evaluate by default; command execution needs an extra confirmation)

**Reproduction (local fixture only).** Anonymous guest booking online
1. Book via the public API with the payload as last name
1. As staff download /api/v1/exports/police-register?format=csv
1. Open in Excel

Observed/expected: The cell is evaluated as a formula on the staff workstation

**Remediation.** In Exporter::cell() and Sheets::csv(): when a string starts with one of = + - @ \t \r, prefix it with a single quote (') ; for xlsx force a string cell type.

---

## [LOW] Public booking lookup turns a guessable confirmation number + surname into a manage token (cancel/modify/pay) and leaks loyalty number and points balance

- Fingerprint: `idor:booking-api:sequential-confirmation-plus-surname-yields-manage-token`
- Likelihood medium / impact low; confidence high
- Intended behaviour: Lookup credential = confirmation + last name; the 8-char public_code is the intended unguessable reference
- Root cause: app/Modules/GuestPortal/Http/BookingEngineController.php:158 - returns manage_token, guest name, special_requests, deposit figures, loyalty number/tier/points, online check-in ID type

**Description.** Attacker iterates sequential confirmation numbers with common Cameroonian surnames (20 req/min/IP, no per-booking lockout) to obtain manage tokens, cancel competitors' bookings (fee charged to guest) and harvest loyalty balances

**Trace.**
- entrypoint: `app/Modules/GuestPortal/public-routes.php:14` (entrypoint) — POST /api/v1/booking/{group}/reservations/lookup (throttle:public-write 20/min/IP)
- propagation: `app/Modules/GuestPortal/Application/PublicBookingResolver.php:31` (propagation) — number lookup over the whole group by confirmation number (sequenced per hotel, per comment)
- propagation: `app/Modules/GuestPortal/Application/PublicBookingResolver.php:51` (propagation) — distinct responses: 404 when number unknown, 404 name mismatch (same here) but 422 'ambiguous' when number exists in several hotels - partial oracle
- propagation: `app/Modules/GuestPortal/Http/BookingEngineController.php:198` (propagation) — cancel() accepts the manage_token obtained above
- sink: `app/Modules/GuestPortal/Http/BookingEngineController.php:158` (sink) — returns manage_token, guest name, special_requests, deposit figures, loyalty number/tier/points, online check-in ID type

**Conditions.** data_state: confirmation numbers are gapless sequences (NumberSequence); data_state: attacker has many IPs or patience; data_state: surname distribution skewed

**Reproduction (local fixture only).** Anonymous internet client
1. POST /api/v1/booking/{group}/reservations/lookup (throttle:public-write 20/min/IP)
1. returns manage_token, guest name, special_requests, deposit figures, loyalty number/tier/points, online check-in ID type

Observed/expected: Attacker iterates sequential confirmation numbers with common Cameroonian surnames (20 req/min/IP, no per-booking lockout) to obtain manage tokens, cancel competitors' bookings (fee charged to guest) and harvest loyalty balances

**Remediation.** Require the public_code (reference) or confirmation + last name + arrival date; add per-number failure counter/lockout; do not return loyalty points/number from lookup

---

## [LOW] Employee has no $hidden; employees index/show return bank_account, momo_number, cnps_number, tax_number, base_salary and allowances to hr.view and payroll.manage holders

- Fingerprint: `pii:hr:employee-model-returns-bank-tax-ids-to-view-roles`
- Likelihood medium / impact low; confidence high
- Intended behaviour: SelfService::profile masks bank_account/momo_number even for the employee themselves (SelfService.php:48-55)
- Root cause: app/Modules/HR/Domain/Employee.php:14 - only $guarded defined, no $hidden/serialisation whitelist

**Description.** Any hr.view user can export every colleague's bank account and MoMo number (useful for social engineering a payout diversion) and salary.

**Trace.**
- entrypoint: `app/Modules/HR/Http/HrController.php:52` (entrypoint) — GET /hr/employees returns Employee models paginated (full toArray)
- propagation: `app/Modules/HR/Http/HrController.php:63` (propagation) — showEmployee loads contracts (salary history) too
- propagation: `app/Modules/HR/Http/HrController.php:228` (propagation) — can('view') accepted for hr.view and payroll.manage
- sink: `app/Modules/HR/Domain/Employee.php:14` (sink) — only $guarded defined, no $hidden/serialisation whitelist

**Conditions.** data_state: user has hr.view

**Reproduction (local fixture only).** Staff user holding hr.view (e.g. department supervisors) 
1. GET /hr/employees returns Employee models paginated (full toArray)
1. only $guarded defined, no $hidden/serialisation whitelist

Observed/expected: Any hr.view user can export every colleague's bank account and MoMo number (useful for social engineering a payout diversion) and salary.

**Remediation.** Add an Employee API resource (or $hidden for bank_account, momo_number, cnps_number, tax_number) and expose those fields only to hr.manage/payroll.manage via an explicit endpoint.

---

## [LOW] Channel inbound webhooks for connections in 'sandbox' environment without a webhook_secret accept unsigned bookings

- Fingerprint: `public:channels:sandbox-unsigned-inbound`
- Likelihood medium / impact low; confidence high
- Intended behaviour: Each connection authenticates its provider by HMAC
- Root cause: app/Modules/Channels/Application/Drivers/BaseDriver.php:57 - verify(): return $c->environment === 'sandbox' when webhook_secret is empty -> true

**Description.** Fake OTA bookings (and XML payloads up to the body limit) can be injected into a hotel whose connection was left in sandbox with no secret; the uuid (whereUuid) is the only secret and appears in provider configuration screens/logs.

**Trace.**
- entrypoint: `app/Modules/Channels/Http/InboundController.php:35` (entrypoint) — receive(): $driver->verify() decides
- propagation: `app/Modules/Channels/Http/InboundController.php:46` (propagation) — BookingImporter::import() creates reservations
- sink: `app/Modules/Channels/Application/Drivers/BaseDriver.php:57` (sink) — verify(): return $c->environment === 'sandbox' when webhook_secret is empty -> true

**Conditions.** authentication_level: A connection with environment=sandbox, receive_reservations=true and no webhook_secret is reachable on the public host

**Reproduction (local fixture only).** Anyone who learns a sandbox connection uuid
1. receive(): $driver->verify() decides
1. verify(): return $c->environment === 'sandbox' when webhook_secret is empty -> true

Observed/expected: Fake OTA bookings (and XML payloads up to the body limit) can be injected into a hotel whose connection was left in sandbox with no secret; the uuid (whereUuid) is the only secret and appears in provider configuration screens/logs.

**Remediation.** Require a webhook_secret for every connection that receives reservations, sandbox included (generate one at connection creation).

---

## [LOW] Public digital check-in stores the ID photo from a base64 data URL with only a prefix check (no magic-byte/finfo validation), unlike the staff path

- Fingerprint: `public:checkin:id-photo-no-magic-check`
- Likelihood medium / impact low; confidence high
- Intended behaviour: Only JPEG/PNG/WebP images accepted (as enforced on the staff path)
- Root cause: app/Modules/Reservations/Experience/Application/DigitalCheckin.php:123 - storePhoto(): Storage::disk('local')->put("checkins/{hotel}/{id}-id.<ext>", base64 bytes) with extension taken from the declared mime, bytes never checked

**Description.** Arbitrary bytes up to 2 MB written under storage/app/checkins with a .jpg/.png/.webp name; when later decoded by image libraries (thumbnails, PDF embedding) malformed input reaches the decoder. No direct code execution path identified.

**Trace.**
- entrypoint: `app/Modules/GuestPortal/Http/ManageController.php:65` (entrypoint) — checkin(): id_photo string accepted
- propagation: `app/Modules/FrontOffice/Http/FrontDeskExtrasController.php:220` (propagation) — staff path uses finfo buffer check (store()) and Storage::disk('local')->response() serves by extension-derived mime; nosniff is set by SecurityHeaders so browser execution is unlikely
- sink: `app/Modules/Reservations/Experience/Application/DigitalCheckin.php:123` (sink) — storePhoto(): Storage::disk('local')->put("checkins/{hotel}/{id}-id.<ext>", base64 bytes) with extension taken from the declared mime, bytes never checked

**Conditions.** authorization_role: Staff or a PDF renderer later opens the file

**Reproduction (local fixture only).** Guest with a manage token (or attacker holding one via the email-reuse issue)
1. checkin(): id_photo string accepted
1. storePhoto(): Storage::disk('local')->put("checkins/{hotel}/{id}-id.<ext>", base64 bytes) with extension taken from the declared mime, bytes never checked

Observed/expected: Arbitrary bytes up to 2 MB written under storage/app/checkins with a .jpg/.png/.webp name; when later decoded by image libraries (thumbnails, PDF embedding) malformed input reaches the decoder. No direct code execution path identified.

**Remediation.** Reuse the finfo-based store() logic from FrontDeskExtrasController (check magic bytes/mime of the decoded bytes before writing).

---

## [LOW] Booking-site deposit and pay flows forward an arbitrary return_url to Orange Money / card gateway as return_url and cancel_url (open redirect after payment)

- Fingerprint: `public:deposit:return-url-forwarded-to-provider`
- Likelihood medium / impact low; confidence high
- Intended behaviour: Return to the booking site after payment
- Root cause: app/Modules/Payments/Application/Drivers/OrangeMoneyGateway.php:44 - return_url / cancel_url sent to the provider; CardGateway.php line 40 likewise

**Description.** After paying, the guest is sent by the provider to an attacker-chosen site (phishing continuation); needs the attacker to initiate the intent whose redirect_url the victim then follows.

**Trace.**
- entrypoint: `app/Modules/Payments/Http/PublicPaymentController.php:37` (entrypoint) — return_url validated only as 'url' (any host)
- propagation: `app/Modules/Payments/Application/PaymentService.php:107` (propagation) — stored in intent meta
- sink: `app/Modules/Payments/Application/Drivers/OrangeMoneyGateway.php:44` (sink) — return_url / cancel_url sent to the provider; CardGateway.php line 40 likewise

**Conditions.** third_party_dependency: Attacker must get the victim to use an intent they created (e.g. shared link with the provider redirect_url)

**Reproduction (local fixture only).** Attacker crafting a deposit request on behalf of a guest (requires manage token) or any pay-link payer
1. return_url validated only as 'url' (any host)
1. return_url / cancel_url sent to the provider; CardGateway.php line 40 likewise

Observed/expected: After paying, the guest is sent by the provider to an attacker-chosen site (phishing continuation); needs the attacker to initiate the intent whose redirect_url the victim then follows.

**Remediation.** Validate return_url host against config('app.url') (or allow only paths) before storing it.

---

## [LOW] Guest room-service order idempotency key (client_uuid) is looked up hotel-wide, not per reservation: another guest's order is returned for a colliding/guessed uuid

- Fingerprint: `public:guest-order:client-uuid-cross-reservation`
- Likelihood medium / impact low; confidence high
- Intended behaviour: Retry protection for one guest's own double tap
- Root cause: app/Modules/GuestPortal/Application/GuestOrderService.php:64 - GuestOrder::where('client_uuid', $clientUuid)->first() without reservation_id filter; the order is returned as if it were the caller's

**Description.** If uuids are predictable on the client, a guest can read another in-house guest's order totals/check ids and skip placing their own order; also an attacker can poison a uuid so that the real guest's first order silently returns the attacker's record.

**Trace.**
- entrypoint: `app/Modules/GuestPortal/Http/GuestOrderController.php:45` (entrypoint) — place(): client_uuid is any string <= 64 chars
- sink: `app/Modules/GuestPortal/Application/GuestOrderService.php:64` (sink) — GuestOrder::where('client_uuid', $clientUuid)->first() without reservation_id filter; the order is returned as if it were the caller's

**Conditions.** data_state: Guessable client_uuid values

**Reproduction (local fixture only).** Guest holding a signed ordering link for their own reservation
1. place(): client_uuid is any string <= 64 chars
1. GuestOrder::where('client_uuid', $clientUuid)->first() without reservation_id filter; the order is returned as if it were the caller's

Observed/expected: If uuids are predictable on the client, a guest can read another in-house guest's order totals/check ids and skip placing their own order; also an attacker can poison a uuid so that the real guest's first order silently returns the attacker's record.

**Remediation.** Add ->where('reservation_id', $r->id) to the client_uuid lookup (and a unique index on (reservation_id, client_uuid)).

---

## [LOW] FolioService::open consumes the gapless folio number outside a transaction, so the row lock is released immediately and concurrent opens collide on the unique (hotel_id, number) index

- Fingerprint: `race:folio:open-number-sequence-outside-transaction`
- Likelihood medium / impact low; confidence high
- Intended behaviour: NumberSequence docblock: 'must run inside the caller's transaction'
- Root cause: app/Platform/Domain/NumberSequence.php:20 - lockForUpdate() in autocommit mode: lock released after the SELECT, two callers read the same 'last'

**Description.** Under load (night audit + online deposit settle + front desk), one of the concurrent folio creations fails with a unique-violation 500 or two open guest folios are created for the same reservation, splitting charges across folios.

**Trace.**
- entrypoint: `app/Modules/FrontOffice/Application/FolioService.php:34` (entrypoint) — NumberSequence::next() called from Folio::create outside DB::transaction (contrast invoice() line 201 which wraps it)
- propagation: `app/Modules/FrontOffice/Database/Migrations/2026_11_02_000500_create_front_office_tables.php:100` (propagation) — unique(hotel_id, number) turns the duplicate into a 500 for one caller; guestFolioFor (FolioService:47) is also unlocked so two guest folios can be opened for one reservation
- propagation: `app/Modules/POS/Application/PosService.php:41` (propagation) — PosService::open has the same pattern for pos-check numbers (split() wraps it, open() does not)
- sink: `app/Platform/Domain/NumberSequence.php:20` (sink) — lockForUpdate() in autocommit mode: lock released after the SELECT, two callers read the same 'last'

**Conditions.** timing_dependency: concurrent folio/check creation for the same hotel

**Reproduction (local fixture only).** Two staff users / online payments opening folios at the same time
1. NumberSequence::next() called from Folio::create outside DB::transaction (contrast invoice() line 201 which wraps it)
1. lockForUpdate() in autocommit mode: lock released after the SELECT, two callers read the same 'last'

Observed/expected: Under load (night audit + online deposit settle + front desk), one of the concurrent folio creations fails with a unique-violation 500 or two open guest folios are created for the same reservation, splitting charges across folios.

**Remediation.** Wrap open() (and PosService::open) in DB::transaction; in guestFolioFor lock the reservation row first.

---

## [LOW] Approved data-protection requests anonymise the guest row, register entries, company contacts and activity log, but guest names/contacts persist in folios, invoices, payment intents, pay links and online check-ins

- Fingerprint: `retention:guests:erasure-does-not-cover-financial-and-payment-copies-of-personal-data`
- Likelihood high / impact low; confidence high
- Intended behaviour: Personal fields blanked across modules; financial amounts kept
- Root cause: app/Modules/FrontOffice/Application/FolioService.php:50 - folios.name is built from guest full_name and is never blanked; invoices.bill_to_name/address copy it at line 216-218

**Description.** After approval the guest still appears by name in folio lists, invoices, payment-intent searches (payer_phone ilike), pay links and online check-in records; erasure is partial and the audit entry claims full anonymisation.

**Trace.**
- entrypoint: `app/Modules/Guests/Application/PrivacyService.php:79` (PrivacyService::anonymise) — approved erasure request blanks guests (line 82), guest_register_entries (line 90-107), company_contacts (line 112) and activity_log (line 116) only - exactly what the docblock at line 78 promises
- propagation: `app/Modules/FrontOffice/Application/FolioService.php:50` (FolioService::guestFolioFor) — folios.name = guest full_name + reservation number at creation and never blanked
- propagation: `app/Modules/Payments/Http/PublicPaymentController.php:48` (PublicPaymentController::create) — payer_phone / payer_email copied into payment_intents and kept
- sink: `app/Modules/FrontOffice/Application/FolioService.php:216` (FolioService::invoice) — invoices.bill_to_name/address copied from folio/company; online_checkins (BookingEngineController line 170+) and reservations.special_requests also untouched

**Conditions.** data_state: Guest had a stay with folio, invoice, online payment or online check-in; authorization_role: Any later staff reader of folios/payment_intents/online_checkins

**Reproduction (local fixture only).** Staff user searching after an approved erasure
1. Create guest with reservation, folio, invoice, intent
1. Approve a delete request with a second user
1. Search folios / intents / online_checkins

Observed/expected: Original name/phone/email still visible in folios.name, payment_intents.payer_*, payment_links.payer_phone, online_checkins and reservation free text

**Remediation.** Extend anonymise() to blank folios.name, payment_intents.payer_phone/payer_email, payment_links.payer_phone, online_checkins document fields and reservations.special_requests/notes for the guest; keep invoices (legal retention) but document that in the request decision note.

---

## [LOW] Web 2FA pending state (2fa.user_id) is written into the pre-authentication session without regenerating the id

- Fingerprint: `session:2fa-pending-state-stored-before-session-regenerate`
- Likelihood medium / impact low; confidence high
- Intended behaviour: Session regeneration on privilege change
- Root cause: app/Http/Controllers/Auth/TwoFactorController.php:91 - verify() trusts 2fa.user_id; the full login regenerates (line 102) only after success

**Description.** An attacker holding the same session id knows the victim has entered a valid password (state 2fa.user_id present, /two-factor-challenge renders) and can race the TOTP step; practical impact is low because the final login regenerates, but the pending state and remember flag are unprotected

**Trace.**
- entrypoint: `app/Http/Controllers/Auth/AuthenticatedSessionController.php:40` (entrypoint) — session()->put('2fa.user_id', $user->id) with the current (possibly fixated) session id
- sink: `app/Http/Controllers/Auth/TwoFactorController.php:91` (sink) — verify() trusts 2fa.user_id; the full login regenerates (line 102) only after success

**Conditions.** data_state: session fixation or shared session cookie

**Reproduction (local fixture only).** Attacker who can plant a session cookie in the victim's browser (session fixation), or who shares a device
1. session()->put('2fa.user_id', $user->id) with the current (possibly fixated) session id
1. verify() trusts 2fa.user_id; the full login regenerates (line 102) only after success

Observed/expected: An attacker holding the same session id knows the victim has entered a valid password (state 2fa.user_id present, /two-factor-challenge renders) and can race the TOTP step; practical impact is low because the final login regenerates, but the pending state and remember flag are unprotected

**Remediation.** Call $request->session()->regenerate() before putting 2fa.user_id, and expire the pending state (store a timestamp, reject after 5 minutes)

---

## [LOW] Changing the staff password (and member password reset) does not invalidate other sessions or Passport tokens

- Fingerprint: `session:password-change-keeps-other-sessions-and-tokens`
- Likelihood medium / impact low; confidence high
- Intended behaviour: Password change as the user's remediation after suspected compromise
- Root cause: app/Http/Controllers/ProfileController.php:47 - password() updates the hash only; no Auth::logoutOtherDevices(), no tokens()->revoke

**Description.** Attacker's session (120 min / remember-me) and 12 h API tokens keep working after the victim rotates the password

**Trace.**
- entrypoint: `routes/web.php:144` (entrypoint) — POST /profile/password
- propagation: `app/Modules/MemberPortal/Application/MemberAuthService.php:195` (propagation) — member reset() same: other member sessions survive
- sink: `app/Http/Controllers/ProfileController.php:47` (sink) — password() updates the hash only; no Auth::logoutOtherDevices(), no tokens()->revoke

**Conditions.** data_state: prior session/token theft

**Reproduction (local fixture only).** Attacker who already holds a stolen session/token of the victim
1. POST /profile/password
1. password() updates the hash only; no Auth::logoutOtherDevices(), no tokens()->revoke

Observed/expected: Attacker's session (120 min / remember-me) and 12 h API tokens keep working after the victim rotates the password

**Remediation.** After a successful change: Auth::logoutOtherDevices($data['password']); $user->tokens()->update(['revoked'=>true]); for members delete other session rows for the account

---

## [LOW] Guest data-protection export and merge read/re-point reservations and loyalty rows of every hotel in the group, ignoring the actor's allowed hotels

- Fingerprint: `tenancy:guests:export-and-merge-ignore-allowed-hotels`
- Likelihood medium / impact low; confidence high
- Intended behaviour: GuestProfileController::scoped() limits raw guest reads to the actor's allowed hotels
- Root cause: app/Modules/Guests/Http/GuestController.php:145 - DB::table('reservations')->where('guest_id',$g->id)->get() and loyalty_memberships: raw query, no hotel_id / allowed-hotel filter, returns full rows of sister hotels (rates, dates, notes, totals)

**Description.** Hotel-A receptionist with guests.manage exports a VIP guest and obtains their complete stay history and amounts at hotel B; merge lets them silently re-assign hotel B invoices/folios to another guest

**Trace.**
- entrypoint: `app/Modules/Guests/Http/GuestController.php:139` (entrypoint) — GET /api/v1/guests/{id}/export (authorize 'manage' only)
- propagation: `app/Modules/Guests/Http/GuestController.php:112` (propagation) — merge(): DB::table($table)->where('guest_id',$dup->id)->update(guest_id) across reservations/folios/invoices of all hotels - a hotel-A user can rewrite hotel B's folio/invoice ownership
- propagation: `app/Modules/Guests/Http/GuestProfileController.php:166` (propagation) — contrast: history()/folios() apply scoped() with allowedHotelIds - the intended pattern
- sink: `app/Modules/Guests/Http/GuestController.php:145` (sink) — DB::table('reservations')->where('guest_id',$g->id)->get() and loyalty_memberships: raw query, no hotel_id / allowed-hotel filter, returns full rows of sister hotels (rates, dates, notes, totals)

**Conditions.** data_state: actor has guests.manage; data_state: actor all_hotels=false

**Reproduction (local fixture only).** Staff user with guests.manage limited to hotel A
1. GET /api/v1/guests/{id}/export (authorize 'manage' only)
1. DB::table('reservations')->where('guest_id',$g->id)->get() and loyalty_memberships: raw query, no hotel_id / allowed-hotel filter, returns full rows of sister hotels (rates, dates, notes, totals)

Observed/expected: Hotel-A receptionist with guests.manage exports a VIP guest and obtains their complete stay history and amounts at hotel B; merge lets them silently re-assign hotel B invoices/folios to another guest

**Remediation.** Reuse the scoped() helper (group_id + whereIn hotel_id allowed) for export; in merge() either require all_hotels or restrict the re-pointing updates to allowed hotels

---

## [LOW] Member sign-up links the new account to any guest whose phone ends with the same 9 digits

- Fingerprint: `tenancy:memberportal:phone-suffix-match-links-other-guest`
- Likelihood medium / impact low; confidence high
- Intended behaviour: OTP proves control of the contact; contact must identify exactly one guest
- Root cause: app/Modules/MemberPortal/Application/MemberAuthService.php:275 - matchGuest: whereRaw("length(digits) >= 9 and right(digits, 9) = ?", [last 9 digits]) - different country code / prefix with same trailing 9 digits matches; then account->guest_id = that guest

**Description.** Attacker registers +44xxx123456789 and is linked to a Cameroonian guest +237 123456789 (9 significant digits) -> sees and cancels that guest's bookings, downloads invoices

**Trace.**
- entrypoint: `routes/web/member.php:14` (entrypoint) — POST /account/register then /account/register/verify (OTP to the attacker's own phone)
- propagation: `app/Modules/MemberPortal/Application/MemberAuthService.php:109` (propagation) — verifySignup: $guest = matchGuest($contact); forceFill(['guest_id' => $guest->id, ...])
- propagation: `app/Modules/MemberPortal/Application/MemberBookings.php:40` (propagation) — all member reads are keyed on $a->guest_id -> full stay history, invoices PDFs, pay links, cancel
- sink: `app/Modules/MemberPortal/Application/MemberAuthService.php:275` (sink) — matchGuest: whereRaw("length(digits) >= 9 and right(digits, 9) = ?", [last 9 digits]) - different country code / prefix with same trailing 9 digits matches; then account->guest_id = that guest

**Conditions.** authorization_role: a staff-created guest whose phone has the same last 9 digits exists in the group; data_state: guest has no verified member account yet (profile_taken check)

**Reproduction (local fixture only).** Anonymous person who controls a phone number
1. POST /account/register then /account/register/verify (OTP to the attacker's own phone)
1. matchGuest: whereRaw("length(digits) >= 9 and right(digits, 9) = ?", [last 9 digits]) - different country code / prefix with same trailing 9 digits matches; then account->guest_id = that guest

Observed/expected: Attacker registers +44xxx123456789 and is linked to a Cameroonian guest +237 123456789 (9 significant digits) -> sees and cancels that guest's bookings, downloads invoices

**Remediation.** Match on the full E.164-normalised number (or require country code equality); when several guests match, do not auto-link (create a new profile and let staff merge)

---

## [INFORMATIONAL] PublicBookingResolver answers 403 'Invalid link' for a wrong manage token but 404 for an unknown reference, and 422 'ambiguous' when several bookings share a confirmation number: existence oracle on booking references

- Fingerprint: `public:booking:lookup-status-oracle`
- Likelihood high / impact informational; confidence high
- Intended behaviour: Comment: 'Never picks the first of several matches' and uniform 404 on name mismatch
- Root cause: app/Modules/GuestPortal/Application/PublicBookingResolver.php:121 - 422 'Several bookings share this confirmation number' returned before any ownership proof when lastName/token are absent (review path allows token null + last_name)

**Description.** Distinguishable responses let an attacker confirm that a confirmation number / 8-char public_code exists (public_code space is 31^8 so brute force is impractical; sequential confirmation numbers are trivially enumerable but reveal only existence).

**Trace.**
- entrypoint: `app/Modules/GuestPortal/Application/PublicBookingResolver.php:104` (entrypoint) — fail(404) when empty vs fail(403) on token mismatch (line 110) vs fail(422) ambiguous (line 121)
- sink: `app/Modules/GuestPortal/Application/PublicBookingResolver.php:121` (sink) — 422 'Several bookings share this confirmation number' returned before any ownership proof when lastName/token are absent (review path allows token null + last_name)

**Conditions.** authentication_level: public-write throttle 20/min per ip+uri limits volume

**Reproduction (local fixture only).** Anonymous internet user
1. fail(404) when empty vs fail(403) on token mismatch (line 110) vs fail(422) ambiguous (line 121)
1. 422 'Several bookings share this confirmation number' returned before any ownership proof when lastName/token are absent (review path allows token null + last_name)

Observed/expected: Distinguishable responses let an attacker confirm that a confirmation number / 8-char public_code exists (public_code space is 31^8 so brute force is impractical; sequential confirmation numbers are trivially enumerable but reveal only existence).

**Remediation.** Return the same 404 body for not-found, bad token and name mismatch; only emit the 'ambiguous' hint after the last name or token matched at least one candidate (it already does for the name path; make the token path identical).

---

## [INFORMATIONAL] Idempotency-Key replay cache for unauthenticated routes is keyed only by key|method|path, so any client can replay another client's stored response

- Fingerprint: `tenancy:idempotency:anonymous-scope-shared-across-clients`
- Likelihood low / impact informational; confidence high
- Intended behaviour: Idempotency keys are meant to be per client
- Root cause: app/Platform/Http/Idempotency.php:37 - returns stored response_body (e.g. booking confirmation with manage_token, pay-link intent) when key and request_hash match

**Description.** Mostly informational: a victim's response is replayable only with identical body; realistic abuse limited to replaying cancel/callback outcomes. Noted for completeness.

**Trace.**
- entrypoint: `app/Platform/Http/Idempotency.php:24` (entrypoint) — $scope = ('anon')|method|path for every public-routes.php request (middleware ['api','idempotent'])
- propagation: `app/Platform/Http/Idempotency.php:29` (propagation) — request_hash must match too, which makes exploitation need the identical body - practical only for low-entropy bodies (e.g. /payments/callbacks, /booking/{group}/reservations/cancel {confirmation, token})
- sink: `app/Platform/Http/Idempotency.php:37` (sink) — returns stored response_body (e.g. booking confirmation with manage_token, pay-link intent) when key and request_hash match

**Conditions.** data_state: attacker knows key + exact body

**Reproduction (local fixture only).** Anonymous client of public /api/v1 routes
1. $scope = ('anon')|method|path for every public-routes.php request (middleware ['api','idempotent'])
1. returns stored response_body (e.g. booking confirmation with manage_token, pay-link intent) when key and request_hash match

Observed/expected: Mostly informational: a victim's response is replayable only with identical body; realistic abuse limited to replaying cancel/callback outcomes. Noted for completeness.

**Remediation.** Include client IP (or skip caching) for anonymous requests; or require Idempotency-Key only on authenticated routes

---

