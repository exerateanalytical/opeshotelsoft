# Device certification (DV1, 2026-11-04)

## Device rules (one source of truth)
The class is decided once, before first paint, by the inline script in `resources/views/app.blade.php` (CSP nonce), and written to `<html data-device="phone|tablet|desktop" data-density="regular|compact">`. It re-runs on resize and on pointer changes and fires `opes:device`.

| Condition | Class |
|---|---|
| viewport under 768 CSS px (any input) | phone |
| mouse or trackpad (`hover: hover` and `pointer: fine`) and 900 CSS px or more | desktop (density `compact` under 1100 px) |
| touch and 1280 CSS px or more | desktop |
| anything else (touch 768-1279, mouse 768-899) | tablet |

- Server hint: `App\Platform\Support\DeviceHint` maps the User-Agent (simple regex) to a class; it pre-sets `data-device` in the HTML and is shared as the Inertia prop `device`. The client rule always overwrites it before paint.
- React: `useDevice()`, `useIsPhone()`, `useIsTablet()`, `useIsDesktop()`, `useDesktopAtLeast(px)` in `resources/js/lib/useDevice.ts`. `useMediaQuery` is kept only for non-layout queries; its `useIsPhone/useIsTablet` exports re-export the device hooks.
- CSS: in `app.css` the Tailwind variants `md:` (= not phone), `max-md:`, `lg:` (= desktop), `max-lg:` are redefined on `data-device`, and `phone:`, `tablet:`, `desktop:` variants exist. `sm:`, `xl:`, `2xl:` stay width-based (content sizing inside a device layout). Desktop type scale, n1 desktop table features, modal vs sheet, card-list tables and phone text rules all key off `data-device`. 44 px touch sizing keys off `pointer: coarse` only (touch only).
- Behaviour per class: phone = app bar + bottom tab bar + More sheet, sheets, card lists (DataTable and every plain `main table.table-auto`, labels copied from the header by `lib/tableLabels.ts`; opt out with `data-keep-table`), filter sheet. Tablet = icon rail, modals centred, tables with horizontal scroll. Desktop = persistent collapsible sidebar (narrower 208 px bar in compact density), tables, modals and centred sheets, keyboard shortcuts and command palette (DesktopShell), voice chip floating, master-detail split at desktop and 1280 px or more, content caps from AppLayout (`narrow/default/wide/full`).

## What was fixed
See docs/DEVICE-AUDIT.md (14 findings, 11 fixed, 3 by design). Files: `resources/views/app.blade.php`, `app/Platform/Support/DeviceHint.php`, `app/Http/Middleware/HandleInertiaRequests.php` (prop `device`), `resources/js/lib/useDevice.ts` (new), `resources/js/lib/useMediaQuery.ts`, `resources/js/lib/tableLabels.ts` (new), `resources/js/app.tsx`, `resources/css/app.css`, `Layouts/AppLayout.tsx`, `Components/layout/{Sidebar,DesktopShell}.tsx`, `Components/ui/{MasterDetail,ChartFrame,FilterBar}.tsx`, `Components/voice/VoiceNotifier.tsx`, `Pages/{Me/Index,Insights/Owner,Member/MemberLayout,Billing/Folios,Maintenance/Index,Reservations/TapeChart}.tsx`.

## Guards
- `tests/Feature/Platform/DeviceClassTest.php` (no database): UA hint mapping, the inline script runs before `@vite` with the nonce and the 900 px mouse rule, the variants are defined on `data-device`, no `(max-width: 1023px)` / `@media (min-width: 1024px)` layout switches remain in app.css, no component calls `useMediaQuery('(min|max-width…')`, AppLayout uses `useDevice()`.
- `scripts/ui-audit/device.cjs` (rendered): exits 1 if any desktop viewport shows the bottom tab bar, an icon rail or hidden sidebar, card lists, sheet filters or a narrow column; if any phone viewport shows the sidebar or a desktop table; if a touch tablet shows the tab bar or full sidebar; or on horizontal overflow. Run: `PUPPETEER_CORE=<dir containing puppeteer-core> node scripts/ui-audit/device.cjs --base http://localhost:8131 [--modes phone,tablet,desktop]`.

## Verification
- Final matrix (`scripts/ui-audit/results/device-final.json`): 139 routes x 12 viewports = 1668 checks, 0 findings, 0 load failures, 0 horizontal overflow.
- `npx tsc --noEmit` clean; build with `bash scripts/build-locked.sh` ok; `tests/Feature/Platform` 71/71 pass (before the last CSS tweak); UiStandardTest + DeviceClassTest pass after it.

