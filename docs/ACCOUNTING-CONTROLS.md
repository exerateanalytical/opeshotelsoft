# Accounting controls (A-C hardening, 2026-11-04)

Every write to the general ledger goes through `App\Modules\Accounting\Application\Ledger::post()` (no other code creates
`journal_entries` or `journal_lines`). Ledger guarantees, for every caller below:

- **Balanced**: sum debit = sum credit and > 0, otherwise 422 `unbalanced-entry` (a zero entry is not written).
- **Valid accounts**: active and postable, otherwise 422 `invalid-account`.
- **Period lock**: month not closed (422 `period-closed`) and fiscal year not locked (422 `year-locked`). Only the year-end closing entry (`closing` meta) may post in a closed month of an unlocked year.
- **Tenant**: the hotel comes from the tenant context (`X-Hotel-Id` resolved and checked against the user's hotels by `ResolveTenant`); entries and lines carry `hotel_id`, and `JournalEntry` is hotel-scoped (`BelongsToHotel`), so route-bound entries of another hotel are 404.
- **Audit row**: `accounting_audit_log` `posted` (append-only, a database trigger rejects UPDATE/DELETE), plus event `accounting.entry_posted`.
- **Idempotency**: `source_event` is unique; a replayed event returns without posting. `source_type` / `source_id` are stored on every automated posting.
- **Immutability**: there is no update or delete route for entries, lines or attachments. Only the `reversed` flag changes, through `Ledger::reverse()`.

Reversals (`Ledger::reverse`): refused if the entry is already reversed or is itself a reversal (no double reversal, no reversal of a reversal); the reversal is posted through `post()`, so it is refused in a closed period or locked year (pass a `date` in an open period); a reason is required by both endpoints; audit row `reversed`.

## HTTP write endpoints (prefix /api/v1)

| Endpoint | Permission | Other controls |
|---|---|---|
| POST accounting/entries (legacy manual entry) | accounting.post_manual | Ledger rules; **now via ManualEntries**: at least one attachment unless hotel setting `accounting.attachments_optional` is true (422 `attachment-required`); accepts JSON or multipart (`attachments[]`, `lines` as JSON string) |
| POST accounting/core/entries | accounting.post_manual | Ledger rules; attachment always required |
| POST accounting/entries/{id}/reverse, POST accounting/core/entries/{id}/reverse | accounting.post_manual | reason; reversal rules above |
| POST accounting/core/entries/{id}/attachments | accounting.post_manual | append-only, audit `attachment_added` |
| PUT accounting/accounts | accounting.manage | chart of accounts (no ledger write) |
| POST accounting/periods/close (legacy) | accounting.close_period | trial balance balanced, suspense 471 cleared; **now writes audit `period_closed`** (still single-user: see residual risks) |
| POST accounting/periods/reopen (legacy) | **accounting.period_approve** (was: role group-owner or finance-director) | reason required; **now a wrapper over PeriodLocking**: creates a reopen request (202, pending) or, if another user's reopen request is pending, approves it (200); requester can never approve their own |
| POST accounting/core/period-locks | accounting.close_period | reason required for reopen; one pending request per period; audit `lock_requested` |
| POST accounting/core/period-locks/{id}/decide | accounting.period_approve | four-eyes (decider ≠ requester); close re-checks balance and suspense; reopen refused in a locked year; audit |
| POST accounting/year-end/close | accounting.close_period | YearEndClose (closing entry, locks the year) |
| POST accounting/fixed-assets, .../dispose, POST accounting/depreciation-runs, POST accounting/asset-classes | accounting.manage | Ledger rules; source_type fixed_asset* |
| POST accounting/fx-revaluations, POST accounting/core/fx/month-end | accounting.manage | Ledger rules; source_type fx_revaluation |
| POST accounting/bank-accounts, .../statements, .../auto-match, statement-lines match/unmatch/difference | accounting.manage | difference posts through Ledger |
| POST accounting/core/budgets, budgets/{id}/approve | accounting.budgets, accounting.budgets_approve | no ledger write |
| POST accounting/core/dimensions, dimension-defaults | accounting.dimensions | no ledger write |
| POST accounting/core/schedules, schedules/run, schedules/{id}/cancel, recurring | accounting.schedules | Ledger rules; source_event per schedule item / recurring run |
| POST accounting/core/intercompany, intercompany/{id}/reverse | accounting.intercompany | both sides through Ledger with `source_event intercompany:{id}:{side}`; reason for reverse |
| POST accounting3/payables/* (chains, submit, decide, delegations, captures, convert) | payables.approval_config / manage / approve / capture | approval chain; posting happens on `payables.invoice_approved` event |
| POST accounting3/bank-accounts/{bank}/feed, apply-rules, statement-lines/{line}/accept-rule, bank-rules | accounting.bank_feed | rule postings through Ledger |
| PUT/POST accounting3/dunning/* | accounting.dunning(_config) | no ledger write |
| POST accounting3/tax/* (profile, vat-returns prepare/file/amend, withholding, stamp duty, payroll filings) | accounting.tax / tax_file | VAT settlement, withholding remittance, stamp duty through Ledger with source_type/id |
| POST accounting3/close/* (statement packs, templates, runs, tasks) | accounting.statements / close / close_manage | month-end close finally locks through PeriodLocking (four-eyes) |
| POST receivables/invoices/{invoice}/payments | receivables.manage | posts through folio events → PostingRules |

## Event postings (PostingRules, subscribers) — all through Ledger with source_type, source_id and source_event = event uuid

folio lines (`folio_line`), POS direct settlement (`pos_check`), stock movements (`stock_movement`), supplier invoice (`supplier_invoice`) and payment (`supplier_payment`), payroll run (`payroll_run`) and payment (`payroll_payment`), loyalty (`loyalty`). Other services posting directly: PayoutService (salary payouts), PayLinkService (pay links), CreditNotes, Onboarding Importer (opening balances, `onboarding_import`), Reconciliation, BankFeed, StampDuty, VatReturns, Withholding, FixedAssets, FxRevaluation, YearEndClose, Deferrals, RecurringJournals, Intercompany.

## Hotel setting `accounting.attachments_optional`

- Hotels existing on 2026-11-04 were set to `true` by migration `2026_12_20_000000_legacy_manual_entry_attachments_setting` (unchanged behaviour).
- Hotels created afterwards have no key, which means **strict**: legacy manual entries need a document.
- To make an existing hotel strict: set `settings["accounting.attachments_optional"] = false` on the hotel.

## Amount threshold with a second approver: not implemented (why)

There is no four-eyes mechanism for journal entries: the Ledger only knows posted entries (no draft or pending state), and
every report, close check and reconciliation reads posted lines directly. A threshold approval would need a new pending-entry
store, an approval inbox and a post-on-approval step; doing it inside the Ledger would change the "only posted" invariant across
all modules. Until then the controls are: permission accounting.post_manual, mandatory supporting document (strict hotels),
append-only audit, and after-the-fact review in the audit log. Suggested design for later: a `manual_entry_requests` table
reusing the PeriodLocking pattern (request, decide by a different user with accounting.period_approve, post on approval).

## Residual risks

- Legacy `POST accounting/periods/close` is still single-user (a user with accounting.close_period closes directly). Kept because screens and tests rely on it; the four-eyes path is `core/period-locks`.
- `ManualEntries` stores `approved_by` = the poster (self). It is not an independent approval.
- `general-manager` and `group-owner` hold `*`, so they also pass accounting.period_approve (they can approve another user's reopen request, never their own).
- Server error messages for these rules are English only (no server-side translation table exists for accounting messages).
