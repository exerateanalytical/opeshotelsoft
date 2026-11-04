# UI audit: final report (R1)

Generated 2026-11-04 by `node scripts/ui-audit/register.cjs`. Contract: docs/UI-DIRECTIVE.md. Method, harness and scope: docs/UI-STANDARD.md and the notes under A.

## A. Executive summary

**Screens audited: 144** (5 errors, 10 member, 12 public, 117 staff), each at 25 viewport configurations: phones 320, 360, 375, 390, 412, 430, 480; tablets 768, 820, 834, 1024; desktops 1280, 1366, 1440, 1536, 1600, 1920, 2560; emulated zoom 125/150/200 percent at 1280 and 1920 (CSS widths 1024, 853, 640 and 1536, 1280, 960 with matching device pixel ratio); 200 percent text scale at 1280. Total harness runs: 3596.

| Form factor | Screens audited | PASS | PASS* (P3 polish only) | FAIL (P0-P2) | Pass rate (PASS + PASS*) |
|---|---|---|---|---|---|
| mobile | 144 | 13 | 100 | 31 | 78% |
| tablet | 144 | 11 | 129 | 4 | 97% |
| desktop | 144 | 11 | 95 | 38 | 74% |
| zoom | 144 | 2 | 102 | 40 | 72% |

**Screens needing correction** (any P0-P2): 59 of 144. Clean: 0. P3 polish only: 85.

| Severity | Screens whose worst finding is this | Distinct findings (rule x screen) |
|---|---|---|
| P0 | 0 | 0 |
| P1 | 4 | 5 |
| P2 | 55 | 75 |
| P3 | 85 | 269 |

Modal and sheet checks (53 triggers tried on up to 29 pages at 320, 375 and 1280 px; 24 opened a dialog; 0 dialog openings had at least one failed criterion; 55 page-viewport pairs had no "new/add/edit" trigger). Details under D.

### Honest scope notes
- Runner: headless Chrome 156 driven by `scripts/ui-audit/run.cjs` (puppeteer-core) on my own server http://localhost:8120, not iframes or the shared browser pane; real viewport emulation (mobile and touch flags for phones, touch for tablets). Each route is loaded once per input mode and resized in place for every width, so a width that only differs by viewport (not by load-time logic) is exact.
- Zoom is an emulation: CSS width = screen width / zoom, device pixel ratio = zoom. It reproduces layout reflow at zoomed widths, not the browser zoom UI.
- Roles: staff pages were audited as General Manager (hotel scoped, sees nearly all); routes that GM cannot open are listed in G. Cashier was used for the 403 page. Role-fitted home pages per role were not exercised in this pass.
- Heuristic rules (long-line, stretched, uppercase-block, btn-nonstandard, icon-family, colour-only-status) can over- or under-report; samples are in `results/summary.json`. The repository is not under git, so there is no before/after diff for this pass; "Fixed" means clean at the time of this run.

### Headline findings and top blockers
1. **No P0 and 4 P1 screens remain after fresh-load confirmation.** Open P1: `/` (overflow-x at text200@1280); `/finance/bank-feed` (overflow-x at text200@1280); `/finance/payables-workflow` (overflow-x at text200@1280); `/reports` (overflow-x, chart-overlap at text200@1280). All are emulated 200 percent text scaling, not 100 percent layouts.
2. **Ultra-wide is the biggest open area (P2): 33 screens stretch a form, an input or a sparse table at 1600 px and wider** (search and filter inputs reach 850 to 1,600 px, forms 1,280 to 1,630 px, 4-column tables 1,600 px). Cause: `main` has no maximum content width and form grids use `grid-cols-*` without a cap. Proposed shared fix (owner decision, AppLayout): `max-w-screen-2xl mx-auto` on `main` for non-operational pages, `max-w-3xl` on single forms, `w-auto` on sparse tables. Operational boards (tape chart, POS, housekeeping) stay full width.
3. **Phone page title (P3, systemic, 144 screens): the h1 is 16 px in the app bar**; the directive baseline is 24 to 28 px. Needs a design decision (large in-page title under the app bar, or a larger app bar title).
4. **Running text under 14 px on phones (P2, 25 screens):** the app bar subtitle (`Topbar.tsx`, `text-xs md:text-sm`, 13 px) and helper paragraphs written as `text-xs` (for example finance and payments descriptions). Use `text-sm` for sentences; the subtitle should be hidden on phones or moved into the page.
5. **Button class drift (14 screens P2, 121 screens P3):** the same `.btn-*` class renders at 40 and 42 px on desktop on some pages (content-driven height; give the shared class an explicit `line-height` and `min-height`), the landing hero CTAs override size, and the voice sound toggle in the shell is a hand-styled 44 px, 12 px radius button on every page (use `.btn-secondary` or `.btn-icon`).
6. **Static design-system debt:** 78 <Button> uses and 895 shared btn-* class uses against 129 raw buttons and 52 overridden shared buttons; text inputs 14/716, selects 257/310, textareas 26/31 without the shared class; 38 hand-built cards, 5 hand-built badges, 92 hand-built alert boxes (no shared Alert component), 29 raw tables without a wrapper class, 4 hand-rolled overlays; 37 hard-coded colour literals in components, 957 non-brand Tailwind hue utilities (semantic status colours), 270 off-scale spacing utilities, 13 raw z-index numbers, 22 uppercase uses, 14 arbitrary font sizes, 1 shadow/ring utilities. See docs/UI-CONSISTENCY-MATRIX.md for files. Biggest wins: a shared `<Alert>` (94 hand-built callouts, colour-plus-text status), `.select` on selects (most use `.input`), `.table-wrap` on 29 raw tables.
7. **Modals:** every dialog that opened (24 openings, filter sheets and edit dialogs on 7 pages at 320, 375 and 1280 px) passed width, height, footer reach, close button, Escape, focus move and Tab trap. The shared `<Modal>` is sound; only 2 hand-rolled overlays exist (member portal sheet and layout menu) plus the command palette. Coverage is partial (many pages use inline panels, not dialogs).
8. **Not verified in this pass:** role-fitted home pages per role beyond General Manager, real Safari and Firefox rendering, keyboard shortcut coverage, screen reader walkthroughs, print layouts, and offline states (another workstream owns them). Findings fixed by other workstreams while R1 ran may already be gone; re-run `run.cjs` then `recheck.cjs` then `register.cjs` to refresh.