## Residual items
- Sheets on touch tablets stay bottom sheets (intended for thumbs); only desktop gets the centred dialog.
- The phone card list covers tables inside `main`; tables inside modals/sheets on phones still scroll horizontally.
- Pages with their own internal layout (TapeChart, POS, boards) were only checked for shell, overflow and table mode, not redesigned.
- The audit uses the General Manager role; other roles see fewer menu entries but the same shell.
- `/hr/org-chart` and `/payments/1` are excluded from the narrow-column check (content-sized / empty state).

## Honest limits
- Browser zoom is a real width change: 200 % on a 1366 screen is 683 CSS px, which is a phone layout by design (WCAG reflow). 150 % on 1366 (911 px) stays desktop with compact density; under 900 px with a mouse it becomes the tablet rail.
- Hybrid touch laptops report their primary pointer; a Surface in laptop mode counts as mouse (desktop from 900 px), in tablet mode as touch (tablet under 1280 px).
- Zoom and scaling were emulated with CSS width plus deviceScaleFactor in headless Chrome, not the OS scaling UI; Safari and Firefox were not run.
- Without JavaScript the server UA hint is the only signal.

## Matrix (PASS = all viewports of that class clean)
| Area | Route | Phone 360/390/412 | Tablet 768/820/1024 | Desktop 1280/1366/1536@1.25/1920/1366@125%/1366@150% |
|---|---|---|---|---|
| member | /account/book | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| member | /account/bookings/1 | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| member | /account/bookings | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| member | /account/card | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| member | /account/chat | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| member | /account/hotels | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| member | /account/points | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| member | /account/profile | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| member | /account/requests | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| member | /account | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| public | /book/opes-demo/confirmation | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| public | /book/opes-demo/details | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| public | /book/opes-demo/manage | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| public | /book/opes-demo/review | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| public | /book/opes-demo/search | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| public | /book/opes-demo | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| public | /careers/opes-demo/1 | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| public | /careers/opes-demo | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| public | / | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| public | /forgot-password | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| public | /login | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| public | /verify/sample | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /admin/audit | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /admin/currencies | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /billing/cashier | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /billing/folios | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /billing/invoices/1 | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /billing/invoices | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /channels | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /dashboard | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /developer | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /documents/scheduled-reports | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /edge | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /finance/accruals | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /finance/bank-feed | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /finance/budgets | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /finance/cash-forecast | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /finance/consolidation | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /finance/dimensions | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /finance/dunning | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /finance/month-close | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /finance/payables-workflow | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /finance/payroll-filings | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /finance/statements-pack | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /finance/tax | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /finance | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /front-desk/in-house | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /front-desk | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /groups/1 | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /groups | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /guests/1 | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /guests/companies/1 | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /guests/privacy-requests | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /guests | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /housekeeping | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /hr/employees/1 | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /hr/lifecycle | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /hr/org-chart | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /hr/talent/compliance | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /hr/talent/planning | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /hr/talent/skills | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /hr/talent/training | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /hr/talent | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /hr/workforce/clock | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /hr/workforce/cost | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /hr/workforce/leave | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /hr/workforce/requests | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /hr/workforce/time | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /hr/workforce | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /hr | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /insights/alerts | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /insights/settings | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /loyalty/cards/1 | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /loyalty/counter | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /loyalty/members | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /loyalty/promotions | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /loyalty | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /maintenance | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /me | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /messaging/templates | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /messaging | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /notifications/outbox | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /notifications/templates | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /onboarding/branding | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /onboarding/import | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /onboarding/templates | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /onboarding | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /owner/group | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /owner | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /payments/1 | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /payments/gateways | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /payments/inbound | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /payments/links | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /payments/payouts/1 | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /payments/payouts | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /payments | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /people/discipline | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /people/exits | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /people/feedback | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /people/reviews | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /people/tasks | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /people | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /pos | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /profile | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /properties/fast-configure | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /properties | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /reports | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /reservations/1 | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /reservations/arrivals | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /reservations/channel-health | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /reservations/create | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /reservations/experience-settings | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /reservations/group-booking | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /reservations/rooming-import | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /reservations/seasons | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /reservations/tape-chart | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /reservations/travel-agents | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /reservations/waitlist | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /reservations | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /revenue/offers | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /revenue/overbooking | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /revenue/rules | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /revenue/suggestions | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /service-requests/settings | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /service-requests | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /settings/hotels | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /settings/users | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /stock/batches | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /stock/issues | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /stock/menu-engineering | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /stock/minibar | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /stock/recipes | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /stock/reorder | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /stock/rfq | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /stock/scan | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /stock/stocktake | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /stock/valuation | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /stock/variance | PASS 3/3 | PASS 3/3 | PASS 6/6 |
| staff | /stock | PASS 3/3 | PASS 3/3 | PASS 6/6 |
