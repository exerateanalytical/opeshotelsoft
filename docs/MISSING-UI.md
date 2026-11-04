# Missing UI inventory (GAP1)

Method: `php artisan route:list --json` (972 `/api` routes, about 130 web page routes) compared with `resources/js/Pages/**`, `app/Http/Navigation.php` and every string path passed to `useApi(`/`api(`/`uploadFile(` in `resources/js`. Permissions from `database/seeders/permissions/*.php` (93 names). Read-only; no code touched.

Caveat: API matching is by literal path shape. A few endpoints built from a variable base (for example `${base}/stage`) can show as unmatched; those marked "verify" need a 1-minute grep before building.

Headline: no page file is dead (every `.tsx` under Pages is either routed or imported by a routed page). The weakness is not orphan files but (a) sub-screens reachable only from a tab strip or one in-page link, and (b) about 200 API routes with no caller.

## Table 1 - Screens that exist but are not in the sidebar

Ranked by hotel-operator value. "Reached via" = the only way a user gets there today. SB1: these are the candidates to link. Nav items reuse the existing `permission` of the parent entry unless stated.

| # | Path | Component file | Reached via today | Suggested role(s) | Suggested section |
|---|------|----------------|-------------------|-------------------|-------------------|
| 1 | `/finance/accruals` | `Pages/Finance/Accruals.tsx` | coreShared tab strip only | Accountant, Finance manager (`accounting.view`, `accounting.schedules`) | back_office (Finance group) |
| 2 | `/finance/month-close` | `Pages/Finance/MonthClose.tsx` | ab3Shared tab strip only | Finance manager (`accounting.close`, `accounting.close_manage`) | back_office |
| 3 | `/finance/bank-feed` | `Pages/Finance/BankFeed.tsx` | ab3Shared tab strip only | Accountant (`accounting.bank_feed`) | back_office |
| 4 | `/finance/tax` | `Pages/Finance/TaxVat.tsx` | ab3Shared tab strip only | Accountant, Owner (`accounting.tax`, `accounting.tax_file`) | back_office |
| 5 | `/finance/dunning` | `Pages/Finance/Dunning.tsx` | ab3Shared tab strip only | Accountant, Credit controller (`accounting.dunning`) | back_office |
| 6 | `/finance/cash-forecast` | `Pages/Finance/CashForecast.tsx` | ab3Shared tab strip only | Owner, Finance manager (`accounting.forecast`) | owner or back_office |
| 7 | `/finance/statements-pack` | `Pages/Finance/StatementsPack.tsx` | ab3Shared tab strip only | Owner, Accountant (`accounting.view`) | owner |
| 8 | `/finance/payroll-filings` | `Pages/Finance/PayrollFilings.tsx` | ab3Shared tab strip only | Payroll officer, Accountant (`payroll.filings`) | back_office (HR/Finance) |
| 9 | `/finance/consolidation` | `Pages/Finance/Consolidation.tsx` | coreShared tab strip only | Group finance (`accounting.consolidate`, `accounting.intercompany`) | owner |
| 10 | `/finance/dimensions` | `Pages/Finance/Dimensions.tsx` | coreShared tab strip only | Accountant (`accounting.dimensions`) | back_office |
| 11 | `/stock/reorder` | `Pages/Stock/Reorder.tsx` | StockTabs only | Storekeeper, Purchasing (`stockcontrol.view`) | back_office (Stock) |
| 12 | `/stock/stocktake` | `Pages/Stock/Stocktake.tsx` | StockTabs only | Storekeeper, F&B manager (`stockops.view`) | back_office |
| 13 | `/stock/scan` | `Pages/Stock/Scan.tsx` | StockTabs only | Storekeeper (`stockops.view`) | back_office |
| 14 | `/stock/minibar` | `Pages/Stock/Minibar.tsx` | StockTabs only | Housekeeping supervisor (`stockops.view`) | operations |
| 15 | `/stock/variance`, `/stock/valuation`, `/stock/batches`, `/stock/issues`, `/stock/menu-engineering` | `Pages/Stock/{Variance,Valuation,Batches,Issues,MenuEngineering}.tsx` | StockTabs only | F&B manager, Accountant (`stockcontrol.view`, `stockops.view`) | back_office |
| 16 | `/hr/workforce/time`, `/leave`, `/requests`, `/cost`, `/clock` | `Pages/HR/Workforce{Time,Leave,Requests,Cost,Clock}.tsx` | WorkforceTabs only (sidebar has only `/hr/workforce` = Roster) | HR manager, Department heads (`workforce.view`); `/clock` also all staff (kiosk) | back_office (HR) |
| 17 | `/hr/talent/skills`, `/training`, `/planning`, `/compliance` | `Pages/HR/Talent{Skills,Training,Planning,Compliance}.tsx` | TalentShared tab strip only (sidebar has only `/hr/talent` = Recruitment) | HR manager (`talent.view`) | back_office (HR) |
| 18 | `/people/reviews`, `/feedback`, `/tasks`, `/discipline`, `/exits` | `Pages/People/{Reviews,Feedback,Tasks,Discipline,Exits}.tsx` | People/Index tab strip only | HR manager, Dept heads (`people.view`, `people.discipline`, `people.exit`) | back_office (HR) |
| 19 | `/loyalty/members`, `/loyalty/promotions`, `/loyalty/counter` | `Pages/Loyalty/{Members,Promotions,Counter}.tsx` | Loyalty/Index and Members in-page links (promotions only from Members) | Front desk (counter), Marketing (promotions), Manager (`loyalty.cards`, `loyalty.promotions`) | operations |
| 20 | `/guests/companies` | `Pages/Guests/Companies.tsx` | in-page tab of Guests only | Sales, Front office manager (`guests.view`) | operations (Corporate accounts) |
| 21 | `/service-requests/settings` | `Pages/ServiceRequests/Settings.tsx` | single link on Board | Duty manager (`servicerequests.manage`) | settings |
| 22 | `/messaging/templates` | `Pages/Messaging/Templates.tsx` | single link on Inbox | Front office manager (`messaging.manage`) | settings |
| 23 | `/onboarding/import`, `/onboarding/templates` | `Pages/Onboarding/{Import,Templates}.tsx` | Onboarding wizard strip only | Admin, Implementer (`onboarding.manage`) | settings |
| 24 | `/payments/payouts/{id}`, `/payments/{id}` | `Pages/Payments/{PayoutShow,Show}.tsx` | detail pages, fine (listed for completeness) | Cashier, Accountant | - |
| 25 | `/finance` sub-pages Accounts, Journal, Receivables, Reconciliation, Periods, Closing, Assets, Reports | `Pages/Finance/{Accounts,Journal,Receivables,Reconciliation,Periods,Closing,Assets,Reports}.tsx` | tabs inside `Finance/Index.tsx` (no URL of their own, not deep-linkable) | Accountant | back_office (consider own URLs) |
| 26 | `/pos` sub-screens Floor plan, Menu, Setup, Day report, Terminal | `Pages/POS/{FloorPlan,MenuTab,Setup,DayReport,Terminal}.tsx` | tabs inside `POS/Index.tsx` (no deep link) | F&B manager, Cashier (`pos.view`, `pos.configure`) | billing |
| 27 | `/careers/offer/{token}`, `/sign/contract/{token}`, `/pay/{code}/{sig}`, `/verify/*`, `/book/*` | `Careers/Offer`, `HR/SignContract`, `Payments/PayLink`, `Verify/Show`, `Public/*` | token or public links (intentionally not in staff nav) | external | n/a |

