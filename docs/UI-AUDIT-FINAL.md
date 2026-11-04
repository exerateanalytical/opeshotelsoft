# UI audit: final report (R1)

Generated 2026-11-04 by `node scripts/ui-audit/register.cjs`. Contract: docs/UI-DIRECTIVE.md. Method, harness and scope: docs/UI-STANDARD.md and the notes under A.

## A. Executive summary

**Screens audited: 144** (5 errors, 10 member, 12 public, 117 staff), each at 25 viewport configurations: phones 320, 360, 375, 390, 412, 430, 480; tablets 768, 820, 834, 1024; desktops 1280, 1366, 1440, 1536, 1600, 1920, 2560; emulated zoom 125/150/200 percent at 1280 and 1920 (CSS widths 1024, 853, 640 and 1536, 1280, 960 with matching device pixel ratio); 200 percent text scale at 1280. Total harness runs: 3650.

| Form factor | Screens audited | PASS | PASS* (P3 polish only) | FAIL (P0-P2) | Pass rate (PASS + PASS*) |
|---|---|---|---|---|---|
| mobile | 144 | 9 | 90 | 45 | 69% |
| tablet | 144 | 9 | 123 | 12 | 92% |
| desktop | 144 | 9 | 91 | 44 | 69% |
| zoom | 144 | 0 | 88 | 56 | 61% |

**Screens needing correction** (any P0-P2): 78 of 144. Clean: 0. P3 polish only: 66.

| Severity | Screens whose worst finding is this | Distinct findings (rule x screen) |
|---|---|---|
| P0 | 9 | 9 |
| P1 | 15 | 25 |
| P2 | 54 | 102 |
| P3 | 66 | 269 |

Modal and sheet checks (33 triggers tried on up to 29 pages at 320, 375 and 1280 px; 24 opened a dialog; 0 dialog openings had at least one failed criterion; 62 page-viewport pairs had no "new/add/edit" trigger). Details under D.

### Honest scope notes
- Runner: headless Chrome 156 driven by `scripts/ui-audit/run.cjs` (puppeteer-core) on my own server http://localhost:8120, not iframes or the shared browser pane; real viewport emulation (mobile and touch flags for phones, touch for tablets). Each route is loaded once per input mode and resized in place for every width, so a width that only differs by viewport (not by load-time logic) is exact.
- Zoom is an emulation: CSS width = screen width / zoom, device pixel ratio = zoom. It reproduces layout reflow at zoomed widths, not the browser zoom UI.
- Roles: staff pages were audited as General Manager (hotel scoped, sees nearly all); routes that GM cannot open are listed in G. Cashier was used for the 403 page. Role-fitted home pages per role were not exercised in this pass.
- Heuristic rules (long-line, stretched, uppercase-block, btn-nonstandard, icon-family, colour-only-status) can over- or under-report; samples are in `results/summary.json`. The repository is not under git, so there is no before/after diff for this pass; "Fixed" means clean at the time of this run.

## B. Design-system findings

Static counts (docs/UI-CONSISTENCY-MATRIX.md, docs/UI-TOKENS.md): 77 <Button> uses and 877 shared btn-* class uses against 129 raw buttons and 52 overridden shared buttons; text inputs 14/716, selects 257/310, textareas 26/31 without the shared class; 38 hand-built cards, 5 hand-built badges, 94 hand-built alert boxes (no shared Alert component), 29 raw tables without a wrapper class, 4 hand-rolled overlays; 57 hard-coded colour literals in components, 970 non-brand Tailwind hue utilities (semantic status colours), 271 off-scale spacing utilities, 13 raw z-index numbers, 35 uppercase uses, 11 arbitrary font sizes, 1 shadow/ring utilities.

Harness findings in the component and colour families (screens affected out of 144):

| Rule | Severity | What | Screens |
|---|---|---|---|
| contrast | P1 | text contrast below WCAG AA | 3 |
| glow | P1 | shadow, filter, gradient or pulse | 2 |
| btn-variant | P2 | the same button class renders with different height, radius or padding | 26 |
| icon-family | P2 | icon outside the Lucide family or filled | 2 |
| btn-nonstandard | P3 | button that does not use the shared button classes | 121 |
| radius-off-scale | P3 | border radius outside the 4/8/12/16/24 scale | 1 |

