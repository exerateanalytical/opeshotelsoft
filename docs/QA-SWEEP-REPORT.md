# QA sweep report (B1)

Method: own server (`php artisan serve --port=8090`, 4 workers), own tab, audit harness `scripts/ui-audit/audit.js` loaded by script tag into same-origin iframes of exactly 375, 768 and 1280 px width (the iframe width drives the media queries, so no pane resizing was needed). Signed in through the one-click demo login. Dev DB responds slowly (about 5 s per page), so each page was given up to 40 s and 3 harness load attempts; pages flagged BLANK on the first pass were re-run and passed.

## Totals
- Staff routes audited: 114 at 375 and 114 at 1280, plus 25 key pages at 768 (253 runs), as General Manager. Extra: `/properties/fast-configure` (not in routes.json) at 375 and 1280.
- Public pages: 16 URLs x 2 widths. Member portal: 10 routes x 2 widths.
- Roles checked (desktop sidebar order and set, phone tab bar + More sheet, every visible link fetched): 15 (the 14 asked + general-manager). All 15 match RbacMatrixTest exactly (set, order, tab bar has 4 destinations, More sheet equals the full list), and every sidebar link returned 200.
- HTTP errors on staff routes: 0. Blank pages: 0 after retry (2 transient timeouts, `/finance/tax` and `/payments/inbound` at 375, passed on re-run). Console errors: only the missing page below.
- Open blockers: **0**. Major open: 1 (confirmation numbers not unique across hotels, rest of manage-booking flow). Fixed: 1 blocker, 5 majors/minors.

## Defects fixed
| # | Sev | Defect | Fix (file) |
|---|-----|--------|-----------|
| 1 | blocker | `/forgot-password` rendered a blank page (Inertia page `Auth/ForgotPassword` did not exist; the "Mot de passe oublie ?" link on the staff login led to it) | Added `resources/js/Pages/Auth/ForgotPassword.tsx` (info page: staff resets are done by an administrator, member reset via Member tab) and keys `auth.forgot.text` in `lang/en.json`, `lang/fr.json`. There is no staff self-service reset backend. |
| 2 | major | Public manage-booking lookup said "Reservation introuvable" for a valid booking: confirmation numbers are sequenced per hotel (R2026-000094 exists 5 times in the group) and lookup took `first()` | `app/Modules/GuestPortal/Http/BookingEngineController.php` `findBooking` now picks the candidate whose guest last name matches. Verified with the booking created in the sweep. (Backend, small and local.) |
| 3 | minor | Pay link page stayed on "Chargement..." forever for an invalid/expired link (empty `message` won the `??` chain) | `resources/js/Pages/Payments/PayLink.tsx` line 34 uses `||` and falls back to status text |
| 4 | minor | HR / People / Onboarding sub-tab links 38 or 24 px high on phones (touch target) in 19+ pages | `HR/TalentShared.tsx`, `HR/WorkforceTabs.tsx`, `People/Index.tsx` (`min-h-11 inline-flex`), `Onboarding/shared.tsx`, `HR/Lifecycle.tsx` tab buttons |
| 5 | minor | `/admin/currencies` horizontal overflow at 375 (grid child min-width) | `Admin/Currencies.tsx` `grid-cols-1` + `min-w-0` |
| 6 | minor | `/onboarding/branding` overflow 845 -> 386 px at 375 (still 19 px, see open) | `Onboarding/Branding.tsx` `grid-cols-1`, `min-w-0` |
| 7 | minor | Member `/account/hotels` star rating gold on white 2.9:1 | `Member/Hotels.tsx` `text-brand-800` |
| 8 | minor | Fast configure action bar (Dry run / Apply) hidden under the phone tab bar | `Property/FastConfigure.tsx` bar offset by `--bar-bottom-h` on phones |
| 9 | tooling | Audit harness reported 33 false contrast failures: it could not parse `oklch()` backgrounds (white on "slate-100") | `scripts/ui-audit/audit.js` now normalises any CSS colour through a canvas |

Build: `bash scripts/build-locked.sh` passed after the edits; `UiStandardTest` 4/4 passing.