Note on items 1-10: the Finance sidebar has only Finance, Budgets, Payables workflow. Ten fully built accounting screens are invisible unless the user first opens a screen that renders the right tab strip. This is the largest linking gap. Items 11-15 the same for Stock (sidebar has Stock, Recipes, RFQ). Item 16-18: HR has about 15 screens behind 5 sidebar entries.

Suggested new sidebar entries (labels need i18n keys `nav.*` in EN/FR): accruals, month_close, bank_feed, tax_vat, dunning, cash_forecast, statements_pack, payroll_filings, consolidation, dimensions; stock_reorder, stocktake, stock_scan, minibar, stock_variance, stock_valuation; time_attendance, leave, workforce_cost, clock (kiosk); talent_skills, training, succession_planning, compliance; reviews, feedback, hr_tasks, discipline, exits; loyalty_members, promotions, loyalty_counter; corporate_accounts; sr_settings.

## Table 2 - Backend capability with no screen at all

Roles: FO = front office, HK = housekeeping, ACC = accountant, FM = finance manager, HR = HR manager, SK = storekeeper, OWN = owner/GM, REV = revenue manager, IT = admin. Size S <= 0.5 day, M ~ 1-2 days, L >= 3 days. Ranked by operator value.

| # | Module | Endpoint / permission | Role | Size | Suggested screen |
|---|--------|-----------------------|------|------|------------------|
| 1 | Billing | `POST folios/{folio}/refunds` (`payments.refund`) | FO, ACC | S | "Refund" action on `Billing/Folios.tsx` payment rows (verify: payments/refunds exists in Payments/Show only) |
| 2 | Workforce | `POST workforce/swaps`, `POST workforce/requests/{req}/cancel`, `GET workforce/my-shifts`, `GET workforce/colleague-shifts` | All staff | M | "My shifts and swaps" panel in `Me/Index.tsx` |
| 3 | Workforce | `GET workforce/leave/balances`, `POST workforce/leave/adjust` | HR | S | Leave balances table + adjust modal in `HR/WorkforceLeave.tsx` |
| 4 | Workforce | `GET workforce/forecast` (labour forecast), `GET/PUT workforce/dept-rules`, `PUT workforce/employees/{e}/pin`, `PUT workforce/rosters/{roster}/shifts` (bulk save), `POST workforce/kiosk/{dir}`, `GET workforce/anomalies/{a}/photo` | HR, Dept heads | M | "Labour forecast and rules" tab; employee PIN field in `HR/EmployeeProfile`; anomaly photo viewer |
| 5 | Revenue | `GET revenue/walks`, `POST revenue/reservations/{r}/walk` (`revenue.manage`) | FO, REV | M | "Walk-in-overbooked" / relocation list on `Reservations/RevenueOverbooking.tsx` |
| 6 | Revenue | `GET revenue/upsell-offers`, `POST .../accept`, `.../decline`, `POST revenue/reservations/{r}/upsell-offers` (`revenue.upsell`) | FO, REV | M | "Upsell offers" tab on revenue pages + button on `Reservations/Show.tsx` |
| 7 | Revenue | `GET revenue/quote`, `PATCH revenue/contracts/{c}`, `GET .../availability` | REV, Sales | M | Rate quote tool; contract edit and availability on `RevenueOffers.tsx` |
| 8 | Reservations | `GET reservations/{r}/registration-card/id-photo`, `/signature`; `GET documents/registration-cards/{r}` | FO | S | "ID photo / signature / print card" links on `Reservations/Show.tsx` |
| 9 | Front desk | `GET/PUT front-desk/checkout-fee-rules` (`frontdesk.configure`) | FO manager | S | "Late checkout fee rules" section (new settings page or `FrontDesk` settings tab) |
| 10 | Accounting | `GET accounting/reports/{balance-sheet,income-statement,trial-balance,general-ledger,departmental,consolidated,vat,receivables-ageing}` | ACC, OWN | S | verify: `Finance/Reports.tsx` references some of these (dynamic); confirm departmental, GL, consolidated, VAT, ageing all selectable |
| 11 | Accounting | `GET accounting/core/fx/exposure`, `POST accounting/core/fx/month-end` | FM | M | "FX exposure and month-end revaluation" on `Finance/Closing.tsx` |
| 12 | Accounting | `GET/POST accounting/core/entries/{e}/attachments`, `GET .../audit`, `GET core/attachments/{a}` | ACC | S | Attachments and audit trail panel in Journal entry detail |
| 13 | Accounting | `GET accounting/core/budgets/{id}`, `budgets/group` | FM, OWN | S | Budget detail and group roll-up in `Finance/Budgets.tsx` |
| 14 | Accounting | `GET accounting/bank-accounts/{bank}/imports` | ACC | S | Import history on `Finance/BankFeed.tsx` |
| 15 | Accounting3 | `GET accounting3/tax/vat-returns/preview`, `/{id}`, `/{id}/verify`, `withholding-rates` (GET/POST), `stamp-duty/rules`, `suppliers/{s}/profile` (`accounting.tax`, `accounting.tax_file`) | ACC | M | VAT return preview/detail/verify drawer, WHT rate table, stamp duty rules, supplier tax profile on `Finance/TaxVat.tsx` (verify: only list/file may exist) |
| 16 | Accounting3 | `payables/invoices/{i}/submit`, `.../approvals`, `payables/captures/{id}`, `.../file`, `PUT payables/approval-chains/{id}` (`payables.approval_config`) | ACC, Approvers | M | Submit-for-approval and approval history on payables invoice; capture file preview; chain edit in `PayablesWorkflow.tsx` |
| 17 | Accounting3 | `close/templates` (GET/POST/PUT), `close/tasks/{id}/assign`; `dunning/holds/{id}/release`; `PUT bank-rules/{id}` | FM, ACC | M | Close checklist template editor, task assign, dunning hold release, bank rule edit |
| 18 | HR lifecycle | `GET/PUT hr/contract-templates`, `GET/PATCH hr/econtracts/{id}`, `POST hr/lifecycle/apply-due`, `GET hr/onboarding-tasks/mine`, `POST hr/sign/{token}/otp` (`hr.lifecycle.*`) | HR, new hires | M | Contract template editor + e-contract detail in `HR/Lifecycle.tsx`; "My onboarding tasks" in `Me/Index.tsx` |
| 19 | Talent | applicants `stage/notes/interviews/offers/pool/anonymise`, `interviews/{i}/scorecard,scores`, `offers/{o}/send,accept,withdraw`, `posts/{p}` PATCH+status, `privacy/eligible`, `employees/{e}/erase`, `applicants/purge-expired` (`talent.recruit`, `talent.interview`) | HR | verify | Most are called from `TalentRecruitment.tsx` via dynamic base (false positives); real gap is likely applicant detail drawer GET `applicants/{id}`, purge-expired and privacy erase (GDPR-style) buttons |
| 20 | Talent | `sessions/{s}/enroll,record,complete,cancel`, `employees/{e}/certifications`, `certifications/scan`, `employees/{e}/skills`, `employees/{e}/training`, `payroll/{run}/checks,acknowledge`, `requests/{req}/decide`, `DELETE successors/{s}` | HR | verify | Likely partly present in `TalentTraining/Skills/Planning/Compliance`; confirm payroll pre-checks/acknowledge (`talent.payroll_review`) and requests decide (`talent.requests`) |
| 21 | Stock | `GET/PUT/DELETE stock/items/{item}`, `stock/stores/{store}`, `suppliers/{supplier}`, `GET stock/wastage-reasons` | SK | S | Edit/delete on item, store and supplier rows in `Stock/Index.tsx` (Index has one DELETE only) |
| 22 | Stock control | `par-levels` (GET/PUT/DELETE), `lead-times` (+`{supplier}`), `item-controls/{item}`, `units/{item}` (+`convert`), `lots/fefo`, `recipes/{r}/history`, `variance/reviews/{r}` show/finalize/annotate | SK, F&B manager | M-L | Par levels editor, supplier lead times, item control and unit conversion in `Stock/Reorder.tsx` / `Stock/Recipes.tsx`; FEFO lots view in `Stock/Batches.tsx`; variance review finalise in `Stock/Variance.tsx` |
| 23 | Stock ops | `GET stock-ops/rfqs/{rfq}`, supplier-sheet, `scan/barcodes` (register), `scan/receive`, `scan/codes/item|lot` | SK | M | RFQ detail + supplier comparison sheet print; barcode register and scan-receive in `Stock/Scan.tsx` |
| 24 | Purchasing | `GET purchasing/orders/{order}`, `GET documents/purchase-orders/{order}` | SK, ACC | S | PO detail page and print |
| 25 | Documents | `GET documents/issued`, `documents/issued/{id}` (document register), `documents/ops/{kind}/regenerate` (`documents.ops.regenerate`), `documents/branding/designs`, `documents/folios/{folio}`, `documents/money/{kind}`, `documents/ops/{kind}` | FM, OWN | M | "Issued documents register" screen with search, re-download, verify log (`documents.verify.log`) and regenerate |
| 26 | Insights | `GET insights/digests`, `GET insights/alerts/{alert}` | OWN | S | Digest history tab on `Insights/Owner.tsx`; alert detail drawer |
| 27 | Messaging | `GET/POST messaging/accounts` (`messaging.manage`), `PUT/DELETE messaging/quick-replies/{id}` | FO manager | S | Channel accounts settings, quick-reply edit/delete in `Messaging/Inbox.tsx` |
| 28 | Notifications | `GET/PUT notifications/preferences/{guest}` | FO | S | "Contact preferences" card on `Guests/Show.tsx` |
| 29 | Property | `GET/PUT/DELETE rate-plans/{id}`, `room-types/{id}`, `outlets/{id}`, `assets/{id}` | Admin, Revenue | S | Detail/edit/delete in `Property/Crud.tsx` (only 3 mutation calls exist; verify per entity) |
| 30 | People | `GET/PATCH people/exits/{exit}`, `POST people/sanctions/{s}/withdraw`, `GET people/employees/{e}/feedback-summary` | HR | S | Exit detail edit in `People/Exits.tsx`; withdraw sanction in `People/Discipline.tsx`; feedback summary on employee profile |
| 31 | Payments | `POST payments/payouts/{payout}/reject`, `GET payments/links/{link}` | Cashier, ACC | S | Reject item button in `Payments/PayoutShow.tsx`; link detail |
| 32 | Experience | `GET experience/bookings/{ref}`, `PATCH experience/reservations/{r}/preferences` | FO | S | Guest preferences editor on `Reservations/Show.tsx` |
| 33 | Service requests | `POST service-requests/escalate` | HK, FO | S | Escalate button on `ServiceRequests/Board.tsx` |
| 34 | Careers | `POST careers/offers/{token}/accept|decline` | candidate | S | verify: probably used by `Careers/Offer.tsx` via another path |
| 35 | Edge | `POST edge/sync/pull|push` | IT | S | machine endpoints; no UI needed |
| 36 | Public booking | `booking/{group}/availability|hotels|reservations|manage/*|upsell/*` | guests | n/a | Called by `Public/*` pages through a shared base (false positives); no action |
| 37 | Auth | `auth/login|logout|token|token/two-factor` | n/a | n/a | Used via Inertia forms; no action |

