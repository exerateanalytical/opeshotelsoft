# h2-auth notes — authentication / authorization / session / config / supply chain

Source-only review, 2026-11-04. Laravel 13.39, Passport 13.10, spatie/permission. No files outside this scratch dir were touched.

## A. Route coverage

### Mounting (verified)
- `app/Modules/*/routes.php` -> `/api/v1`, middleware `['api','auth:api','tenant','idempotent']` (app/Platform/ModuleServiceProvider.php:45-48). Sub-files `require`d: Accounting routes_core/routes_ab, HR Workforce/Talent/Profile, Reservations Revenue/Experience, Stock Control/Operations, Property FastConfigure.
- `app/Modules/*/public-routes.php` -> `/api/v1`, `['api','idempotent']` (line 51). HR public also requires Talent/public-routes.php.
- Web: routes/web.php + routes/web/*.php (glob require), `web` group = session, CSRF (ValidateCsrfToken default, no exclusions found: grep validateCsrfTokens/except in bootstrap+app -> none), HandleInertiaRequests, CreateFreshApiToken.
- Passport vendor routes `/oauth/*` are ALSO registered (Passport::$registersRoutes=true, no ignoreRoutes()).

### Authorization pattern
Web pages: `can:` or `PermissionMiddleware` on every staff page except those listed below. API: no route-level `can:` except Notifications/messaging and Reservations/experience groups; authorization lives in controllers via `abort_unless($u->can(...))`, `$this->need()/any()/allow()/view()/manage()/authorize()` helpers (ResourceController::authorize, POS ConfiguresPos, People Authorizes trait, RevenueController::need, Talent/Workforce ::need, Stock Control/Operations ::allow, PurchasingExtras ::any, Loyalty ::view/cardsAbility/promoAbility, FrontDeskExtras ::view/manage). A method-count scan (public methods vs. authorization calls per controller) was used to find gaps; every remaining gap was read by hand.

### Routes reachable by ANY authenticated staff without a permission (web)
| Method | URI | Middleware | Controller check | Verdict |
|---|---|---|---|---|
| GET | /dashboard | auth | none (widgets filter by perms) | OK: page shell only; data via API |
| GET | /profile, POST /profile, /profile/password, /profile/two-factor*, DELETE /profile/two-factor | auth (+throttle on confirm) | self only | OK (password change: see candidate session:password-change) |
| GET | /me | auth | API answers 403 if not an employee | OK |
| GET | /tasks | auth | TaskHome API filters by perms | OK |
| GET | /hr/workforce/requests, /hr/workforce/clock | auth | self-service by design | OK |
| POST | /logout | auth | – | OK |
| fallback | * | auth | NotFound page | OK |
| GET/POST/PUT/DELETE | /oauth/tokens, /oauth/clients, /oauth/personal-access-tokens, /oauth/authorize, /oauth/scopes, /oauth/device/* | web, auth | Passport only checks ownership | CANDIDATE authz:passport:oauth-management-routes-open-to-all-staff |

### Routes reachable by ANY authenticated staff without a permission (API /api/v1)
| Method | URI | Controller check | Verdict |
|---|---|---|---|
| GET | me | self | OK |
| POST | auth/logout | revokes own token | OK |
| GET | hotels, hotels/{id} | HotelController index/show: none | CANDIDATE authz:tenancy:hotels-and-staff-directory (low) |
| GET | staff | StaffController: none (id,name,roles) | same candidate |
| GET | exports, exports/{report} | ExportController -> Exporter -> InternalApi sub-request re-runs the target report's own permission check (InternalApi.php:27-55 copies Authorization/Cookie/user resolver) | OK |
| GET | documents/invoices|folios|registration-cards|payslips|purchase-orders/{id} | DocumentController via InternalApi (same mechanism) | OK |
| GET | tasks/home | any staff; sections filtered per permission | OK |
| GET | reports/dashboard/compose | DashboardComposeController (widgets per role; tests/Feature/Platform/DashboardRolesTest) | OK |
| GET | hr/me/* , workforce/my-shifts, open-shifts, clock/{dir}, swaps, requests | self-service: controller resolves `me()` employee, 403 otherwise (WorkforceController:45,193,213-217) | OK |
| GET | people/reviews/mine, people/tasks/mine, hr/payslips/mine, hr/onboarding-tasks/mine | self-service | OK |
| GET | payroll/payslips/{payslip} | PayrollController checks payroll.* or owner | OK |
| GET | hr/employees/{employee}/profile/* | ProfileController (HR) checks hr.view/manage/payroll.manage | OK |

Every other API action read has a `can()`-style check in its controller (counts matched public method counts after accounting for private helpers; MessagingController and Experience/Tape controllers are guarded at route level).

### Unauthenticated routes
| Method | URI | Protection | Verdict |
|---|---|---|---|
| GET | / , /login, /forgot-password (page only, no POST handler), /two-factor-challenge | guest | OK; /login lists demo accounts when DEMO_LOGINS (candidate config:demo-logins) |
| POST | /login | guest, throttle:10,1, LoginGuard email+IP (5/300s), lockout 10 -> 15 min | CANDIDATE authn:web-login:suspended-status-ignored; rate-limit XFF |
| POST | /demo-login/{login}, /demo-member-login/{n} | config('app.demo_logins'), throttle:20,1 | CANDIDATE config:demo-logins |
| POST | /two-factor-challenge | guest, throttle:10,1, bound to session 2fa.user_id, failures count toward lockout | OK (minor: candidate session:2fa-pending-state) |
| POST | /locale | CSRF | OK |
| POST | /demo-request | throttle:5,10 | OK (form) |
| POST | /account/register, register/verify, login, login/code, login/code/verify, forgot, reset | throttle:20,1 per IP + identifier|IP limiter 5/300s + OTP 5 attempts/10 min + account lock | OK (candidate authn:member:register-overwrites-pending-account, low) |
| GET | /verify/{code}, /api/v1/verify/{code} | throttle:verify 30/min | OK (code regex 1-40 chars; entropy not reviewed here) |
| GET | /book/{group}/*, /careers/*, /pay/{code}/{sig}, /sign/contract/{token}, /g/order/{reservation} (signed) | throttle:public-read; signed/HMAC/48-char tokens | OK for auth purposes (pay sig = 40-bit HMAC prefix, hash_equals, 404 on mismatch; combined with public-write throttle) |
| POST | /api/v1/auth/token, auth/token/two-factor, auth/login | throttle:auth-token 10/min/IP + LoginGuard + 2FA challenge (encrypted, nonce in cache, 5 attempts, 300 s) | OK; MFA-required-but-unenrolled bypass = blocker #20 |
| POST/GET | /api/v1/booking/{group}/** (hotels, availability, reservations, lookup, cancel, check-in, manage/*, upsell/*, deposit) | public-read/public-write throttles; manage_token = HMAC(app.key, group|hotel|id|number|guest) (GuestLinks.php:63) returned after confirmation+last_name lookup | OK (token never rotates/revokes: hardening) |
| POST | /api/v1/channels/inbound/{uuid}, payments/callbacks/{provider}/{reference}, payments/inbound/{provider}/{hotel}, webhooks/whatsapp | provider signatures (WhatsApp X-Hub-Signature-256 hash_equals; DryRunGateway HMAC) — detailed review belongs to the payments/integrations hunter | not re-verified here beyond auth presence |
| POST | /api/v1/edge/sync/push|pull | HMAC with node secret + timestamp (+/-300 s), no nonce | CANDIDATE authn:edge-sync:replay-window (low) |
| GET/POST | /api/v1/hr/sign/{token}/*, /careers/offers/{token}/* | 48-char tokens, stored sha256(token), OTP 6 digits + attempts | OK |
| POST | /oauth/token, GET /oauth/authorize, POST /oauth/device/code | Passport; no clients seeded; password grant not enabled (no Passport::enablePasswordGrant) | OK |
| GET | /up | health | OK |

## B. Authentication review (paths read)
- app/Http/Controllers/Auth/AuthenticatedSessionController.php — throttle + lockout + regenerate after login OK; **no status check** (candidate). 2FA pending state session-bound to user id (OK), stored before regenerate (low candidate). remember flag honoured.
- app/Http/Controllers/Auth/TwoFactorController.php — challenge requires 2fa.user_id; verify consumes state, regenerates; enrol/confirm/regenerate/disable require auth; disable needs current password and is refused for REQUIRED_ROLES. Recovery codes hashed (bcrypt), single use. mfa_secret `encrypted` cast; hidden on serialisation.
- app/Modules/Admin/Domain/Totp.php — RFC 6238, 20-byte secret, window +/-1, hash_equals; **no replay protection** (low candidate).
- app/Modules/Admin/Application/ApiTokenIssuer.php — checks throttle, lock, status=active, password, 2FA if enabled; challenge token = Crypt::encryptString(user,nonce,expiry) + cache nonce, 5 attempts. OK. MFA *required-but-unenrolled* not enforced (blocker #20, confirmed; AuthController::assertMfaFor never called).
- app/Modules/Admin/Http/TokenController.php, app/Modules/Tenancy/Http/AuthController.php — personal access tokens via createToken; expiry 12 h (AppServiceProvider:25-27); logout revokes current token only.
- Passport: config/passport.php guard web; keys in storage/oauth-*.key (gitignored `/storage/*.key`); no password grant; vendor routes registered (candidate). TokenGuard (v13.10) verifies X-CSRF-TOKEN / X-XSRF-TOKEN for the laravel_token cookie path (TokenGuard.php:235,264-285); Passport::$ignoreCsrfToken not set anywhere in app.
- MemberPortal: MemberAuthController/MemberAuthService/MemberAuth middleware — OTP random_int 6 digits, 10 min, 5 attempts, HMAC(app.key) stored; identifier|IP limiter; lockout; constant-time; enumeration-safe responses; startSession logs out web guard then logs in member and regenerates (fixation OK); MemberAuth requires verified+guest-linked member, nulls user resolver so staff session never counts; staff `auth` middleware uses web guard so member sessions cannot reach staff routes. Same session cookie, but guards are distinct keys in the session; `/login` redirects members to /account.
- Demo logins: DemoLoginController/DemoMemberLoginController gated by config('app.demo_logins') = DEMO_LOGINS && APP_ENV !== 'production' (config/app.php:22) → 'prod','staging' pass (candidate). Demo session sets `demo_login` which bypasses MFA enrolment (HandleInertiaRequests:125).
- Impersonation: grep -ri impersonat app → none.
- Staff password reset: no backend flow exists (only the ForgotPassword page). Informational.
- UserController: users.manage (held by '*' roles group-owner/general-manager only, seeder lines 29-30) can assign any role incl. group-owner and edit own roles; cross-group update blocked (line 63). Hardening note, not a candidate (both roles are already '*').

## C. CSRF / CORS / session / proxies / redirects
- CSRF: web group default ValidateCsrfToken, no exclusions. API via cookie protected by Passport CSRF check; bearer tokens unaffected. Member routes are web routes (CSRF applies).
- CORS: no config/cors.php → framework defaults (paths api/*, origins *, supports_credentials=false) → cookies never sent cross-origin; OK.
- config/session.php: driver database, lifetime 120, http_only true, same_site lax, secure = env (null locally; .env.production.example sets SESSION_SECURE_COOKIE=true), encrypt false (fine with http_only + TLS).
- SecurityHeaders: nonce CSP, frame-ancestors self, HSTS in production over HTTPS. OK.
- trustProxies('*') → X-Forwarded-For spoof of ip() (candidate rate-limit). docs/DEPLOY.md:57 and :202 themselves recommend restricting.
- Open redirects: only `redirect()->intended(route('dashboard'))` (session-stored intended URL from auth middleware; internal). No request-driven redirect targets found. Member login JSON returns fixed '/account'.
- Host header: APP_URL used by url()/route(); SecurityHeaders/TwoFactorRequired use url() → with trustProxies('*') X-Forwarded-Host is also trusted (same root cause as the proxy candidate; emails use config app.url via Notifier — not separately verified).
- Password policy: staff Password::min(10) (profile, user creation); members min 8 + letters + numbers. Logout: web invalidates session + token regen; API logout revokes only the current token.

## D. Secrets / config
- .env.example: APP_DEBUG=true, LOG_LEVEL=debug (local). .env.production.example: APP_DEBUG=false, DEMO_LOGINS=false, SESSION_SECURE_COOKIE=true. OK.
- ErrorPages: debug page only when config app.debug && 5xx && not api; ProblemDetails exposes messages only for 400/409/422 after a safeMessage() filter (no SQLSTATE/paths). OK.
- PerfDebug: only local + PERF_DEBUG. OK.
- Logging: WhatsAppClient dry-run logs full payload (dev only); PaymentService records provider callback `$request->all()` into activity log (payload may contain phone/amounts — informational).
- Hard-coded secrets: none in app/config (grep). Seeder passwords Admin@2026!/Demo@2026! in DemoUsersSeeder, invoked by default DatabaseSeeder (candidate).
- Encryption at rest: users.mfa_secret (encrypted), mfa_recovery_codes (bcrypt), channels.connections.credentials (encrypted:array), payment_gateway_settings.credentials (encrypted:array), edge_nodes.secret (encrypted), e-contract tokens sha256, member OTP HMAC. Key rotation: none implemented (APP_KEY rotation would break all encrypted columns — hardening).
- Backups: app/Modules/Platform/Ops/Application/BackupService.php + BackupCipher.php exist (not reviewed in depth here; belongs to ops hunter).
- Deploy: Dockerfile, docker-compose.yml (requires DB_PASSWORD/REDIS_PASSWORD, no defaults), docker/entrypoint.sh runs migrate --force only (no seed), deploy/nginx+supervisor+systemd. CI: .github/workflows/ci.yml — no explicit `permissions:` block (defaults to repo setting), pinned major-version actions (checkout@v4, setup-php@v2, cache@v4, setup-node@v4), runs migrate/test/pint/tsc/eslint/build; no deploy secrets used.

## E. Supply chain (raw)
- `composer audit` (2026-11-04): "No security vulnerability advisories found." JSON: advisories [], abandoned []. (scratch/composer-audit.txt, composer-audit.json)
- `npm audit --omit=dev`: 0 vulnerabilities (info 0, low 0, moderate 0, high 0, critical 0); deps prod 117, dev 95, optional 109, total 271. (scratch/npm-audit-prod.json)
- `npm audit`: identical, 0 vulnerabilities. (scratch/npm-audit-all.json)

## SAFE list (reviewed, no finding)
- Web page RBAC: every staff page in routes/web.php and routes/web/*.php carries can:/PermissionMiddleware except the intentionally-open shells listed in A (dashboard, profile, me, tasks, workforce self-service).
- API controller RBAC: ResourceController, POS, Stock Control/Operations, Accounting (all 5 controllers), HR (Hr, HrExtras, Lifecycle, Payroll, Talent, Workforce, Profile), People, Reservations (all), Revenue, Experience (route-level), Housekeeping, Maintenance, Insights, Loyalty, Guests, Channels, Edge admin, Documents (Branding, Template, Issued, Scheduled, Money/Ops docs), Notifications/Messaging, Onboarding, Payments (Payment, MobileMoney), Platform webhooks, FastConfigure, Admin audit/currencies, Tenancy users — all check permissions.
- Export/Document rendering: InternalApi re-dispatches through the router with the caller's credentials, so report permissions apply.
- Tenant scoping of API requests: ResolveTenant validates X-Hotel-Id against group + allowed hotel ids (tenancy details are another hunter's scope).
- Web 2FA challenge binding, recovery code hashing and consumption, TOTP secret entropy, API 2FA challenge token (encrypted + nonce + attempts + TTL).
- Member portal: OTP entropy/lifetime/attempt cap, enumeration-safe answers, lockout, session regeneration, guard separation (member vs web), MemberAuth nulling the staff user resolver.
- Passport cookie CSRF protection (TokenGuard::validCsrf active), token lifetimes 12 h, keys gitignored.
- CSRF on all web state-changing routes (no exclusions), CORS credentials off, cookie http_only/lax, CSP with nonce, HSTS in production.
- Error handling: no stack traces/messages in API problem+json except filtered 4xx detail; debug page only with APP_DEBUG and never for api/*.
- Open redirects: none. Host header: only via the proxy-trust issue.
- composer/npm audit: clean.

## Hardening notes (not candidates)
- Role assignment has no hierarchy guard (users.manage may grant group-owner / edit own roles); currently only '*' roles hold users.manage, so no escalation across a trust boundary. Consider forbidding self-role edits and granting roles above the assigner's.
- No staff "forgot password" flow; admins reset by creating a new password via users API (ensure users.manage password sets force a change).
- API logout revokes only the current token; profile has no "sign out everywhere".
- TOTP has no last-used-step; recovery codes 10 chars lowercase alnum (~51 bits) fine.
- 2FA web challenge: pending state never expires until session does.
- manage_token for public bookings is a deterministic HMAC (cannot be rotated/revoked without changing app.key or the token version string).
- Idempotency: see low candidate; also stores response bodies of public endpoints in DB.
- Edge sync: add nonce; revoke flow exists.
- PaymentService activity log stores raw callback payloads; consider redaction.
- Key rotation procedure for APP_KEY (encrypted columns) is undocumented.
- CI workflow lacks an explicit least-privilege `permissions:` block.
- HandleInertiaRequests reads an unencrypted `hotel_id` cookie from the raw Cookie header; only used to pick among the user's allowed hotels (safe) but bypasses EncryptCookies conventions.
- /login discloses the full demo roster (names + sections) when DEMO_LOGINS is on; combined with demo passwords in the repo this is a public credential list on any demo host (intended for demos).

## Reviewed paths (complete list)
bootstrap/app.php; routes/web.php; routes/api.php; routes/web/*.php (28 files); app/Platform/ModuleServiceProvider.php; app/Modules/*/routes.php and public-routes.php (all 26 + sub-route files); app/Platform/Http/{ResourceController,Idempotency,ErrorPages,ProblemDetails}.php; app/Platform/Tenancy/ResolveTenant.php; app/Http/Middleware/{HandleInertiaRequests,SecurityHeaders,PerfDebug}.php; app/Http/Controllers/Auth/{AuthenticatedSessionController,TwoFactorController,DemoLoginController,DemoMemberLoginController}.php; app/Http/Controllers/ProfileController.php; app/Models/User.php; app/Modules/Admin/{AdminModule.php,Http/TokenController.php,Application/{ApiTokenIssuer,TwoFactor,TwoFactorRequired,LoginGuard}.php,Domain/Totp.php}; app/Modules/Tenancy/Http/{AuthController,UserController,HotelController,StaffController}.php; app/Modules/MemberPortal/{Http/MemberAuthController.php,Http/Middleware/MemberAuth.php,Application/MemberAuthService.php}; app/Modules/Documents/{Http/{DocumentController,ExportController}.php,Application/{InternalApi,Exporter}.php}; app/Modules/Reservations/Http/TapeChartController.php; app/Modules/Reports/TaskHome/TaskHomeController.php; app/Modules/People/Http/Authorizes.php; app/Modules/POS/Http/ConfiguresPos.php; app/Modules/Edge/{Http/EdgeSyncController.php,Application/CloudSync.php (auth part)}; app/Modules/Notifications/Http/WhatsAppWebhookController.php; app/Modules/Payments/Application/PayLinkService.php (signature); app/Modules/GuestPortal/{Http/BookingEngineController.php (token parts),Application/GuestLinks.php}; app/Modules/HR/{Http/ContractSigningController.php,Application/Lifecycle/EContractService.php (token/OTP lines),Talent/Http/CareersController.php}; app/Modules/Platform/Ops/OpsBootstrap.php (rate limits); app/Providers/AppServiceProvider.php; config/{app,auth,session,passport}.php; vendor/laravel/passport/{src/Guards/TokenGuard.php,src/PassportServiceProvider.php,src/Passport.php,routes/web.php}; database/seeders/{DatabaseSeeder,DemoSeeder,DemoUsersSeeder,RolesAndPermissionsSeeder}.php; .env.example; .env.production.example; .gitignore; Dockerfile; docker-compose.yml; docker/entrypoint.sh; docker/nginx/default.conf; deploy/*; .github/workflows/ci.yml; docs/DEPLOY.md (proxy sections); tests/Feature/Platform/RbacMatrixTest.php (test names); resources/js/Pages/Auth/Login.tsx (demo roster rendering). Authorization-call counts were computed for every controller under app/Modules/**/Http.
