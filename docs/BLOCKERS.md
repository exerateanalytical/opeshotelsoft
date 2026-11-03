# Blockers for the owner (to resolve)

Updated 2026-11-02 after the overnight build. Nothing here stopped the build; each item needs admin rights on this PC, an outside party, or a decision only you can make.

## A. Access from this PC and from phones (needs admin rights)

**Working right now, without admin:** the app is served on **http://192.168.1.189:8000** (and http://127.0.0.1:8000 on this PC) by a background `php artisan serve` started tonight. It stops if the PC restarts or that terminal closes. Phones on the same Wi-Fi can use it **once the firewall allows port 8000 (item 3)**.

1. **Restart Apache / Laragon (admin).** Apache runs elevated, so I could not restart it. Two configs are ready:
   - `C:\laragon\etc\apache2\sites-enabled\auto.opeshotelsoft.test.conf` → `http://opeshotelsoft.test`
   - `C:\laragon\etc\apache2\sites-enabled\opeshotelsoft-lan.conf` → LAN on **http://192.168.1.189:8088** (permanent replacement for :8000)
   In Laragon: *Menu → Apache → Reload*, or *Stop All* then *Start All*.
2. **Hosts entry for `.test` (admin).** Laragon adds it automatically when it runs as administrator. Otherwise add this line to `C:\Windows\System32\drivers\etc\hosts` (Notepad as administrator):
   `127.0.0.1 opeshotelsoft.test`
3. **Windows Firewall (admin)** so phones and other PCs can connect. PowerShell as administrator:
   `New-NetFirewallRule -DisplayName "OpesHotelSoft LAN" -Direction Inbound -Protocol TCP -LocalPort 80,8000,8088 -Action Allow -Profile Private`
   Make sure the Wi-Fi network is set to **Private** in Windows settings.
4. **`.test` on phones.** Phones cannot read this PC's hosts file, so `opeshotelsoft.test` will not resolve on them. Use **http://192.168.1.189:8088** (after items 1 & 3). For a name, add a DNS record on the router or run a LAN DNS (e.g. Acrylic DNS on this PC) and point the phones' Wi-Fi DNS at 192.168.1.189. Reserve 192.168.1.189 for this PC in the router's DHCP settings so the address does not change.
5. **Queue worker and scheduler.** Webhooks and channel pushes use the database queue. For real-time pushes keep a worker running: `php artisan queue:work`, and add the Laravel scheduler (`php artisan schedule:work`, or a Windows Task running `php artisan schedule:run` every minute). Tonight queue jobs were verified in tests (sync mode).
6. **Redis is not running.** Sessions use the database driver (`.env`) so the site works without it. Start Redis in Laragon before production-like testing.

## B. Decisions for you

7. **Web UI approach.** I kept the Inertia shell, but the staff screens now call the same public API as everyone else (Passport API cookie). The dashboard is live; the other screens are still placeholders. Confirm this hybrid, or say if you want a separate SPA.
8. **Open questions in SPEC §11:** pilot hotel, launch countries, Mobile Money route (direct or aggregator), channel manager preference, hosting.
9. **Demo data** uses a fictional group "Opes Demo Hotels" (slug `opes-demo`). Test logins are listed in `database/seeders/DemoSeeder.php` (local only). Two leftover users from the first session exist in the database (`manager@krystalpalace.cm` with no group, and old `web`-guard roles); they have no access to the new API. Say if you want them removed.

## C. Outside parties (cannot be done by code)