Permissions with no matching `can(...)` in the frontend or Navigation (backend-gated only; the screen exists or does not): accounting.audit, accounting.bank_feed, accounting.budgets_approve, accounting.close_manage, accounting.consolidate, accounting.dimensions, accounting.dunning(_config), accounting.forecast, accounting.intercompany, accounting.period_approve, accounting.schedules, accounting.statements, accounting.tax_file, documents.ops.regenerate, documents.verify.log, frontdesk.configure, guests.privacy_approve, hr.lifecycle.approve, hr.lifecycle.manage, invoices.credit, loyalty.adjust_large, loyalty.cards, loyalty.promotions, messaging.manage, messaging.reply, notifications.manage, payables.approval_config, payments.manage, payments.refund, payroll.filings, people.discipline, people.exit, people.tasks, pos.comp, pos.configure, revenue.approve, revenue.manage, revenue.upsell, talent.interview, talent.manage, talent.payroll_review, talent.recruit, talent.requests, workforce.kiosk, workforce.manage, workforce.publish. Consequence: pages show buttons the user is then refused on (403) because the UI does not hide actions by these permissions. Recommend a `can()` gate on: `invoices.credit` (credit note button), `payments.refund`, `pos.comp`, `loyalty.adjust_large`, `workforce.publish` (roster publish), `revenue.approve`, `accounting.period_approve`, `guests.privacy_approve`.

