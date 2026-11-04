# UI consistency matrix (R1)

Generated 2026-11-04 by `node scripts/ui-audit/static.cjs` from 237 `.tsx` files (197 under Pages). Regex based and counting literal class strings, so dynamic class composition is under-counted. "Non-conforming" means: bypasses the shared class or component in `resources/css/app.css` / `Components/ui` (see docs/UI-STANDARD.md). Re-run the script after changes; this file is overwritten.

## Summary

| Area | Variants found | Non-conforming | Where (top files, count) |
|---|---|---|---|
| Buttons | 5 variants (btn-accent 14, btn-secondary 504, btn-primary 336, btn-ghost 66, btn-danger 6); sizes btn-sm 92, btn-icon 66, btn-lg 25; `<Button>` component 78 | 102 raw `<button>` without a btn/nav/tab class; 51 shared-class buttons with local bg/radius/height overrides; 0 pill buttons | `Components/layout/Topbar.tsx` 10; `Pages/POS/Terminal.tsx` 8; `Pages/Member/MemberLayout.tsx` 6; `Components/ui/DataTable.tsx` 4; `Pages/Auth/MemberAuthPanel.tsx` 4; `Pages/Finance/Receivables.tsx` 4 |
| Inputs | `.input` (shared) | 14 of 716 text-like inputs without `.input` | `Pages/Auth/MemberAuthPanel.tsx` 6; `Pages/Auth/Login.tsx` 2; `Components/layout/CommandPalette.tsx` 1; `Components/layout/Topbar.tsx` 1; `Pages/Auth/TwoFactorChallenge.tsx` 1; `Pages/Landing/Index.tsx` 1; `Pages/Maintenance/Index.tsx` 1; `Pages/Verify/Show.tsx` 1 |
| Selects | `.select` | 1 of 311 without `.select` | `Components/auth/DemoSelect.tsx` 1 |
| Textareas | `.textarea` | 26 of 31 without `.textarea` | `Pages/Guests/Index.tsx` 2; `Pages/Housekeeping/Index.tsx` 2; `Pages/Insights/Settings.tsx` 2; `Pages/Maintenance/Index.tsx` 2; `Pages/Onboarding/Branding.tsx` 2; `Pages/Careers/Index.tsx` 1; `Pages/Finance/Reconciliation.tsx` 1; `Pages/Groups/Index.tsx` 1 |
| Field wrapper | `<Field>` 403 uses, `.label` class 251 | n/a | `Pages/HR/Employees.tsx` 41; `Pages/HR/Lifecycle.tsx` 24; `Pages/Stock/Index.tsx` 23; `Pages/Stock/Extras.tsx` 22 |
| Cards | `.card` 479, `.stat-tile` 2 | 37 hand-built bordered white boxes (rounded + border + bg-white, no `.card`) | `Pages/Landing/Index.tsx` 6; `Components/voice/VoiceNotifier.tsx` 2; `Pages/HR/SignContract.tsx` 2; `Pages/Me/Index.tsx` 2; `Pages/Public/Details.tsx` 2; `Pages/Public/Hotels.tsx` 2 |
| Tabs | `<Tabs>` 7, `.tab(s)` class 15 | 1 hand-rolled `role="tab"` outside the shared component | `Pages/Auth/Login.tsx` 1 |
| Tables | `<DataTable>` 5, raw `<table>` 137, `table-cards` (phone cards) 4 | 0 raw tables with no `table-wrap`/`table-auto`/`table-std` class | none |
| Modals and sheets | `<Modal>/<Sheet>` 22 | 2 hand-rolled `role="dialog"`; 2 `fixed inset-0` overlays outside Modal | `Components/layout/CommandPalette.tsx` 1; `Pages/Member/MemberLayout.tsx` 1; `Pages/Member/ConfirmSheet.tsx` 1; `Pages/Member/MemberLayout.tsx` 1 |
| Badges | 6 shared (badge-slate 50, badge-green 45, badge-yellow 31, badge-red 20, badge-blue 16, badge-navy 1) | 5 hand-built pills (rounded-full + tinted bg, no `badge-*`) | `Pages/Channels/Index.tsx` 4; `Pages/HR/shared.tsx` 1 |
| Alerts / callouts | no shared Alert component; `role="alert"/"status"` 62 | 17 hand-built tinted callout boxes | `Pages/Reservations/TapeChart.tsx` 5; `Components/voice/VoiceNotifier.tsx` 2; `Pages/Guests/Index.tsx` 2; `Pages/Property/FastConfigure.tsx` 2; `Pages/Billing/Folios.tsx` 1; `Pages/Guests/Companies.tsx` 1 |
| Typography | sizes sm 992, xs 361, base 64, lg 40, xl 17, 2xl 15, 3xl 8, 4xl 4, 5xl 1; weights font-semibold 393, font-medium 180, font-bold 31, font-normal 15 | 14 arbitrary `text-[Npx]`; 23 `uppercase`; tracking tracking-widest 3, tracking-wide 3, tracking-wider 2 | `Pages/Landing/Index.tsx` 5; `Components/layout/Topbar.tsx` 4; `Pages/ServiceRequests/Board.tsx` 3; `Pages/Member/MemberLayout.tsx` 2; uppercase: `Pages/Property/Crud.tsx` 2; `Pages/Stock/Recipes.tsx` 2; `Components/layout/Topbar.tsx` 1; `Pages/Admin/Currencies.tsx` 1 |
| Icons | lucide-react 120 imports; sizes (h=w) 4 344, 5 125, 3 26, 3.5 19, 6 9, 11 9, 10 8, 8 5, 9 1 | 1 inline `<svg>` outside shared; 6 emoji-as-icon matches | inline svg: `Pages/HR/EmployeeProfileSections.tsx` 1; emoji: `Pages/Reservations/TapeChart.tsx` 2; `Pages/FrontDesk/Index.tsx` 1; `Pages/Housekeeping/Index.tsx` 1; `Pages/Public/Hotels.tsx` 1 |
| Spacing | Tailwind 4 px base | 268 off-scale utilities (5 114, 0.5 61, 1.5 37, 10 23, 9 16, 2.5 15, 7 2); 105 arbitrary `-[Npx]` sizes or offsets | `Components/layout/Topbar.tsx` 18; `Pages/Property/FastConfigure.tsx` 15; `Pages/HR/EmployeeProfileSections.tsx` 6; `Pages/Channels/Index.tsx` 5; `Pages/Member/MemberLayout.tsx` 5; `Components/layout/BottomNav.tsx` 4 |
| Colours | palette hues used: slate 1608, red 308, emerald 158, amber 136, navy 124, blue 35, brand 28, yellow 19, sky 7, green 7, indigo 5, orange 3 | 33 `text-slate-300/400`; 680 utilities from non-brand hues (red 308, amber 136, emerald 158, blue 35, yellow 19, sky 7, green 7, violet 2, orange 3, indigo 5) | see docs/UI-TOKENS.md |
| Shadows / glow | none allowed | 1 `shadow/ring/blur` utilities in source (neutralised by theme + global `box-shadow:none`) | `Pages/Billing/InvoiceShow.tsx` 1 |
| Radius | lg 124, undefined 71, full 24, md 23, xl 3, 2xl 3, sm 1 | 0 arbitrary `rounded-[..]` | none |
| Navigation | `nav-item` 5 refs; shell from `Components/layout` | n/a (see register for per-route nav state) | |
| Page headers | `<PageHeader>` 0, `<AppLayout title=>` 119, raw `<h1>` in Pages 13 | 13 raw h1 (AppLayout already renders the h1; a second h1 breaks the rule of one per page unless on a public page) | `Pages/Auth/ForgotPassword.tsx` 1; `Pages/Auth/Login.tsx` 1; `Pages/Auth/MemberAuthPanel.tsx` 1; `Pages/Auth/TwoFactorChallenge.tsx` 1; `Pages/Careers/Index.tsx` 1; `Pages/Careers/Offer.tsx` 1; `Pages/HR/SignContract.tsx` 1; `Pages/Landing/Index.tsx` 1 |