## B. Design-system findings

Static counts (docs/UI-CONSISTENCY-MATRIX.md, docs/UI-TOKENS.md): 78 <Button> uses and 895 shared btn-* class uses against 129 raw buttons and 52 overridden shared buttons; text inputs 14/716, selects 257/310, textareas 26/31 without the shared class; 38 hand-built cards, 5 hand-built badges, 92 hand-built alert boxes (no shared Alert component), 29 raw tables without a wrapper class, 4 hand-rolled overlays; 37 hard-coded colour literals in components, 957 non-brand Tailwind hue utilities (semantic status colours), 270 off-scale spacing utilities, 13 raw z-index numbers, 22 uppercase uses, 14 arbitrary font sizes, 1 shadow/ring utilities.

Harness findings in the component and colour families (screens affected out of 144):

| Rule | Severity | What | Screens |
|---|---|---|---|
| btn-variant | P2 | the same button class renders with different height, radius or padding | 14 |
| btn-nonstandard | P3 | button that does not use the shared button classes | 121 |
| radius-off-scale | P3 | border radius outside the 4/8/12/16/24 scale | 1 |

Top offenders: **btn-variant**: `/dashboard`, `/this-page-does-not-exist`, `/`, `/login`, `/billing/cashier`, `/developer`, `/hr/talent/skills`, `/hr/talent/training`, and 6 more; **btn-nonstandard**: `/finance`, `/hr`, `/this-page-does-not-exist`, `/account`, `/`, `/admin/audit`, `/admin/currencies`, `/billing/cashier`, and 113 more; **radius-off-scale**: `/properties/fast-configure`.

## C. Responsive findings

### Findings per viewport (all screens)

| Viewport | Screens run | Screens with a confirmed P0-P2 finding | Confirmed P0-P2 findings | P3 findings | Raw critical count before confirmation |
|---|---|---|---|---|---|
| 320x640 | 144 | 31 | 31 | 145 | 84 |
| 360x740 | 144 | 31 | 31 | 145 | 84 |
| 375x812 | 144 | 31 | 31 | 145 | 84 |
| 390x844 | 144 | 31 | 31 | 145 | 84 |
| 412x915 | 144 | 31 | 31 | 145 | 86 |
| 430x932 | 144 | 31 | 32 | 145 | 88 |
| 480x854 | 144 | 31 | 32 | 145 | 91 |
| zoom200@1280 | 144 | 30 | 31 | 145 | 11 |
| 768x1024 | 143 | 4 | 5 | 33 | 180 |
| 820x1180 | 143 | 4 | 5 | 33 | 180 |
| 834x1194 | 143 | 4 | 5 | 33 | 180 |
| zoom150@1280 | 144 | 4 | 5 | 35 | 9 |
| zoom200@1920 | 144 | 5 | 6 | 35 | 26 |
| 1024x768 | 143 | 4 | 4 | 132 | 173 |
| zoom125@1280 | 144 | 13 | 13 | 132 | 2 |
| 1280x720 | 144 | 13 | 13 | 132 | 2 |
| text200@1280 | 144 | 10 | 11 | 145 | 9 |
| zoom150@1920 | 144 | 13 | 13 | 132 | 2 |
| 1366x768 | 144 | 13 | 13 | 132 | 2 |
| 1440x900 | 144 | 13 | 13 | 132 | 2 |
| 1536x864 | 144 | 13 | 13 | 132 | 2 |
| zoom125@1920 | 144 | 13 | 13 | 132 | 2 |
| 1600x900 | 144 | 34 | 42 | 132 | 2 |
| 1920x1080 | 144 | 37 | 45 | 132 | 2 |
| 2560x1440 | 144 | 38 | 46 | 132 | 2 |

