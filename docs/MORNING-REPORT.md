# Morning report (2026-11-04)

## State
Everything is committed and on GitHub (main). Full test suite: 35 folders, all passing on a fresh database. `npm run build` and `npx tsc --noEmit` are clean.

## Built overnight
- Security: audit with 0 critical, 10 high fixed; second pass fixed 18 medium/low items (docs/security-audit/FIXES-TODO.md). Required-role 2FA on API tokens, TOTP replay protection, separation of duties in payroll and HR, trusted proxies configured by env, signed channel inbound bookings.
- Accounting controls: attachments required on legacy entries, two-person period reopen (docs/ACCOUNTING-CONTROLS.md).
- Speed: page navigation from about 1.1-1.9 s to 0.13-0.19 s with `scripts/serve-fast.sh`; payload 275 kB to 14.5 kB.
- UI: design-system rollout, states (loading, empty, error, offline), desktop shortcuts and context menus, 144-screen extended audit with 0 P0.
- Documents, member portal, voice alerts, Fast Configure, landing page, brand logo, edge node, HR 360 profile (earlier in the session).

## Decisions taken for you
- HR officer cannot see pay (grant `payroll.view` to change).
- General manager can approve another user's period reopen request; reopen is always two-person.
- Public multi-room booking deferred. Mobile app deferred.
- Orange Money payouts stay in dry-run until verified against the contract.
- Stay credits do not exist in the data model; not built.

## Act on these before production
- Channel connections created before now refuse inbound bookings until a `webhook_secret` is set; there is no rotate endpoint yet.
- Run the permission seeder so `loyalty.adjust_large` exists.
- Set `TRUSTED_PROXIES` behind a proxy (docs/DEPLOY.md). Turn `DEMO_LOGINS` off.
- Corporate codes are now long (`PREFIX-` plus 13 characters); give companies the full code.
- Staff without hotels/users view permission see fewer fields; ledger requests over 366 days return 422.

## Open
- Not done: edge nonce replay protection, accounting self-approval redesign, sequential confirmation number lookup on the public booking API, DryRunGateway behind a flag.
- Lazy chart chunk, WebP brand images, sending nav and permissions once.
- Console error `(l.data ?? []).reduce is not a function` seen once on a stock page: not re-checked in a browser.
- 4 P1 (200% text zoom) and 55 P2 screens in docs/UI-REGISTER.md.

## Run it
`PHP=/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php HOST=0.0.0.0 bash scripts/serve-fast.sh 8000`
Tests: `php artisan test tests/Feature/<Folder>` on a scratch database.
