# UI audit and fix plan

Owner request (2026-11-04): audit the entire platform for colour collisions, mobile and desktop responsiveness (it must feel like a native app on a phone and on a desktop), cramped or overlapping or distorted layouts, poorly styled buttons and forms, poor colour blends, fonts and font sizes, visual-impairment accessibility, and glow effects. Every page clean, auto-fitting, with undistorted filters. Industry standard and professional.

## Baseline (measured 2026-11-04, 196 page and component files)
- Glow and gradients in code: 1 `shadow-` use, 8 `ring-` uses (3 files), 0 gradients, 0 blur. Other glow sources (CSS box-shadow in app.css, outline halos, glossy logo artwork, animation) must be found by the harness, not by grep alone.
- Faint text: `text-slate-400` used as text colour 295 times (about 2.6:1 on white, fails WCAG AA 4.5:1); `text-slate-500` 465 times (about 4.8:1, passes on white but fails on slate-50 tints); `text-brand-*` gold on white fails for text.
- Small text: 17 hard-coded sizes of 10 to 12 px in 4 files; `text-xs` used 461 times (the theme maps it to 13 px, acceptable but too dense for body copy on phones).

## The standard (the contract for every agent)

**Colour.** The palette stays: navy (brand body and chrome), gold (accent), slate neutrals, plus semantic colours success (emerald), warning (amber), danger (red), info (navy-500). Rules: body text on white at least 4.5:1; large text and UI boundaries at least 3:1; gold is a fill with navy text (never gold text on white or white text on gold); status is never colour alone (icon or label too); no two adjacent surfaces with similar luminance (collisions). All pairs live as tokens in `resources/css/app.css` and are checked by a test.

**Type.** System-readable scale: phone body 16 px, desktop body 15 to 16 px, table text 14 to 15 px, captions and labels never under 13 px, headings 20/24/30 px, line-height 1.5 body and 1.25 headings, no all-caps paragraphs, numbers in tabular figures, honour browser text zoom to 200 percent without clipping or overlap.

**No glow, ever.** No `box-shadow`, `text-shadow`, `filter: drop-shadow` or blur, no halo focus rings (focus is a solid 2 px outline with 2 px offset), no gradients, no pulsing or glowing animations, no glossy effects on controls. Elevation is shown by a 1 px border and background step only. Icons use `currentColor`, outline style (lucide), 16 to 24 px, coloured by meaning: neutral slate, brand navy for navigation, success, warning and danger by status; never filled blobs, never glow, never a single decorative colour on everything.

**Layout.** Mobile first: no horizontal page scroll at 320 to 1920 px; no element overlap; wide tables scroll inside their own container (or become card lists under 768 px); filters wrap or collapse into a "Filtres / Filters" sheet on phones, never shrink below readable size; forms single column on phones, two columns from 768 px; tap targets at least 44 px on touch, 36 px on desktop; consistent spacing scale (4 px base) and radius; sticky action bars that never cover content; no hover-only controls.

**Native feel, phone.** App bar on top, bottom tab bar for the four to five main destinations of the user's role plus a "More" sheet with the full menu, safe-area insets (notches), `100dvh`, bottom sheets instead of centred modals, momentum scrolling and `overscroll-behavior: contain`, no tap highlight, pressed states instead of hover, instant feedback under 100 ms, respect `prefers-reduced-motion`, standalone display mode of the installed web app works.

**Native feel, desktop.** Fixed collapsible sidebar, dense but readable tables, a consistent page header with actions, keyboard shortcuts (search, new item, close), visible focus, context-appropriate cursors, split panes for list and detail where it fits, no layout shift while loading (skeletons), windowed scrolling inside panes instead of the whole page jumping.

**Accessibility (WCAG 2.2 AA).** Skip link, landmarks, one h1 per page, focus moved to the page heading on navigation, labels linked to every control, errors linked and announced, `aria-current` in navigation, live regions for toasts and the voice alerts, keyboard operable everywhere, focus never trapped or hidden by sticky bars, forced-colours and high-contrast modes tolerated, motion reduced on request, images with alt, touch targets as above.

## Workstreams

| WS | Scope | Owns |
|---|---|---|
| UX1 Design system, shell, harness | Tokens (colour, type, spacing, radius, z-index) in app.css; fix at class level (`.btn`, `.input`, `.card`, `.badge`, `.nav-item`, tables, tabs) so pages inherit; shared components (Button, Field, FilterBar, Table, Tabs, Modal/Sheet, Toast, EmptyState, PageHeader, Skeleton); app shell for phone (bottom tab bar, More sheet, safe areas) and desktop; accessibility base; glow removal; the audit harness and the baseline report; the guard test that bans glow and sub-13 px text and checks contrast pairs | `resources/css/app.css`, `resources/js/Components/ui/**`, `resources/js/Components/layout/**`, `resources/js/Layouts/**`, `scripts/ui-audit/**`, `docs/UI-STANDARD.md`, `docs/UI-AUDIT-BASELINE.md`, `tests/Feature/Platform/UiStandardTest.php` |
| UX2 Front of house pages | Reservations, FrontDesk, Billing, Payments, Guests, Loyalty, Housekeeping, Maintenance, Messaging, ServiceRequests, Channels, Groups | those page folders |
| UX3 Money and stock pages | Finance, Reports, Documents, Stock, POS, Property | those page folders |
| UX4 People and admin pages | HR, People, Talent, Me, Workforce, Onboarding, Edge, Insights, Admin, Settings, Developer, Profile, Dashboard | those page folders |
| UX5 Public and guest pages | Landing, Auth/Login, Public booking site, Careers, Verify, member portal (`/account`), error pages | those page folders |

UX2 to UX5 start after UX1 lands the shared pieces. Each runs the harness at 375, 768 and 1280 px on every page it owns, fixes every finding, reruns until clean, and attaches a short before/after table to its report.

## Harness (built by UX1)
`scripts/ui-audit/audit.js` injected with the Claude browser tool after each navigation at each width. It reports per page: horizontal overflow, overlapping interactive elements, text under 13 px, contrast failures (computed colours with alpha compositing), touch targets under 44 px on touch widths, missing labels and alt text, computed shadows, text-shadows, filters and gradients, clipped text, fixed or sticky elements that cover content, wide tables escaping their container. `scripts/ui-audit/routes.json` lists every staff, public and member route. A runner note in docs/UI-STANDARD.md explains how to run it.

## Done means
Zero critical findings on every page at 375, 768 and 1280 px; contrast test green; guard test green; no regression in the existing suites; a screenshot check by the lead of the main pages per role on phone and desktop width.
