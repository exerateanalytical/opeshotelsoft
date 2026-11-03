# UI audit baseline (UX1)

Harness: `scripts/ui-audit/audit.js` (see docs/UI-STANDARD.md). Signed in as General Manager on a private server (`localhost:8020`), own browser tab.

## Honest scope note
There was no harness before the token, class and shell work landed, and the repository is not under git, so a true "before" run of the harness was impossible. "Before" below means the static baseline from the plan plus the first harness run, which already included the new tokens and shell but none of the per-page fixes. "After" is the last run after shell fixes found by the harness (title collapsing to 0 px on tablet and desktop, voice chip covering the last row, touch sizes, rail width, clipped nav items). Pages were not re-run at 375 and 768 after the very last header change; 1280 was.

## Static counts
| Item | Before | After |
|---|---|---|
| `shadow-` utilities in pages | 1 | 0 (also neutralised in theme tokens and a global `box-shadow: none`) |
| `ring-` uses | 8 (3 files) | 0 |
| `animate-pulse` | 1 | 0 |
| gradients, blur, drop-shadow, text-shadow | 0 | 0 (guard test enforces) |
| `text-slate-400` as text on light surfaces | 295 | 0 (259 codemodded to the muted token; 36 left on navy surfaces where it passes) |
| hard-coded sizes under 13 px | 17 | 0 |
| contrast pairs declared and passing | 0 | 31 of 31 (min 3.0 UI, 4.5 text) |

## Harness, critical findings per page and width (first run, then final where re-run)
| Page | 375 | 768 | 1280 |
|---|---|---|---|
| Landing `/` | 6 touch targets | not run | not run (session redirect) |
| Login | 11 (missing h1 and main landmark, label-less password toggle overlap, disabled-button contrast) | not run | not run |
| Dashboard | 12 first, 3 final | 3 | 1 (chart svg has no name) |
| Reservations list | 12 first, 10 | 11 | 2 (search input placeholder-only) |
| Reservations create | 54 (overflow-x, 27 unlabeled inputs, 26 small touch targets) | 4 | 17 (unlabeled inputs) |
| Tape chart | 104 touch targets | not run | 2 |
| Folios | 5 | 6 | 4 (unlabeled search and filters) |
| POS | 3 | 0 | 0 |
| Finance index | 0 | 0 | 0 |
| Stock | 0 | 0 | 0 |
| HR | 0 | 0 | 0 |
| Housekeeping | 12 (white-on-slate "All" filter pill contrast 1.1:1, touch) | 12 | 1 |
| Settings, hotels | 1 (16 px icon button) | 1 | 0 |
| Public booking `/book/opes-demo` | not run | not run | 0 |
Zero glow findings (box-shadow, text-shadow, filter, gradient, pulse) on every page measured, by computed style.

## Findings left for the page agents (outside the shared files)
- Placeholder-only inputs and unlabeled checkboxes (reservations, create, folios, tape chart): use `Field` or `aria-label` (FilterBar adds aria-label from placeholders as a safety net).
- Small touch targets in page-level pills, icon buttons, tape chart bars, checkboxes (wrap in a 44 px label).
- Housekeeping status pill `All (n)`: white text on slate-100.
- Reservations create: horizontal overflow at 375 px.
- Recharts surfaces need an accessible name (`role="img"` plus `aria-label`).
- Login: no h1 or main landmark (UX5).
- Dark-surface pages still use `text-slate-500` as dim text on navy in a few places; use `text-[var(--c-on-chrome-muted)]`.

## Screenshots, in words
Phone dashboard: navy bottom tab bar with Dashboard, Front Desk, Reservations, Housekeeping and More, gold top rule on the active tab, white app bar with logo, title, search and user, stat tiles in two columns, sound chip floating above the tab bar. Login on phone: navy page, gold primary button, clear two-tab Team and Member switch. Desktop: fixed navy sidebar with gold left marker on the active entry, white top bar, content in the main pane only.
