# Wave 3: four flagship domains

Owner goal (2026-11-03): the most advanced HR, Inventory, Accounting and Booking/Reservation flow.

"Best" is not a thing a test can prove, so each domain has a **capability checklist** (what leaders such as Oracle OPERA, Mews, Cloudbeds, Sage, Odoo, Workday, MarketMan and Apicbase offer, adapted to CEMAC) and a **differentiator** (what none of them do well here). A domain is done when every checklist line has working code, tests, FR/EN screens and a line in the certification register. Items needing outside parties stay in docs/BLOCKERS.md.

Rules: max 4 agents at once; one owner per file set; hard rules of PLAN-PHASE-3.md apply; each domain is split in two sequential agents (A then B) so no agent holds more than it can finish.

## 1. Booking and reservation flow

Already built: ARI, restrictions, groups/blocks, waitlist, seasons, travel agents, tape chart, channels (dry-run), booking site, pay links, WhatsApp journey (in progress).

| Agent | Scope |
|---|---|
| R-A Revenue engine | Rate plans with derived rates and packages; dynamic pricing suggestions (occupancy, pickup pace, day of week, local events calendar, competitor rate entry); length-of-stay and advance-purchase rules; overbooking limits with walk-policy; upsell engine (room upgrade offers at booking, pre-arrival and check-in with price per night); best-available-rate logic on the booking site; promo codes and corporate negotiated rates with allotments and last-room-availability |
| R-B Reservation experience | Drag-and-drop tape chart (move, extend, split stay, room swap) with conflict checks; multi-room and multi-guest booking in one flow; guest self-service (modify, cancel, add services, early/late check-out purchase); no-show and cancellation automation with deposits; group rooming list import; digital key-ready check-in (ID scan fields, pre-filled registration card, signature); smart room assignment (preferences, adjacency, housekeeping state, loyalty tier); channel two-way sync health board |

Differentiator: works offline at the hotel (edge node) and takes Mobile Money deposits at booking.

## 2. Accounting

Already built: SYSCOHADA GL, VAT, statements, receivables/payables, reconciliation, fixed assets, cash flow, DSF export, FX revaluation, year-end close.

| Agent | Scope |
|---|---|
| A-A Core ledger | Multi-currency ledgers with revaluation automation; budgets by account/department/hotel with variance; cost centres and analytic accounting (department, outlet, project); accruals and prepayment schedules; recurring journals; period locking with approval; inter-hotel and inter-company eliminations with consolidated group statements; audit trail on every entry with attachments |
| A-B Payables, tax, close | Supplier invoice capture with OCR-ready fields and 3-way match (exists) plus approval workflows by amount; customer dunning (reminder letters, escalation); VAT returns per country (Cameroon, Gabon, Congo, Chad, CAR, Eq. Guinea), withholding tax, stamp duty; CNPS/IRPP payroll tax filings export; financial statements pack (SYSCOHADA révisé) as PDF/Excel; bank feed import (CSV/MT940/CAMT) with rules engine; cash-flow forecast 13 weeks; month-end close checklist with owner sign-off per task |

Differentiator: one-click statutory pack per CEMAC country; accountant read-only portal. Needs the chartered accountant sign-off.

## 3. Inventory

Already built: items, stores, movements, counts, transfers, POs, GRN, 3-way match, requisitions, price lists, returns, WAC, aged payables.

| Agent | Scope |
|---|---|
| I-A Control | Recipe/BOM costing with yield and waste; theoretical vs actual usage variance per outlet; par levels and automatic reorder with suggested POs by supplier lead time; batch/lot and expiry tracking with FEFO and expiry alerts; unit-of-measure conversions (case/each/kg); stock valuation reports (FIFO/WAC) |
| I-B Operations | Supplier portal and RFQ (compare quotes, award); barcode/QR scanning for receiving and counts (camera, no extra hardware); mobile stocktake with blind counts and recount rules; consignment and minibar replenishment from housekeeping; menu engineering (cost %, margin, popularity) feeding POS; fixed-asset and uniform tracking; leakage alerts hook into Insights |

Differentiator: bar and kitchen theft detection (usage vs sales) and offline stock counts.

## 4. HR (builds on Q2 lifecycle and Q3 People)

| Agent | Scope |
|---|---|
| H-A Workforce | Shift scheduling with demand forecast from occupancy; auto-rostering with labour-law rules (rest, weekly hours, night work); shift swap and open-shift marketplace; leave policies with accrual rules and carry-over; time and attendance with geofenced mobile or PIN clock, photo proof and anomaly flags; labour cost vs revenue dashboard |
| H-B Talent and compliance | Recruitment (job posts, applicant pipeline, interview scoring, offer letter to e-contract); skills and certification matrix with expiry (food safety, first aid, fire); training plans and records; succession and headcount planning; employee self-service portal (payslips, leave, documents, requests) web first; payroll variance and audit checks before approval; labour-inspectorate and CNPS exports; ISO-style employee data export and erasure |

Differentiator: labour cost tied to occupancy; Mobile Money salary payout (Q5); contracts signed on a phone (Q2).

## Order and dependencies

Start order as slots free: R-A, A-A, I-A, H-A, then R-B, A-B, I-B, H-B. Four agents max. Two agents never touch the same module at once (R-A/R-B are sequential, etc.). Cross-domain hooks (recipes ↔ POS, labour ↔ payroll, budgets ↔ stock) are done by the agent that owns the consuming side, through application services.

## Definition of done (every agent)
- Tests for each capability; money flows assert a balanced trial balance.
- FR/EN keys (lang/{fr,en}/<ws>.json), flat UI, RBAC permissions and nav entries reported.
- Browser check by the lead; register and BLOCKERS updated.
- Items that need a human expert are named, not hidden.