## Details

### Buttons
Shared class counts: `btn-accent` 14, `btn-secondary` 504, `btn-primary` 336, `btn-ghost` 66, `btn-danger` 6. Hand-rolled buttons (no btn/nav/tab/hit-44 class), top files: `Components/layout/Topbar.tsx` 10; `Pages/POS/Terminal.tsx` 8; `Pages/Member/MemberLayout.tsx` 6; `Components/ui/DataTable.tsx` 4; `Pages/Auth/MemberAuthPanel.tsx` 4; `Pages/Finance/Receivables.tsx` 4; `Pages/Reports/Index.tsx` 4; `Components/voice/VoiceNotifier.tsx` 3; `Pages/Housekeeping/Index.tsx` 3; `Pages/Property/FastConfigure.tsx` 3; `Pages/Reservations/Create.tsx` 3; `Pages/Developer/Index.tsx` 2; `Pages/POS/FloorPlan.tsx` 2; `Pages/Public/Manage.tsx` 2; `Components/layout/BottomNav.tsx` 1.
Shared class with local overrides (bg-, rounded-, h-, py-, px-, text size): `Pages/POS/Terminal.tsx` 12; `Components/CashierBar.tsx` 3; `Pages/Public/Manage.tsx` 3; `Pages/Careers/Index.tsx` 2; `Pages/Careers/Offer.tsx` 2; `Pages/Developer/Index.tsx` 2; `Pages/HR/Organisation.tsx` 2; `Pages/HR/Payroll.tsx` 2; `Pages/HR/SignContract.tsx` 2; `Pages/HR/WorkforceClock.tsx` 2.
Pill (rounded-full) buttons: 0; the directive says "no needless pills".