## Defects open
| Sev | Defect | File to change |
|-----|--------|----------------|
| major | Same non-unique confirmation-number assumption elsewhere: guest self-cancel/online check-in/deposit by `number` + `firstOrFail()` (wrong booking or 404 when another hotel owns the same number). Better root fix: make numbers unique per group or carry hotel/id in the manage token and URL. | `GuestPortal/Http/BookingEngineController.php` lines ~192, ~202; `GuestPortal/Http/ManageController.php` ~102; `Payments/Http/PublicPaymentController.php` ~75 |
| minor | FIXED (UX6) Placeholder-only controls without an accessible name on HR talent/workforce/people, loyalty, insights alerts, developer, settings users, onboarding import/templates/branding, profile: every control now has a Field label, htmlFor or `aria-label` (existing keys where the meaning is identical, new keys in `lang/{fr,en}/ui2.json`). Audit `no-label` 0 on all 27 pages at 375, 768, 1280. | the respective `Pages/*` files |
| minor | FIXED (UX6) Touch targets: onboarding wizard steps, careers link, `/owner` All alerts, dunning checkboxes (global 44 px hit area), notification template rows, loyalty Points statement, `/service-requests/settings` range inputs (global 44 px row), currencies badge/delete buttons, users icon buttons, template placeholder chips, file inputs. New `.hit-44` helper. Audit `touch-target` 0 on all pages at 375, 768, 1280. | `app.css` + page files |
| minor | FIXED (UX6) `/onboarding/branding` overflow: native file inputs (logo and watermark) were as wide as their file name; global `input[type=file]{max-width:100%;min-width:0}`. Audit overflow-x 0 at 375. | `app.css`, `Onboarding/Branding.tsx`, `Onboarding/Watermark.tsx` |
| minor | FIXED (UX6) Voice-alert chip: under 1024 px it is now an icon-only 44 px button in the app bar (`#voice-slot` in Topbar), with `aria-pressed`, label and a polite live region; toasts show under the app bar. Floating chip only from 1024 px. Also fixed the Fast configure action bar covering the tablet icon rail. Audit `overlap`/`covered-by-fixed` 0 on `/properties/fast-configure` at 375, 768, 1280. | `VoiceNotifier.tsx`, `Topbar.tsx`, `FastConfigure.tsx` |
| minor | FIXED (UX6) `/login` has no `<main>` landmark: the form column is now `<main id="main">`; the decorative hero heading became a paragraph so the page has a single h1. Staff/Member tabs and demo drop-down verified intact. | `Pages/Auth/Login.tsx` |
| info | Disabled "JSON/Excel" export buttons on `/hr/talent/compliance` report 3.2:1 (disabled, exempt) | none |
| info | (UX6: the preview now carries `data-doc-preview`, which the harness skips for small-text; the login password-eye overlap is still reported by the harness and is intended.) `/onboarding/branding` small-text hits are the scaled letterhead preview (content, not UI); `/login` password-eye overlap is the intended in-input button; `covered-by-fixed` on member `/account/bookings|card|profile` at 375 is the harness measuring before scroll (layout has bottom padding) | none |
| info | Dev data: no payable payment link, no member in house, so the pay page (valid link) and member service-request creation could only be checked for their error paths (member gets "Requests are available during your stay", correct). Staff-board creation was used for the voice test. | seed data |

## Staff pages (General Manager). OK = no HTTP error, h1 present, no overflow/overlap/small text/contrast/glow after fixes
All 114 routes were OK at 375 and 1280 except the findings below (all other pages: OK, rows omitted for brevity; the full list is `scripts/ui-audit/routes.json`).

| Page | 375 | 768 | 1280 |
|------|-----|-----|------|
| /dashboard, /reservations, /reservations/create, /reservations/1, /reservations/tape-chart, /reservations/arrivals, /front-desk, /front-desk/in-house, /billing/folios, /billing/invoices, /billing/cashier, /pos, /housekeeping, /maintenance, /guests, /finance, /service-requests, /messaging, /stock | OK | OK | OK |
| /owner | touch target (All alerts) | same | OK |
| /hr | OK | OK | OK |
| /hr/workforce (+ clock, cost, leave, requests, time) | tabs fixed; no-label | fixed | no-label (leave 24) |
| /hr/talent (+ planning, skills, training, compliance), /hr/lifecycle, /people (+ discipline, exits, feedback, reviews, tasks) | tab links fixed; some no-label | fixed (people) | no-label on filters |
| /settings/users | no-label (3) | no-label, icon 16 px | no-label (3) |
| /onboarding | wizard steps 36 px | same | OK |
| /onboarding/branding | overflow 19 px, preview small text | n/a | no-label |
| /onboarding/import, /onboarding/templates | no-label | n/a | no-label |
| /admin/currencies | fixed; "Active" badge 24 px | n/a | OK |
| /loyalty, /loyalty/cards/1, /loyalty/counter, /loyalty/members | no-label | n/a | no-label |
| /finance/dunning | row checkbox 20 px | n/a | OK |
| /notifications/templates | list button 40 px | n/a | OK |
| /service-requests/settings | input 16 px | n/a | OK |
| /developer, /insights/alerts, /profile | no-label | n/a | no-label |
| /properties/fast-configure | action bar fixed; chip overlap | n/a | OK |
| /finance/tax, /payments/inbound | OK on re-run | n/a | OK |