Top offenders: **btn-variant**: `/dashboard`, `/this-page-does-not-exist`, `/account`, `/`, `/login`, `/billing/cashier`, `/billing/folios`, `/developer`, and 18 more; **btn-nonstandard**: `/finance`, `/hr`, `/this-page-does-not-exist`, `/account`, `/`, `/admin/audit`, `/admin/currencies`, `/billing/cashier`, and 113 more; **icon-family**: `/hr/workforce/clock`, `/onboarding/templates`; **radius-off-scale**: `/properties/fast-configure`; **contrast**: `/hr/talent/compliance`, `/hr/workforce/clock`, `/onboarding/templates`; **glow**: `/hr/workforce/clock`, `/onboarding/templates`.

## C. Responsive findings

### Findings per viewport (all screens)

| Viewport | Screens run | With a critical finding | Critical findings | With an advisory finding | Advisory findings |
|---|---|---|---|---|---|
| 320x640 | 148 | 10 | 96 | 136 | 327 |
| 360x740 | 148 | 6 | 91 | 136 | 326 |
| 375x812 | 148 | 6 | 91 | 136 | 326 |
| 390x844 | 148 | 6 | 91 | 136 | 325 |
| 412x915 | 148 | 8 | 94 | 136 | 325 |
| 430x932 | 148 | 9 | 97 | 136 | 325 |
| 480x854 | 148 | 7 | 97 | 136 | 323 |
| zoom200@1280 | 145 | 8 | 23 | 134 | 287 |
| 768x1024 | 146 | 4 | 100 | 41 | 172 |
| 820x1180 | 146 | 4 | 101 | 41 | 171 |
| 834x1194 | 146 | 4 | 101 | 41 | 171 |
| zoom150@1280 | 145 | 8 | 17 | 41 | 195 |
| zoom200@1920 | 145 | 9 | 28 | 42 | 196 |
| zoom125@1280 | 145 | 5 | 10 | 134 | 318 |
| 1024x768 | 146 | 5 | 93 | 136 | 287 |
| 1280x720 | 145 | 6 | 11 | 134 | 254 |
| zoom150@1920 | 145 | 6 | 11 | 134 | 320 |
| text200@1280 | 145 | 14 | 18 | 144 | 170 |
| 1366x768 | 145 | 3 | 9 | 134 | 255 |
| 1440x900 | 145 | 5 | 13 | 134 | 255 |
| 1536x864 | 145 | 5 | 13 | 134 | 255 |
| zoom125@1920 | 145 | 5 | 13 | 134 | 318 |
| 1600x900 | 145 | 5 | 13 | 134 | 311 |
| 1920x1080 | 145 | 4 | 13 | 134 | 385 |
| 2560x1440 | 145 | 3 | 11 | 134 | 385 |

### Mobile (320-480)
Screens failing at mobile: `/dashboard`, `/finance`, `/hr`, `/this-page-does-not-exist`, `/account`, `/account/bookings`, `/account/hotels`, `/account/points`, `/account/profile`, `/`, `/login`, `/admin/currencies`, and 33 more.
Horizontal overflow: `/`, `/finance/bank-feed`, `/finance/consolidation`, `/finance/payables-workflow`, `/payments/gateways`, `/reports`, `/settings/users`.
Touch targets under 44 px: `/`, `/billing/invoices`, `/hr`, `/hr/lifecycle`, `/payments/payouts/1`, `/reservations/group-booking`.

### Tablet (768-1024)
Screens failing at tablet: `/dashboard`, `/this-page-does-not-exist`, `/account/hotels`, `/`, `/login`, `/finance/accruals`, `/finance/budgets`, `/finance/consolidation`, `/hr/talent/compliance`, `/hr/talent/skills`, `/hr/workforce/clock`, `/reports`.

### Desktop (1280-1920)
Screens failing at desktop: `/dashboard`, `/this-page-does-not-exist`, `/account/bookings`, `/account/hotels`, `/`, `/login`, `/developer`, `/finance/accruals`, `/finance/bank-feed`, `/finance/budgets`, `/finance/consolidation`, `/finance/dimensions`, and 32 more.

