# Plan: translate module screens (FR/EN)

Goal: every staff and public screen switches between French and English with the language switch. French is the default.

## Method
- Strings move into `t('<area>.<screen>.<thing>')` calls (`useT` from `@/lib/i18n`).
- Each workstream owns **one pair of language files**: `lang/fr/<ws>.json` and `lang/en/<ws>.json` (merged at runtime by `HandleInertiaRequests::translations`). Nobody edits `lang/fr.json` / `lang/en.json` or another workstream's files.
- Keys must start with the workstream's own prefixes (below) so files never collide.
- Shared keys already in `lang/fr.json` (e.g. `common.*`, `nav.*`) may be reused, not changed.
- No logic changes. API values (status codes, enum values) stay as they are; only their displayed labels are translated.
- French uses the hotel vocabulary used in Cameroon/CEMAC (e.g. *main courante*, *arrhes*, *note client / folio*, *recouvrement*, *grand livre*, *bulletin de paie*), and amounts/dates use the existing formatters.

## Workstreams (max 6 at once)

| WS | Pages / components owned | Lang file | Key prefixes |
|---|---|---|---|
| T1 | `Pages/Reservations/*`, `Pages/Groups/*`, `Pages/Channels/*` | `reservations.json` | `res.`, `groups.`, `channels.` |
| T2 | `Pages/FrontDesk/*`, `Pages/Billing/*`, `Pages/Payments/*`, `Components/CashierBar.tsx`, `Components/ExportButtons.tsx`, `Pages/Errors/*` | `frontoffice.json` | `fo.`, `billing.`, `payments.`, `cashier.`, `export.`, `errors.` |
| T3 | `Pages/Finance/*`, `Pages/Reports/*`, `Pages/Documents/*`, `Pages/Developer/*` | `finance.json` | `fin.`, `reports.`, `docs.`, `dev.` |
| T4 | `Pages/HR/*`, `Pages/Notifications/*`, `Pages/Property/*` | `hr.json` | `hr.`, `notif.`, `prop.` |
| T5 | `Pages/POS/*`, `Pages/Stock/*` | `operations.json` | `pos.`, `stock.` |
| T6 | `Pages/Guests/*`, `Pages/Loyalty/*`, `Pages/Housekeeping/*`, `Pages/Maintenance/*`, `Pages/Public/*` | `guests.json` | `guests.`, `loyalty.`, `hk.`, `maint.`, `public.` |

## Done criteria per workstream
1. No user-visible English literal left in owned files (headings, labels, buttons, placeholders, table headers, empty states, toasts, confirm dialogs, validation messages built in the page).
2. FR and EN files have identical keys, valid JSON.
3. `npx tsc --noEmit` shows no errors in owned files.
4. Report lists files changed and key count.

## Lead verification
- Merge check: no duplicate keys across fragments; parity test passes.
- Full `php artisan test`, `tsc`, `npm run build`.
- Browser: switch to French and open one page per workstream; switch back to English.
- Grep owned files for leftover literals.