No page had HTTP errors, a blank screen, a missing h1, glow styles, or console errors (apart from the missing page fixed in #1).

## Per-role menu check (desktop sidebar, phone tab bar, More sheet, links open)
| Role | Sidebar items | Order = RbacMatrixTest | Tab bar | More sheet = full menu | Links non-200 |
|------|---------------|------------------------|---------|------------------------|---------------|
| receptionist | 28 | yes | 4 | yes | 0 |
| night-auditor | 20 | yes | 4 | yes | 0 |
| housekeeping-manager | 10 | yes | 4 | yes | 0 |
| room-attendant | 4 | yes | 4 | yes | 0 |
| pos-cashier | 6 | yes | 4 | yes | 0 |
| fnb-manager | 12 | yes | 4 | yes | 0 |
| storekeeper | 5 | yes | 4 | yes | 0 |
| accountant | 16 | yes | 4 | yes | 0 |
| finance-director | 24 | yes | 4 | yes | 0 |
| hr-officer | 9 | yes | 4 | yes | 0 |
| payroll-officer | 10 | yes | 4 | yes | 0 |
| revenue-manager | 20 | yes | 4 | yes | 0 |
| hotel-owner | 53 | yes | 4 | yes | 0 |
| maintenance-technician | 5 | yes | 4 | yes | 0 |

Note: the nav landmark `aria-label` is translated (French sessions), so scripts must select by structure (`aside nav`), not by the English label.

## Public pages (375 and 1280)
| Page | Result |
|------|--------|
| `/` landing (fr default, English via switch) | OK at both widths |
| `/login` staff tab, member tab, both demo drop-downs | Render and select OK; member demo sign-in works; staff demo sign-in works. Findings: no `<main>` landmark (minor) |
| `/forgot-password` | BLOCKER found and fixed (#1); verified after build |
| `/book/opes-demo` -> search -> details -> confirmation | Full flow worked end to end: search LIM 10-12 Dec, rate chosen, guest details, confirmation R2026-000094 (a test booking remains in the dev DB, guest "QA Sweep") |
| `/book/opes-demo/manage` | Lookup failed for valid booking: defect #2 fixed (lookup), follow-ups open |
| `/book/opes-demo/review`, `/confirmation` bare | OK (no params shows the expected empty state) |
| `/careers/opes-demo`, `/careers/opes-demo/1` | OK ("Aucune offre ouverte") |
| `/verify/{real code}` (e.g. `1-a94b05fe61`, service card) | OK, "Document valide"; invalid code shows the invalid state |
| `/pay/...` | Invalid link: was stuck loading, fixed (#3). Valid link not testable: no open folio/reservation in dev data accepts a link |

## Member portal (demo member Jean Mballa, 375 and 1280)
`/account`, `/account/book`, `/account/bookings`, `/account/card`, `/account/chat`, `/account/hotels`, `/account/points`, `/account/profile`, `/account/requests`: OK (hotels star contrast fixed #7). `/account/bookings/1` returns Not Found (booking 1 is not this member's: expected). Request creation returns "Requests are available during your stay" (member not in house, correct rule).

## Voice alert
Signed in as GM on `/service-requests`: sound chip toggles "Sound off" -> "Sound on" (and shows "Click to enable sound" until a gesture unlocks audio). A housekeeping request for room 101 was created through the staff API; within one poll the chip line showed "Last: Room 101. Towels requested..." and the toast region rendered it; the feed (`/api/v1/service-requests/feed`) returned the event. Audio itself not audible from here.

## Cleanup
Own tab closed, server on port 8090 stopped, `public/ui-audit.js` removed, viewport reset to desktop. Test data left in the dev DB: reservation R2026-000094 (hotel LIM, guest QA Sweep) and service request #1 (room 101).