### Form controls
Text-like inputs 716 (non-conforming 14), selects 311 (1), textareas 31 (26). Checkbox, radio, hidden, file, range and colour inputs are excluded (covered by the global hit-area rule).

### Z-index in source
Utilities: 10 4, 20 3, 30 3, [40 3, 50 2, [30 2, [70 2, 40 1. Tokens: --z-sticky 20, --z-bar 30, --z-dropdown 40, --z-sheet 50, --z-modal 60, --z-toast 70. Raw numeric `z-NN` / `z-[NN]` usages: 13 (`Pages/Reservations/TapeChart.tsx` 4; `Pages/Member/MemberLayout.tsx` 3; `Components/voice/VoiceNotifier.tsx` 1; `Pages/Finance/Receivables.tsx` 1; `Pages/Landing/Index.tsx` 1; `Pages/Member/ConfirmSheet.tsx` 1; `Pages/Property/FastConfigure.tsx` 1; `Pages/Reservations/Create.tsx` 1); inline `zIndex:` 0.

### Variants to consolidate (proposal)
1. Add a shared `<Alert tone="info|success|warning|danger">` (icon + text + role) and replace the 17 hand-built callouts. Status then never relies on colour alone.
2. Replace 5 hand-built pills with `badge-*` (add `badge-orange`-style tones only if a real status needs it).
3. Replace 102 raw `<button>` with `<Button>` or a shared class where they are real actions (tab-like, chip and icon-only toggles may stay but need `hit-44`).
4. Remove local bg/rounded/h overrides on shared btn classes (51).
5. Move 8 files' bare inputs to `.input`.

## UX7b cleanup (design-system debt)

Shared `<Alert>` now exists (`Components/ui/Alert.tsx`, classes `.alert-*` in app.css); the "no shared Alert component" wording in the Alerts row above is stale because it is hard-coded in the generator. Before and after: hand-built callouts 92 to 17 (the remaining 17 are status pills, chart cells, list items and notification links, not callouts); selects without `.select` 257 to 1 (DemoSelect, a false positive of the regex); raw tables without a wrapper class 29 to 0; raw buttons 128 to 102 (icon-only close, remove and edit buttons moved to `btn-ghost btn-icon btn-sm` with aria-label; the rest are tabs, menu items, list rows, chips and link-style buttons that style themselves on purpose).