### Mobile (320-480)
Screens failing at mobile: `/dashboard`, `/finance`, `/hr`, `/this-page-does-not-exist`, `/`, `/login`, `/admin/currencies`, `/billing/cashier`, `/channels`, `/developer`, `/finance/accruals`, `/finance/budgets`, and 19 more.
Horizontal overflow: `/`, `/finance/bank-feed`, `/finance/payables-workflow`, `/reports`.
Touch targets under 44 px: `/`, `/billing/folios`, `/reservations/group-booking`.

### Tablet (768-1024)
Screens failing at tablet: `/dashboard`, `/this-page-does-not-exist`, `/`, `/login`.

### Desktop (1280-1920)
Screens failing at desktop: `/dashboard`, `/this-page-does-not-exist`, `/`, `/login`, `/developer`, `/finance/bank-feed`, `/finance/dimensions`, `/finance/payables-workflow`, `/finance/statements-pack`, `/hr`, `/hr/talent`, `/hr/talent/compliance`, and 26 more.

### Ultra-wide (1920, 2560) and long lines
Stretched forms, inputs or tables (>720 px control, >1100 px form, sparse table): `/developer`, `/finance/bank-feed`, `/finance/dimensions`, `/finance/payables-workflow`, `/finance/statements-pack`, `/hr`, `/hr/talent`, `/hr/talent/compliance`, `/hr/talent/planning`, `/hr/talent/skills`, `/hr/talent/training`, `/hr/workforce`, `/hr/workforce/cost`, `/hr/workforce/time`, `/insights/alerts`, `/loyalty/members`, `/messaging`, `/payments/payouts`, `/people/discipline`, `/people/exits`, `/people/feedback`, `/people/reviews`, `/properties`, `/reservations/create`, `/reservations/group-booking`, `/reservations/waitlist`, `/revenue/overbooking`, `/revenue/rules`, `/service-requests`, `/settings/users`, and 3 more.
Text lines over about 90 characters: none.
Widest content block per page at 2560 (metric contentW, px; pages above 1700 px listed): `/finance` 1760, `/hr` 1760, `/` 2560, `/billing/cashier` 2272, `/billing/invoices` 1760, `/billing/invoices/1` 1760, `/edge` 2272, `/finance` 1760, `/finance/bank-feed` 1760, `/finance/budgets` 1760, `/finance/cash-forecast` 1760, `/finance/dimensions` 1760, `/finance/dunning` 1760, `/finance/month-close` 1760, `/finance/payables-workflow` 1760, `/finance/payroll-filings` 1760, `/finance/statements-pack` 1760, `/finance/tax` 1760, `/front-desk` 2272, `/front-desk/in-house` 2272, `/guests` 1760, `/guests/1` 1760, `/guests/privacy-requests` 1760, `/guests/companies/1` 1760, `/housekeeping` 2272.

### Zoom (125/150/200 percent, emulated)
Screens failing under zoom: `/dashboard`, `/finance`, `/hr`, `/this-page-does-not-exist`, `/`, `/login`, `/admin/currencies`, `/billing/cashier`, `/billing/folios`, `/channels`, `/developer`, `/finance/bank-feed`, `/finance/budgets`, `/finance/consolidation`, `/finance/dimensions`, `/finance/dunning`, `/finance/payables-workflow`, `/finance/payroll-filings`, `/finance/statements-pack`, `/finance/tax`, `/guests/privacy-requests`, `/hr/talent/skills`, `/hr/talent/training`, `/loyalty/members`, `/messaging`, `/notifications/outbox`, `/notifications/templates`, `/onboarding/templates`, `/payments/gateways`, `/payments/inbound`, `/payments/links`, `/payments/payouts`, `/properties/fast-configure`, `/reports`, `/reservations/create`, `/reservations/group-booking`, `/revenue/offers`, `/revenue/overbooking`, `/settings/users`, `/stock/variance`.

## D. Accessibility findings

| Rule | Severity | Screens affected |
|---|---|---|
| touch-target: touch target under 44 px | P2 | 3 |

No-label controls: none. Contrast: none. Colour-only status: none. Missing alt: none.

