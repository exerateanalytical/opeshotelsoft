# UI consistency matrix (R1)

Generated 2026-11-04 by `node scripts/ui-audit/static.cjs` from 235 `.tsx` files (197 under Pages). Regex based and counting literal class strings, so dynamic class composition is under-counted. "Non-conforming" means: bypasses the shared class or component in `resources/css/app.css` / `Components/ui` (see docs/UI-STANDARD.md). Re-run the script after changes; this file is overwritten.

## Summary

| Area | Variants found | Non-conforming | Where (top files, count) |
|---|---|---|---|
| Buttons | 5 variants (btn-accent 13, btn-secondary 494, btn-primary 327, btn-ghost 37, btn-danger 6); sizes btn-sm 63, btn-icon 39, btn-lg 23; `<Button>` component 77 | 129 raw `<button>` without a btn/nav/tab class; 52 shared-class buttons with local bg/radius/height overrides; 0 pill buttons | `Components/layout/Topbar.tsx` 10; `Pages/POS/Terminal.tsx` 9; `Pages/Member/MemberLayout.tsx` 6; `Components/ui/DataTable.tsx` 4; `Components/voice/VoiceNotifier.tsx` 4; `Pages/Auth/MemberAuthPanel.tsx` 4 |
| Inputs | `.input` (shared) | 14 of 716 text-like inputs without `.input` | `Pages/Auth/MemberAuthPanel.tsx` 6; `Pages/Auth/Login.tsx` 2; `Components/layout/CommandPalette.tsx` 1; `Components/layout/Topbar.tsx` 1; `Pages/Auth/TwoFactorChallenge.tsx` 1; `Pages/Landing/Index.tsx` 1; `Pages/Maintenance/Index.tsx` 1; `Pages/Verify/Show.tsx` 1 |
| Selects | `.select` | 257 of 310 without `.select` | `Pages/Stock/Index.tsx` 17; `Pages/HR/Employees.tsx` 13; `Pages/HR/Lifecycle.tsx` 10; `Pages/Maintenance/Index.tsx` 9; `Pages/Billing/Folios.tsx` 6; `Pages/HR/TalentPlanning.tsx` 6; `Pages/FrontDesk/Partials/StayPanels.tsx` 5; `Pages/Groups/Index.tsx` 5 |
| Textareas | `.textarea` | 26 of 31 without `.textarea` | `Pages/Guests/Index.tsx` 2; `Pages/Housekeeping/Index.tsx` 2; `Pages/Insights/Settings.tsx` 2; `Pages/Maintenance/Index.tsx` 2; `Pages/Onboarding/Branding.tsx` 2; `Pages/Careers/Index.tsx` 1; `Pages/Finance/Reconciliation.tsx` 1; `Pages/Groups/Index.tsx` 1 |
| Field wrapper | `<Field>` 402 uses, `.label` class 248 | n/a | `Pages/HR/Employees.tsx` 41; `Pages/HR/Lifecycle.tsx` 24; `Pages/Stock/Index.tsx` 23; `Pages/Stock/Extras.tsx` 22 |
| Cards | `.card` 458, `.stat-tile` 2 | 38 hand-built bordered white boxes (rounded + border + bg-white, no `.card`) | `Pages/Landing/Index.tsx` 6; `Components/voice/VoiceNotifier.tsx` 2; `Pages/HR/SignContract.tsx` 2; `Pages/HR/TalentShared.tsx` 2; `Pages/Me/Index.tsx` 2; `Pages/Public/Details.tsx` 2 |
| Tabs | `<Tabs>` 7, `.tab(s)` class 15 | 1 hand-rolled `role="tab"` outside the shared component | `Pages/Auth/Login.tsx` 1 |
| Tables | `<DataTable>` 5, raw `<table>` 137, `table-cards` (phone cards) 4 | 29 raw tables with no `table-wrap`/`table-auto`/`table-std` class | `Pages/HR/Lifecycle.tsx` 5; `Pages/People/Reviews.tsx` 3; `Pages/HR/TalentSkills.tsx` 2; `Pages/HR/WorkforceCost.tsx` 2; `Pages/HR/WorkforceRoster.tsx` 2; `Pages/Onboarding/Import.tsx` 2 |
| Modals and sheets | `<Modal>/<Sheet>` 22 | 2 hand-rolled `role="dialog"`; 2 `fixed inset-0` overlays outside Modal | `Components/layout/CommandPalette.tsx` 1; `Pages/Member/MemberLayout.tsx` 1; `Pages/Member/ConfirmSheet.tsx` 1; `Pages/Member/MemberLayout.tsx` 1 |
| Badges | 6 shared (badge-slate 50, badge-green 45, badge-yellow 31, badge-red 20, badge-blue 16, badge-navy 1) | 5 hand-built pills (rounded-full + tinted bg, no `badge-*`) | `Pages/Channels/Index.tsx` 4; `Pages/HR/shared.tsx` 1 |
| Alerts / callouts | no shared Alert component; `role="alert"/"status"` 62 | 94 hand-built tinted callout boxes | `Pages/Reservations/TapeChart.tsx` 5; `Pages/HR/SignContract.tsx` 3; `Pages/Loyalty/Index.tsx` 3; `Pages/Maintenance/Index.tsx` 3; `Pages/Profile/Index.tsx` 3; `Pages/Property/FastConfigure.tsx` 3 |
| Typography | sizes sm 1080, xs 371, base 68, lg 40, xl 19, 2xl 17, 3xl 7, 4xl 4, 5xl 1; weights font-semibold 399, font-medium 180, font-bold 31, font-normal 15 | 11 arbitrary `text-[Npx]`; 35 `uppercase`; tracking tracking-widest 6, tracking-wide 5, tracking-wider 4 | `Pages/Landing/Index.tsx` 5; `Pages/ServiceRequests/Board.tsx` 3; `Pages/Member/MemberLayout.tsx` 2; `Components/voice/VoiceNotifier.tsx` 1; uppercase: `Components/layout/Topbar.tsx` 3; `Pages/Auth/Login.tsx` 2; `Pages/Landing/Index.tsx` 2; `Pages/Property/Crud.tsx` 2 |
| Icons | lucide-react 122 imports; sizes (h=w) 4 348, 5 133, 3 26, 3.5 19, 6 13, 11 10, 10 8, 8 5, 9 1 | 1 inline `<svg>` outside shared; 7 emoji-as-icon matches | inline svg: `Pages/HR/EmployeeProfileSections.tsx` 1; emoji: `Pages/Reservations/TapeChart.tsx` 2; `Pages/FrontDesk/Index.tsx` 1; `Pages/Housekeeping/Index.tsx` 1; `Pages/Member/Hotels.tsx` 1 |
| Spacing | Tailwind 4 px base | 271 off-scale utilities (5 113, 0.5 66, 1.5 36, 10 23, 9 16, 2.5 15, 7 2); 103 arbitrary `-[Npx]` sizes or offsets | `Components/layout/Topbar.tsx` 18; `Pages/Property/FastConfigure.tsx` 15; `Pages/HR/EmployeeProfileSections.tsx` 6; `Pages/Channels/Index.tsx` 5; `Pages/Member/MemberLayout.tsx` 5; `Components/layout/BottomNav.tsx` 4 |
| Colours | palette hues used: slate 1666, red 481, emerald 238, amber 169, navy 123, blue 35, brand 29, yellow 19, green 10, sky 7, indigo 6, orange 3 | 33 `text-slate-300/400`; 970 utilities from non-brand hues (red 481, amber 169, emerald 238, blue 35, yellow 19, sky 7, green 10, violet 2, orange 3, indigo 6) | see docs/UI-TOKENS.md |
| Shadows / glow | none allowed | 1 `shadow/ring/blur` utilities in source (neutralised by theme + global `box-shadow:none`) | `Pages/Billing/InvoiceShow.tsx` 1 |
| Radius | lg 183, undefined 75, md 38, full 24, xl 3, 2xl 3, sm 1 | 0 arbitrary `rounded-[..]` | none |
| Navigation | `nav-item` 5 refs; shell from `Components/layout` | n/a (see register for per-route nav state) | |
| Page headers | `<PageHeader>` 0, `<AppLayout title=>` 119, raw `<h1>` in Pages 13 | 13 raw h1 (AppLayout already renders the h1; a second h1 breaks the rule of one per page unless on a public page) | `Pages/Auth/ForgotPassword.tsx` 1; `Pages/Auth/Login.tsx` 1; `Pages/Auth/MemberAuthPanel.tsx` 1; `Pages/Auth/TwoFactorChallenge.tsx` 1; `Pages/Careers/Index.tsx` 1; `Pages/Careers/Offer.tsx` 1; `Pages/HR/SignContract.tsx` 1; `Pages/Landing/Index.tsx` 1 |

