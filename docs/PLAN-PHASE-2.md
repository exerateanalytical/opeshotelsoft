# Phase 2 plan — close every gap between SPEC and the build

Date: 2026-11-03. Owner: lead agent. Source of truth for scope: `docs/SPEC.md` §4–6.

## Method (applies to every workstream)

1. **Plan**: this document defines the scope, the files each workstream owns, and its done criteria.
2. **Build**: one agent per workstream, working only in the files it owns.
3. **Verify (agent)**: feature tests for every new endpoint and rule, run against the workstream's private test DB; no type errors in its files.
4. **Verify (lead)**:
   - Full test suite and production build.
   - Migrations run on the dev DB.
   - Every new screen opened in the browser under the right demo role.
   - RBAC matrix test passes.
5. **Certify**: the lead updates `docs/modules/CERTIFICATION-REGISTER.md`. Gates 2, 3 and 6 stay open until a named human signs them off (domain expert, accountant, payroll expert). The lead never marks them done.

## Hard rules for all workstreams

### UI rules
- **Flat design:** no glows, no coloured shadows, no blur, no gradients, no translucent coloured halos on focus. Focus is a solid outline (already global).
- **Readable text:** body text is `text-sm` (15px). `text-xs` (13px) is for labels and badges only. Never use an arbitrary size below 13px.
- **Server-side permissions:** every endpoint checks a permission, and every page needs one. Hiding a menu entry is not security.
- **Money and dates:** money is integer FCFA. Dates use the hotel business date where relevant.
- **Phone width:** every screen works at 375px.

### File ownership
- Each workstream edits only its listed files.
- Shared extension points:
  - `routes/web/<workstream>.php` for web pages.
  - `database/seeders/permissions/<workstream>.php` for new permissions and role grants.
  - Its own `app/Modules/<Module>` and `resources/js/Pages/<Area>`.
- Only W05 may run `composer require`. No workstream runs `npm install`, except W12, which works inside `mobile/` only.
- Each workstream uses its own test DB (`opeshotelsoft_test_wNN`) and runs only its own tests.

## Workstreams