### Modals and sheets
All dialogs that opened passed width, height, close, Escape, focus and Tab-trap checks.

## E. Page register

One row per route, all fields of the directive: **docs/UI-REGISTER.md** (144 rows; machine readable: scripts/ui-audit/results/register.csv and summary.json).

## F. Fix register

### Findings by rule (screens affected, severity order)

| Rule | Sev | Screens | Proposed fix |
|---|---|---|---|
| overflow-x (horizontal overflow) | P1 | 4 | Find the widest element (sample), add `min-w-0`, `flex-wrap`, or wrap the table in `.table-wrap`. |
| chart-overlap (chart labels overlap or chart too narrow on phone) | P1 | 1 | Use `interval="preserveStartEnd"` or fewer ticks on phones, rotate or abbreviate labels, give the chart a min height. |
| stretched (form, input or table stretched on a wide screen) | P2 | 33 | Cap with `max-w-3xl` (forms) / `max-w-5xl` (reading content) / `max-w-screen-2xl` (data pages); sparse tables `w-auto`. |
| body-small (running text below the baseline (14 px phone, 13 px desktop)) | P2 | 25 | Use `text-sm`/`text-base` for sentences; `text-xs` only for captions. |
| btn-variant (the same button class renders with different height, radius or padding) | P2 | 14 | Remove local size/radius overrides on `.btn-*`; use `size` prop. |
| touch-target (touch target under 44 px) | P2 | 3 | Use `.btn`/`.hit-44`, or wrap in a 44 px label. |
| h1-size (page title size outside the baseline (phone 22-30, desktop 24-36 px)) | P3 | 144 | Phone app bar shows the h1 at 16 px by design; add a 24-28 px in-page title or enlarge the app bar title. |
| btn-nonstandard (button that does not use the shared button classes) | P3 | 121 | Use `<Button>` or `.btn-*`. |
| no-tabular (number or money without tabular figures) | P3 | 3 | Add `tabular-nums` (body already sets it; a parent resets it). |
| radius-off-scale (border radius outside the 4/8/12/16/24 scale) | P3 | 1 | Use `rounded-lg`/`md`/`xl`. |

### Fixes applied in R1
Small, local fixes made by R1 (all verified with a fresh load at the width that failed, then the affected routes re-run in the full matrix; build `bash scripts/build-locked.sh` green):

| File | Defect (harness rule) | Fix |
|---|---|---|
| `resources/js/Pages/Landing/Index.tsx` | `/` horizontal overflow of 34 px at 1024 px: header nav links plus actions did not fit (overflow-x, P1) | Header nav shows from `xl` (1280) instead of `lg` (1024) |
| `resources/js/Pages/Finance/Consolidation.tsx` | `/finance/consolidation` overflow of 11 px at 320 px: the three view buttons did not wrap (overflow-x, P1) | `flex-wrap` on the button row |
| `resources/js/Pages/HR/TalentSkills.tsx` | five selects and the issue-date input without an accessible name (no-label, P1) | `aria-label` from existing `talent.*` keys; new key `ui2.talent.issued_on` in `lang/{en,fr}/ui2.json` |
| `resources/js/Pages/HR/TalentCompliance.tsx` | two selects (payroll run, employee) without a name (no-label, P1) | `aria-label` from `talent.choose_run` and `talent.employee` |
| `resources/js/Pages/Billing/Folios.tsx` | folio number button 36 px high on touch widths (touch-target, P2) | `min-h-11 md:min-h-9` |
| `resources/js/Components/ui/Toast.tsx` (shared, tiny) | toast dismiss button 32 x 32 px (touch-target, P2) | added the shared `hit-44` class |
| `scripts/ui-audit/audit.js` | harness false positives found while triaging: password reveal button flagged as overlap, window-scroll layouts not scrolled for covered-by-fixed, top-sticky headers reported as covering, `pointer-events:none` plus `opacity` controls reported for contrast, chart legend icon reported as a narrow chart, 50 percent radius, wrapped button labels reported as height variants | harness refined; `register.cjs` also drops findings that do not reproduce on a fresh load (`recheck.cjs`) |


## G. Remaining blockers

| Screen | Route | Blocker |
|---|---|---|
| Landing | `/` | overflow-x (P1) |
| Finance / Bank feed | `/finance/bank-feed` | overflow-x (P1) |
| Finance / Payables workflow | `/finance/payables-workflow` | overflow-x (P1) |
| Reports | `/reports` | overflow-x (P1), chart-overlap (P1) |

Routes that did not render their own page for this role (HTTP error or redirect): `/finance` (403), `/hr` (403), `/this-page-does-not-exist` (404), `/account/bookings/1` (404), `/account/chat` (500), `/verify/sample` (404), `/hr/workforce/clock` (500), `/onboarding/templates` (500).