### Ultra-wide (1920, 2560) and long lines
Stretched forms, inputs or tables (>720 px control, >1100 px form, sparse table): `/developer`, `/finance/bank-feed`, `/finance/consolidation`, `/finance/dimensions`, `/finance/payables-workflow`, `/finance/statements-pack`, `/hr`, `/hr/talent`, `/hr/talent/compliance`, `/hr/talent/planning`, `/hr/talent/skills`, `/hr/talent/training`, `/hr/workforce`, `/hr/workforce/cost`, `/hr/workforce/time`, `/insights/alerts`, `/loyalty/members`, `/messaging`, `/payments/payouts`, `/people/discipline`, `/people/exits`, `/people/feedback`, `/people/reviews`, `/properties`, `/reports`, `/reservations/create`, `/reservations/group-booking`, `/reservations/waitlist`, `/revenue/overbooking`, `/revenue/rules`, and 5 more.
Text lines over about 90 characters: `/hr/workforce/clock`.
Widest content block per page at 2560 (metric contentW, px; pages above 1700 px listed): `/finance` 1760, `/hr` 1760, `/` 2560, `/billing/cashier` 2272, `/billing/invoices` 1760, `/billing/invoices/1` 1760, `/billing/folios` 1760, `/edge` 2272, `/finance` 1760, `/finance/bank-feed` 1760, `/finance/budgets` 1760, `/finance/cash-forecast` 1760, `/finance/consolidation` 1760, `/finance/dimensions` 1760, `/finance/dunning` 1760, `/finance/month-close` 1760, `/finance/payables-workflow` 1760, `/finance/payroll-filings` 1760, `/finance/statements-pack` 1760, `/finance/tax` 1760, `/front-desk` 2272, `/front-desk/in-house` 2272, `/guests` 1760, `/guests/1` 1760, `/guests/privacy-requests` 1760.

### Zoom (125/150/200 percent, emulated)
Screens failing under zoom: `/dashboard`, `/finance`, `/hr`, `/this-page-does-not-exist`, `/account`, `/account/book`, `/account/bookings`, `/account/bookings/1`, `/account/chat`, `/account/hotels`, `/account/points`, `/account/profile`, `/`, `/login`, `/admin/currencies`, `/billing/cashier`, `/billing/invoices`, `/channels`, `/developer`, `/finance/accruals`, `/finance/bank-feed`, `/finance/budgets`, `/finance/consolidation`, `/finance/dimensions`, `/finance/dunning`, `/finance/payables-workflow`, `/finance/payroll-filings`, `/finance/statements-pack`, `/finance/tax`, `/guests/1`, `/guests/privacy-requests`, `/hr`, `/hr/talent/compliance`, `/hr/talent/skills`, `/hr/talent/training`, `/insights/alerts`, `/loyalty/members`, `/messaging`, `/notifications/outbox`, `/notifications/templates`, and 16 more.

## D. Accessibility findings

| Rule | Severity | Screens affected |
|---|---|---|
| landmark: no main landmark | P1 | 5 |
| h1: page does not have exactly one visible h1 | P1 | 3 |
| no-label: control without an accessible name | P1 | 2 |
| touch-target: touch target under 44 px | P2 | 6 |
| title: document has no title | P2 | 3 |

No-label controls: `/hr/talent/compliance`, `/hr/talent/skills`. Contrast: `/hr/talent/compliance`, `/hr/workforce/clock`, `/onboarding/templates`. Colour-only status: none. Missing alt: none.

### Modals and sheets
All dialogs that opened passed width, height, close, Escape, focus and Tab-trap checks.

## E. Page register

One row per route, all fields of the directive: **docs/UI-REGISTER.md** (144 rows; machine readable: scripts/ui-audit/results/register.csv and summary.json).

## F. Fix register

### Findings by rule (screens affected, severity order)