## Details

### Buttons
Shared class counts: `btn-accent` 13, `btn-secondary` 494, `btn-primary` 327, `btn-ghost` 37, `btn-danger` 6. Hand-rolled buttons (no btn/nav/tab/hit-44 class), top files: `Components/layout/Topbar.tsx` 10; `Pages/POS/Terminal.tsx` 9; `Pages/Member/MemberLayout.tsx` 6; `Components/ui/DataTable.tsx` 4; `Components/voice/VoiceNotifier.tsx` 4; `Pages/Auth/MemberAuthPanel.tsx` 4; `Pages/Finance/Receivables.tsx` 4; `Pages/Reports/Index.tsx` 4; `Pages/Developer/Index.tsx` 3; `Pages/Finance/Journal.tsx` 3; `Pages/Housekeeping/Index.tsx` 3; `Pages/HR/Lifecycle.tsx` 3; `Pages/Loyalty/Members.tsx` 3; `Pages/Maintenance/Index.tsx` 3; `Pages/Property/FastConfigure.tsx` 3.
Shared class with local overrides (bg-, rounded-, h-, py-, px-, text size): `Pages/POS/Terminal.tsx` 12; `Components/CashierBar.tsx` 3; `Pages/Landing/Index.tsx` 3; `Pages/Public/Manage.tsx` 3; `Pages/Careers/Index.tsx` 2; `Pages/Careers/Offer.tsx` 2; `Pages/Developer/Index.tsx` 2; `Pages/HR/Organisation.tsx` 2; `Pages/HR/Payroll.tsx` 2; `Pages/HR/SignContract.tsx` 2.
Pill (rounded-full) buttons: 0; the directive says "no needless pills".