## Table 3 - Missing affordances on existing pages (top 40 by value)

Found by comparing each page's `api(...)` method set and component use against what its routes allow. "Verify" means the signal is a count of calls, not a read of the full page.

| # | Page file | Missing affordance | Backend that supports it |
|---|-----------|--------------------|--------------------------|
| 1 | `Reservations/Index.tsx` | No CSV/XLSX export of arrivals/list; only 1 mutation call so no bulk actions (cancel, assign room, send message) | `reservations` index filters; export via `Components/ExportButtons.tsx` |
| 2 | `Billing/Folios.tsx` | No refund action; no export of folio list | `POST folios/{folio}/refunds` |
| 3 | `Billing/Invoices.tsx` | No export/CSV; credit-note button not gated on `invoices.credit` | `invoices.credit` |
| 4 | `Billing/Cashier.tsx` | No export of shift report / cash count history | `cashier-shifts` |
| 5 | `Reservations/Show.tsx` | No upsell offer, no preferences edit, no registration card ID photo/signature/print | `revenue/reservations/{r}/upsell-offers`, `experience/.../preferences`, registration-card endpoints |
| 6 | `Reservations/RevenueOverbooking.tsx` | No "walk guest" action or walk history | `POST revenue/reservations/{r}/walk`, `GET revenue/walks` |
| 7 | `Stock/Index.tsx` | Items/stores/suppliers: no edit or delete rows (only 1 DELETE in file); no export | `PUT/DELETE stock/items|stores|suppliers/{id}` |
| 8 | `Stock/Reorder.tsx` | No par-level or lead-time editor | `stock-control/par-levels`, `lead-times` |
| 9 | `Stock/Variance.tsx` | No review detail, annotate lines or finalize | `stock-control/variance/reviews/{r}` show/annotate/finalize |
| 10 | `Stock/Scan.tsx` | No register-barcode or scan-receive flow | `stock-ops/scan/barcodes`, `scan/receive` |
| 11 | `Stock/Rfq.tsx` | No RFQ detail view or supplier comparison sheet print | `stock-ops/rfqs/{rfq}`, `supplier-sheet/{supplier}` |
| 12 | `Housekeeping/Index.tsx` | No export/print of the daily assignment sheet; no empty-state component (0 EmptyState uses) | housekeeping endpoints; print via `OpsPrintButton` |
| 13 | `Maintenance/Index.tsx` | No export; no asset link (asset show/update unused) | `assets/{asset}` |
| 14 | `Groups/Index.tsx` | No empty-state with create CTA (0 EmptyState); no export of rooming list | groups endpoints |
| 15 | `Loyalty/Members.tsx` | No export of members; no large point adjustment gating (`loyalty.adjust_large`) | `loyalty` export |
| 16 | `Settings/Users.tsx` | No delete/deactivate confirm, no password reset or force-2FA-reset action visible (only PATCH+POST); no export | `users/{user}` PATCH |
| 17 | `Settings/Hotels.tsx` | No archive/deactivate (route is `except('destroy')`) so UI is complete but lacks a status toggle if API supports `is_active`; verify | `hotels` |
| 18 | `Admin/Audit.tsx` | No CSV export or entity filter drill-down | `audit` |
| 19 | `Finance/Journal.tsx` | Entry detail lacks attachments panel and per-entry audit trail | `accounting/core/entries/{e}/attachments`, `/audit` |
| 20 | `Finance/Closing.tsx` | No FX exposure view or month-end revaluation | `accounting/core/fx/exposure`, `fx/month-end` |
| 21 | `Finance/Budgets.tsx` | No budget detail or group roll-up view | `accounting/core/budgets/{id}`, `budgets/group` |
| 22 | `Finance/BankFeed.tsx` | No import history; no edit bank rule | `bank-accounts/{b}/imports`, `PUT bank-rules/{id}` |
| 23 | `Finance/TaxVat.tsx` | No VAT return preview/verify, WHT rate table, stamp-duty rules, supplier tax profile | accounting3 tax routes |
| 24 | `Finance/Dunning.tsx` | No release-hold action | `POST dunning/holds/{id}/release` |
| 25 | `Finance/PayablesWorkflow.tsx` | No submit-for-approval, no approval history, no capture file preview, no edit approval chain | accounting3 payables routes |
| 26 | `Finance/MonthClose.tsx` | No close-template editor, no task assign | `close/templates`, `close/tasks/{id}/assign` |
| 27 | `Finance/Receivables.tsx` | No export of ageing (verify) | `accounting/reports/receivables-ageing` |
| 28 | `HR/WorkforceLeave.tsx` | No leave balances table or manual adjustment | `workforce/leave/balances`, `leave/adjust` |
| 29 | `HR/WorkforceRoster.tsx` | No bulk shift save from grid (verify); no forecast overlay | `PUT workforce/rosters/{r}/shifts`, `workforce/forecast` |
| 30 | `HR/WorkforceRequests.tsx` | No cancel own request; no swap request | `requests/{req}/cancel`, `workforce/swaps` |
| 31 | `HR/Lifecycle.tsx` | No contract template editor, e-contract field edit, "apply due" batch button | `hr/contract-templates`, `PATCH hr/econtracts/{id}`, `hr/lifecycle/apply-due` |
| 32 | `Me/Index.tsx` | No my-shifts, colleague shifts, swap, or my onboarding tasks | `workforce/my-shifts`, `colleague-shifts`, `hr/onboarding-tasks/mine` |
| 33 | `HR/EmployeeProfile.tsx` | No PIN set for kiosk clock; no feedback summary | `PUT workforce/employees/{e}/pin`, `people/employees/{e}/feedback-summary` |
| 34 | `People/Exits.tsx` | No exit detail/update | `GET/PATCH people/exits/{exit}` |
| 35 | `People/Discipline.tsx` | No withdraw sanction | `POST people/sanctions/{s}/withdraw` |
| 36 | `Payments/PayoutShow.tsx` | No reject-items action | `POST payments/payouts/{p}/reject` |
| 37 | `Messaging/Inbox.tsx` | Quick replies: no edit/delete; no channel account setup | `PUT/DELETE messaging/quick-replies/{id}`, `messaging/accounts` |
| 38 | `Guests/Show.tsx` | No contact/notification preferences editor | `notifications/preferences/{guest}` |
| 39 | `ServiceRequests/Board.tsx` | No escalate button | `POST service-requests/escalate` |
| 40 | `Insights/Owner.tsx` | No digest history; no alert detail | `insights/digests`, `insights/alerts/{alert}` |

Cross-cutting: pages with no export/print component and no `EmptyState` per grep: Groups/Index, Housekeeping/Index, Reservations/Index (1 EmptyState but no export), Billing/*, Finance/Journal|Accounts|Receivables, Loyalty/Members, Messaging/Inbox, POS/Index, Payments/Index, ServiceRequests/Board, Admin/Audit, HR/Employees, Maintenance/Index.

## Counts

- Table 1: 23 actionable link gaps covering about 55 individual screens (largest: Finance 10, Stock 13, HR/People 14).
- Table 2: about 30 real gaps plus 7 informational/false-positive rows; 203 of 972 API routes have no caller (including public/machine endpoints and dynamic-base false positives).
- Table 3: 40 rows.
- 48 permissions have no frontend gate.