10. **Accountant certification (SYSCOHADA).** Chart of accounts, posting rules, VAT 19.25 % (17.5 % + CAC), tourist tax 1 000 XAF/night and other countries' VAT defaults are configuration from public sources. A chartered accountant must review `app/Modules/Accounting/Domain/ChartOfAccounts.php`, `Application/PostingRules.php` and `Application/Provisioner.php`.
11. **Payroll certification (Cameroon).** CNPS 4.2 % / 4.2 % / 7 % / 1.75 % with 750 000 ceiling, IRPP scale, CAC, CFC, FNE, RAV and TDL tables in `app/Modules/HR/Application/Payroll/CameroonRulePack.php` must be checked against the current Finance Law, CNPS rules and the hotel collective agreement. Until then the rule pack is marked **not certified** and production refuses to approve payroll. Record certification with `POST /api/v1/payroll/rule-packs/{id}/certify`.
12. **Channel partner programmes.** Each needs a partner agreement, credentials and the channel's own certification test before going live:
    - Booking.com Connectivity Partner programme (B.XML machine account)
    - Expedia Group connectivity partner (EQC credentials per property)
    - Tripadvisor TripConnect / metasearch partner
    - Channex, MyAllocator, Cloudbeds and SiteMinder partner/API accounts
    Until credentials are entered, every connection runs in **dry-run**: exact payloads are logged in the sync log but not sent. Exact endpoint paths for Cloudbeds and SiteMinder must be confirmed during onboarding.
13. **Payment and messaging providers** — MTN MoMo, Orange Money and a card acquirer need merchant accounts and API keys (enter them in *Payment gateways*); SMS/WhatsApp/email need provider keys (`NOTIFY_*` in `.env`). Until then payments run in dry-run and messages go to the log.
14. **Penetration test and PCI DSS scoping** before any production hotel (SPEC §10.4).

## D. After phase 2 (2026-11-03) — see docs/PLAN-PHASE-2.md

Phase 2 built: every staff screen, role-based menus and page guards (checked per role), FR/EN shell, 2FA (TOTP) for owner/GM/finance/accountant, audit log, currencies, public booking site, payments (MTN, Orange, card — dry-run), notifications (email/SMS/WhatsApp — log driver), PDFs and Excel/CSV exports, scheduled reports, tape chart, waitlist, seasons, travel agents, front-desk extras, guest/company detail, data-protection requests, POS tables/happy hour/KOT/comps, requisitions, price lists, supplier returns, payment runs, bank reconciliation, fixed assets, cash flow, DSF export, FX revaluation, year-end close, HR documents/org chart/onboarding/overtime/holidays, Flutter app (`mobile/`).

Still open:
15. **Android SDK (you).** The Flutter app analyses and tests clean, but no APK can be built until the Android SDK is installed and `ANDROID_HOME` is set; then run `flutter build apk --debug` in `mobile/`.
16. **Translation of module screens — done 2026-11-03** (docs/PLAN-I18N-MODULES.md, 2 406 keys in lang/{fr,en}/*.json). Still English: labels sent by the server (cash-flow and DSF lines, report titles, API error messages) and staff-entered names. Printed invoice stays French by design. Vocabulary (note client, allotement, arrhes, contrepasser…) awaits review by hotel staff.
17. **Hotel edge node** (offline-first server, SPEC §7.5a) — next phase.
18. **Gate-3 reviews added by phase 2 (accountant):** DSF line mapping (`Accounting/Application/FinancialStatements::DSF_MAP`), VAT treatment of POS comps (account 658100), FX revaluation accounts. **Payroll expert:** overtime multipliers (weekday/night/Sunday/holiday) in the Cameroon pack.
19. **Provider gaps:** MTN does not sign callbacks (driver expects an HMAC from a relay); Orange Money has no refund API; WhatsApp outside 24 h needs Meta-approved templates. Decide per provider during onboarding.
20. **2FA on mobile/API logins** (password grant) is not enforced yet — web only.
21. **Your PostgreSQL has ~430 leftover test databases from other projects** (opesinsure 210, cth, fecapme, opescare…). They made recovery after the restart take 35 minutes. Clean them from those projects.
22. SSO (SAML/OIDC) for enterprise groups; door locks (v2); biometric clocks and fiscal e-invoicing (need vendor/authority specs).
