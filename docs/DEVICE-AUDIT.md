# Device audit register (DV1, 2026-11-04)

Owner report: "On desktop, many pages serve me mobile pages." Harness: `scripts/ui-audit/device.cjs` (puppeteer-core, own server on port 8131, General Manager demo login, member demo login, guest). Routes: all of `scripts/ui-audit/routes.json` (117 staff, 10 public, 2 auth, 10 member = 139). Viewports: phone 360/390/412 (mobile + touch), tablet 768/820/1024 (touch), desktop 1280/1366/1536 at DPR 1.25/1920 (mouse), 1366 at 125 % (1093 CSS px, DPR 1.25) and 150 % (911 CSS px, DPR 1.5).

Each viewport is probed for: bottom tab bar, sidebar width (hidden / icon rail / full), tables shown with a header row vs card lists, filters collapsed into a sheet button, a narrow centred column, and horizontal overflow.

## Root cause
Before this work the phone / tablet / desktop decision was made only from viewport width, in five separate places (`useIsPhone` / `useIsTablet`, `DesktopShell` `min-width:1024px`, `VoiceNotifier` `max-width:1023px`, `Maintenance` and `Folios` own queries, plus 230 scattered `md:` / `lg:` utilities and the `@media` blocks in app.css). A Windows laptop at 125-150 % display scaling has 900-1100 CSS px, so a mouse user got the tablet icon rail, tablet type scale, 44 px touch sizing, no keyboard shortcuts or command palette and no master-detail; touch tablets at 1024 px got the full desktop sidebar. Some pages also hard-coded a phone design.

## Findings
"All staff routes" = the 117 staff routes, because the symptom comes from the shared shell (verified on the old build on /admin/audit, /admin/currencies, /billing/cashier, then reproduced by rule on every route).

| # | Route | Device | Symptom | Cause | Fixed |
|---|---|---|---|---|---|
| 1 | all staff routes | desktop 1366 at 150 % (911 px), any mouse window 900-1023 px | icon-only rail instead of sidebar; tablet type scale; 44 px touch controls; no shortcuts / command palette | width-only gates (`useIsTablet`, `DesktopShell` 1024 px, `lg:`) | y |
| 2 | all staff routes | desktop 900-1023 px | voice control moved into the app bar (phone/tablet placement) | `VoiceNotifier` `max-width:1023px` | y |
| 3 | list pages with MasterDetail, /billing/folios | desktop | split view only from 1280 px by width, also offered to touch | `useSplitView` / Folios own query | y (desktop and >= 1280) |
| 4 | /maintenance | desktop 900-1023 px | detail opened as a full-page modal instead of side panel | own `min-width:1024px` | y |
| 5 | pages using `<Sheet>` (filters, pickers) | desktop | bottom sheet on a wide screen | `.overlay.is-sheet` was always bottom-anchored | y (centred dialog on desktop) |
| 6 | all staff routes | touch tablet 1024 px (iPad landscape) | full desktop sidebar and desktop density on touch | width-only `lg:` | y (icon rail) |
| 7 | /me (employee self-service) | desktop, all widths | phone-only screen: no sidebar, no shell, single stacked column | page rendered its own phone header, outside AppLayout | y (AppLayout, wrapping tabs, 4xl cap) |
| 8 | /owner (Insights owner) | desktop | single 576 px centred phone column of KPI tiles | `mx-auto max-w-xl`, 2-column tile grid | y (4 KPI columns, 2-column cards) |
| 9 | member portal (/account/*) | desktop | content capped at 768 px | `max-w-3xl` | y (1024 px on desktop) |
| 10 | 88 pages with plain `table-auto` tables (e.g. /dashboard arrivals, /reports, /stock, /hr, /finance...) | phone | desktop tables (side-scrolling) on phones | only DataTable had the card-list mode | y (global phone card list + header labels) |
| 11 | /guests, /reservations, /billing/invoices | tablet 768/820/1024 | page wider than screen by 36-371 px | absolutely positioned bits in DataTable cells escaped the scroll box | y (`.table-wrap` is the containing block) |
| 12 | /forgot-password, /login | desktop | narrow centred card | by design (sign-in card) | n/a |
| 13 | /hr/org-chart | desktop | content narrower than half the pane | tree is content-sized | n/a (by design) |
| 14 | /payments/1 | desktop | narrow content | demo data has no payment 1 (empty state) | n/a |

No route showed the bottom tab bar on a desktop width, a card list instead of a table on desktop, or the sidebar on a phone; those are now enforced by the guard.

Result after fixes: `device-final.json`, 139 routes x 12 viewports = 1668 checks, 0 findings, 0 load failures. Matrix in docs/DEVICE-CERTIFICATION.md.