| # | Workstream | SPEC | Owns | Done when |
|---|---|---|---|---|
| W01 | Platform shell: RBAC navigation, page guards, French/English UI, 2FA, audit log viewer, currencies and exchange rates | §2, §4.1, §5 | `routes/web.php`; `Components/layout/*`; `Layouts/*`; `lib/i18n.ts` (new); `lang/`; `HandleInertiaRequests`; `app/Http/*`; `Pages/Auth`, `Pages/Settings`, `Pages/Profile`, `Pages/Dashboard`; new module `app/Modules/Admin` | **Sidebar:** the menu is built only from the user's permissions, and each staff web route has a `can:` guard. **RBAC:** a matrix test logs in as each of the 20 demo roles and asserts its visible menu and 200/403 per page. **French/English:** a language switch is saved per user, and the shell, auth, dashboard and settings are translated. **2FA:** TOTP is required for finance and admin roles. **Audit and currencies:** an audit log screen with filters; currencies and exchange-rate CRUD. |
| W02 | Guest booking website and guest portal | §4.14 | `app/Modules/GuestPortal`, `resources/js/Pages/Public/*`, `routes/web/public.php` | **Site:** a public site per hotel group (search, book, confirmation), with no login needed. **Manage booking:** look up a booking with confirmation number and last name, cancel within policy, online check-in form (ID, ETA), pay deposit (via W03), loyalty card view. **Languages:** French and English. |
| W03 | Payments: MTN MoMo, Orange Money, card | §6 | new `app/Modules/Payments` | **Gateway:** an interface with drivers for MTN, Orange and a card gateway, in sandbox or dry-run without keys. **Payment flow:** create a payment intent; a signed callback webhook that is idempotent; failed payments are not posted. **Posting:** a successful payment posts to the folio through the FrontOffice service. **Refunds** supported. **Admin screen:** gateway credentials, masked. |
| W04 | Notifications: email, SMS, WhatsApp | §4.1, §6 | new `app/Modules/Notifications` | **Channel drivers:** a log driver when no keys are set. **Templates:** in French and English, with variables. **Event-driven:** sends booking confirmation, cancellation, pre-arrival, invoice and OTP messages. **Reliability:** sends are queued with retry. **Preferences** per guest. **Screen:** templates and outbox log. |
| W05 | Documents and exports | §4.4, §4.12, §4.13 | `composer.json` (sole owner); new `app/Modules/Documents`; `resources/views/pdf/*` | **PDFs:** invoice, folio, registration card, and payslip in French and English (from the API). **Exports:** Excel/CSV for every report endpoint through a generic exporter. **Scheduled reports:** emailed via W04 when it exists, otherwise logged. |
| W06 | Reservations extras | §4.3 | `app/Modules/Reservations` (except `AriController`/`Pricing` reads), `Pages/Reservations/*` (not `Show.tsx`), `Pages/Groups/*` | **Tape chart:** a room-by-day calendar with drag to move or assign. **Waitlist screen.** **Seasons:** in rate plans. **Travel agents:** with commission. **Cross-property booking:** book a sister hotel from the same screen. **Blocks:** the block list `status` filter works. |
| W07 | Front desk extras | §4.4 | `app/Modules/FrontOffice`, `Pages/FrontDesk/*`, `Pages/Billing/*`, `Pages/Reservations/Show.tsx` | **In-house list page.** **Early and late check-out:** with fee rules. **Room move:** a UI for moving a guest to another room. **Routing rules:** room charges to the company, extras to the guest. **Registration card:** ID capture (photo upload) and signature pad, stored. **Cashier shift list:** includes the user name and filters. |
| W08 | Guests, CRM and POS extras | §4.5, §4.9 | `app/Modules/Guests`, `app/Modules/POS`, `Pages/Guests/*`, `Pages/POS/*` | **Guest detail page:** stays across hotels, folios and loyalty. **Company detail page:** contacts, statement link, negotiated rates. **Data protection:** a deletion or anonymisation request workflow. **POS:** table plan, price levels and happy hour, a printable kitchen ticket view, and comps that need approval. |
| W09 | Stock, procurement and payables extras | §4.10, §4.11 | `app/Modules/Stock`, `Pages/Stock/*` | **Purchasing:** requisition → PO, supplier price lists, returns to supplier, and wastage reasons. **Supplier payables:** a payment run (select due invoices, pay in batch) and AP ageing. |
| W10 | Accounting extras | §4.11 | `app/Modules/Accounting`, `Pages/Finance/*` | **Reconciliation:** bank and Mobile Money (import CSV statement, match, post differences). **Assets:** fixed asset register with depreciation runs. **Reports:** cash-flow statement and DSF-ready export. **Currency:** foreign-currency gains and losses. **Year-end close.** |
| W11 | HR extras | §4.12 | `app/Modules/HR`, `Pages/HR/*` | **Employee records:** documents upload, org chart, onboarding checklist. **Time and leave:** overtime rules feeding payroll; public holidays per country feeding leave and overtime. **Payslips:** language per employee. |
| W12 | Mobile app (Flutter): guest and staff | §4.14 | `mobile/` only | **Guest side:** login, booking search and book, my bookings, loyalty card with QR. **Staff side:** housekeeping tasks, work orders, my payslips and leave. **Offline:** works offline for staff tasks (local queue and sync). **Builds:** `flutter analyze` is clean, the debug APK builds, and the base URL is configurable for LAN use. |

## Not in this phase (and why)

- **Hotel edge node (offline-first server, §7.5a):** needs W03, W04 and W12 to settle first. It is the next phase.
- **Door locks:** planned for v2 in the spec.
- **Biometric clocks and fiscal e-invoicing:** need vendor and authority specifications. These are tracked in `docs/BLOCKERS.md`.
- **Channel partner certification, accountant and payroll sign-off:** these are human gates.

## Lead verification checklist (after all agents finish)

- [ ] `php artisan migrate` on dev, then reseed roles and permissions.
- [ ] Full `php artisan test` is green.
- [ ] `npm run build` is clean, and `tsc` has no code errors.
- [ ] RBAC matrix test is green. Browser check as owner, GM, receptionist, accountant, HR, storekeeper and cashier.
- [ ] Every new page opened in the browser with no console errors, at desktop and phone width.
- [ ] Sidebar entries added for every new page, each with its permission.
- [ ] `CERTIFICATION-REGISTER.md` and `BLOCKERS.md` updated.