### Form controls
Text-like inputs 716 (non-conforming 14), selects 310 (257), textareas 31 (26). Checkbox, radio, hidden, file, range and colour inputs are excluded (covered by the global hit-area rule).

### Z-index in source
Utilities: 10 4, 20 3, 30 3, [40 3, 50 2, [30 2, [70 2, 40 1. Tokens: --z-sticky 20, --z-bar 30, --z-dropdown 40, --z-sheet 50, --z-modal 60, --z-toast 70. Raw numeric `z-NN` / `z-[NN]` usages: 13 (`Pages/Reservations/TapeChart.tsx` 4; `Pages/Member/MemberLayout.tsx` 3; `Components/voice/VoiceNotifier.tsx` 1; `Pages/Finance/Receivables.tsx` 1; `Pages/Landing/Index.tsx` 1; `Pages/Member/ConfirmSheet.tsx` 1; `Pages/Property/FastConfigure.tsx` 1; `Pages/Reservations/Create.tsx` 1); inline `zIndex:` 0.

### Variants to consolidate (proposal)
1. Add a shared `<Alert tone="info|success|warning|danger">` (icon + text + role) and replace the 94 hand-built callouts. Status then never relies on colour alone.
2. Replace 5 hand-built pills with `badge-*` (add `badge-orange`-style tones only if a real status needs it).
3. Replace 129 raw `<button>` with `<Button>` or a shared class where they are real actions (tab-like, chip and icon-only toggles may stay but need `hit-44`).
4. Remove local bg/rounded/h overrides on shared btn classes (52).
5. Move 8 files' bare inputs to `.input`.