| Rule | Sev | Screens | Proposed fix |
|---|---|---|---|
| covered-by-fixed (control covered by a fixed or sticky bar) | P0 | 9 | Give the scroll pane bottom padding (`pb-[var(--bar-h)]`) or use `.above-tabbar`; check z-index against tokens. |
| overflow-x (horizontal overflow) | P1 | 7 | Find the widest element (sample), add `min-w-0`, `flex-wrap`, or wrap the table in `.table-wrap`. |
| landmark (no main landmark) | P1 | 5 | Render page content inside AppLayout / `<main>`. |
| h1 (page does not have exactly one visible h1) | P1 | 3 | Pass `title` to AppLayout; remove stray h1 elements. |
| contrast (text contrast below WCAG AA) | P1 | 3 | Use the semantic token pair (text-slate-500 minimum on light, `--c-on-chrome-muted` on navy). |
| overlap (overlapping interactive elements) | P1 | 2 | Stack with `flex-wrap`/`grid`, or move one control; check absolute positioning. |
| no-label (control without an accessible name) | P1 | 2 | Wrap in `<Field label>` or add `aria-label` (new keys in lang/{fr,en}/ui2.json). |
| glow (shadow, filter, gradient or pulse) | P1 | 2 | Remove the utility; elevation is border plus background step only. |
| chart-overlap (chart labels overlap or chart too narrow on phone) | P1 | 1 | Use `interval="preserveStartEnd"` or fewer ticks on phones, rotate or abbreviate labels, give the chart a min height. |
| stretched (form, input or table stretched on a wide screen) | P2 | 35 | Cap with `max-w-3xl` (forms) / `max-w-5xl` (reading content) / `max-w-screen-2xl` (data pages); sparse tables `w-auto`. |
| body-small (running text below the baseline (14 px phone, 13 px desktop)) | P2 | 27 | Use `text-sm`/`text-base` for sentences; `text-xs` only for captions. |
| btn-variant (the same button class renders with different height, radius or padding) | P2 | 26 | Remove local size/radius overrides on `.btn-*`; use `size` prop. |
| touch-target (touch target under 44 px) | P2 | 6 | Use `.btn`/`.hit-44`, or wrap in a 44 px label. |
| title (document has no title) | P2 | 3 | Set `<Head title>`. |
| small-text (text under 13 px) | P2 | 2 | Use `text-xs` (13 px) minimum. |
| icon-family (icon outside the Lucide family or filled) | P2 | 2 | Replace with a lucide-react icon. |
| long-line (text lines longer than about 90 characters) | P2 | 1 | Add `max-w-prose` (65ch) or `max-w-3xl` to the text block. |
| h1-size (page title size outside the baseline (phone 22-30, desktop 24-36 px)) | P3 | 144 | Phone app bar shows the h1 at 16 px by design; add a 24-28 px in-page title or enlarge the app bar title. |
| btn-nonstandard (button that does not use the shared button classes) | P3 | 121 | Use `<Button>` or `.btn-*`. |
| no-tabular (number or money without tabular figures) | P3 | 2 | Add `tabular-nums` (body already sets it; a parent resets it). |
| uppercase-block (uppercase text longer than 28 characters) | P3 | 1 | Drop `uppercase` on sentences; keep it for 1-3 word labels. |
| radius-off-scale (border radius outside the 4/8/12/16/24 scale) | P3 | 1 | Use `rounded-lg`/`md`/`xl`. |

### Fixes applied in R1
(see the list in the handoff report)

## G. Remaining blockers

| Screen | Route | Blocker |
|---|---|---|
| Account | `/account` | covered-by-fixed (P0) |
| Account / Book | `/account/book` | covered-by-fixed (P0) |
| Account / Bookings | `/account/bookings` | covered-by-fixed (P0) |
| Account / Bookings / 1 | `/account/bookings/1` | overlap (P1) |
| Account / Chat | `/account/chat` | covered-by-fixed (P0) |
| Account / Hotels | `/account/hotels` | covered-by-fixed (P0) |
| Account / Points | `/account/points` | covered-by-fixed (P0) |
| Account / Profile | `/account/profile` | covered-by-fixed (P0) |
| Landing | `/` | covered-by-fixed (P0), overflow-x (P1) |
| Finance / Accruals | `/finance/accruals` | h1 (P1), landmark (P1) |
| Finance / Bank feed | `/finance/bank-feed` | overflow-x (P1) |
| Finance / Consolidation | `/finance/consolidation` | overflow-x (P1) |
| Finance / Payables workflow | `/finance/payables-workflow` | overflow-x (P1) |
| Hr / Talent / Compliance | `/hr/talent/compliance` | no-label (P1), contrast (P1) |
| Hr / Talent / Skills | `/hr/talent/skills` | no-label (P1) |
| Hr / Workforce / Clock | `/hr/workforce/clock` | landmark (P1), glow (P1), contrast (P1) |
| Loyalty / Cards / 1 | `/loyalty/cards/1` | h1 (P1), landmark (P1) |
| Onboarding / Import | `/onboarding/import` | h1 (P1), landmark (P1) |
| Onboarding / Templates | `/onboarding/templates` | landmark (P1), glow (P1), contrast (P1) |
| Payments / Gateways | `/payments/gateways` | overflow-x (P1) |
| Payments / Payouts / 1 | `/payments/payouts/1` | covered-by-fixed (P0) |
| Pos | `/pos` | overlap (P1) |
| Reports | `/reports` | chart-overlap (P1), overflow-x (P1) |
| Settings / Users | `/settings/users` | overflow-x (P1) |

Routes that did not render their own page for this role (HTTP error or redirect): `/finance` (403), `/hr` (403), `/this-page-does-not-exist` (404), `/account/bookings/1` (404), `/verify/sample` (404), `/hr/workforce/clock` (500), `/onboarding/templates` (500).
