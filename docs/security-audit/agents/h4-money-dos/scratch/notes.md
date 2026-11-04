# h4-money-dos — notes

Scope: money/business-logic abuse (priority 6), DoS/limits (8), personal data (9). Source inspection only, no execution.

## Reviewed paths

### Platform
- app/Platform/Http/Idempotency.php — optional Idempotency-Key, scoped per user+method+path; replay returns stored response. Only protects callers that send the header.
- app/Platform/Domain/NumberSequence.php — row-locked gapless counter; correct only inside a caller transaction.
- app/Platform/Tenancy/BelongsToHotel.php — global group/hotel scope.
- app/Platform/ModuleServiceProvider.php, app/Modules/Platform/Ops/OpsBootstrap.php, app/Modules/Admin/AdminModule.php, app/Modules/Documents/DocumentsModule.php — rate limiters (public-write 20/min/ip+uri + 200/h + 300/min/group; public-read 300/min + 20k/day; api 1200/min; auth-token 10/min; verify 30/min).
- routes/console.php — all scheduled commands withoutOverlapping; per-hotel iteration helper.

### FrontOffice
- Http/FolioController.php, Application/FolioService.php (full read)
- Http/CashierController.php, Application/CashierService.php::close
- Http/FrontDeskController.php (perm outline), Application/FrontDeskService.php::checkOut
- Http/FrontDeskExtrasController.php lines 160-268 (registration card, id photo, signature)

### Payments
- Http/PaymentController.php, Http/PublicPaymentController.php, Http/MobileMoneyController.php, Http/PublicPayLinkController.php (full)
- Application/PaymentService.php, PayLinkService.php, InboundMatcher.php, Payouts/PayoutService.php, Drivers/DryRunGateway.php (full)
- public-routes.php, routes.php

### POS
- Http/PosController.php, Application/PosService.php, Domain/Check.php::recalculate (full)

### Accounting
- Http/AccountingController.php (entries, postManual, reverse, reports, closePeriod, reopenPeriod, consolidated), Application/Ledger.php (full)
- Application/Core/ManualEntries.php, Core/PeriodLocking.php (full); Http/Core/CoreController.php lines 212-245
- Http/ReceivablesController.php (full); routes.php, routes_core.php, routes_ab.php

### HR
- Http/PayrollController.php, Application/PayrollService.php (full)
- Http/HrController.php (outline + updateEmployee, grantAdvance, employees, showEmployee)
- SelfService/SelfServiceController.php, SelfService/SelfService.php (full)
- Talent/Http/TalentController.php::decideRequest; Talent/Application/PayrollChecks.php::assertReviewed
- Application/Lifecycle/LifecycleService.php (SoD grep: assertSod exists for compensation changes and transfers; EContract countersign SoD)
- routes.php, public-routes.php, Talent/routes.php, Talent/public-routes.php

### Stock
- Http/StockController.php, Application/StockService.php (full)
- Application/ProcurementService.php (outline + recordInvoice/approveInvoice/pay), Http/ProcurementController.php (perm outline)
- Http/PurchasingExtrasController.php::createReturn, Application/PurchasingExtrasService.php::returnToSupplier

### Loyalty
- routes.php; Http/LoyaltyController.php (outline); Application/LoyaltyService.php lines 141-190 (redeemOnFolio, adjust); Application/PosLoyalty.php; Application/CardService.php::replace

### Reservations
- Http/ReservationController.php (full); Application/ReservationService.php (outline: cancel/no-show/assignRoom lock); Application/Inventory.php (lockRow)

### Guests / GuestPortal / Documents / Admin / Notifications / Edge
- Guests/Application/PrivacyService.php (full), Guests/Domain/Guest.php (logAll activity), Guests/Http/GuestController.php (pagination grep)
- GuestPortal/Http/BookingEngineController.php::lookup and ->only() presenters; public-routes.php
- Documents/Http/VerifyController.php::json/data
- Admin/Http/AuditController.php (paginated, scoped by group)
- Notifications/Http/WhatsAppWebhookController.php (signature checked before processing)
- Edge/Http/EdgeSyncController.php (changes max:1000, pull limit max:1000)
- app/Http/Controllers/DemoRequestController.php + routes/web/landing.php (throttle:5,10, honeypot, no job dispatch)
- Models: $hidden grep across app; User (password, mfa secrets hidden; activity log logOnly name/email/status), MemberAccount, PaymentGatewaySetting, Connection, EdgeNode, EContract, Offer, Applicant hidden.
- Uploads: all validate() with 'file' have max: (1-10 MB) and mimes except Onboarding import (max:5120, no mimes); nginx client_max_body_size 25m, php upload_max 20M / post_max 25M / memory 256M.
- Housekeeping/Maintenance controllers: permission helper pattern checked only (view/manage), not deep-read.

## SAFE list (one line each)

- Idempotency middleware: request hash bound to key; mismatch returns 422 — replay safe when header used.
- FolioService::payment: amount>0, method must be active, city_ledger kind refused, cashier shift required for cash/card/MoMo/cheque; post() locks folio.
- FolioService::reverse: void needs folios.void; already-reversed and transfer lines refused; receipts voided in Documents.
- FolioService::close: refuses non-zero balance; checkOut closes all open folios so a guest cannot leave with a balance.
- FolioService::invoice: gapless number inside transaction.
- FolioService::transferToCityLedger: credit_limit + approval checks (bypassable only via plain transfer — see candidate).
- FolioController::charge: amount integer min:1, quantity min:0.001, charge code must be active; bigint columns so no overflow at realistic values.
- Cashier close: only own shift or cashier.manage; counted min:0; variance recorded and evented.
- Registration-card ID photo/signature: served only to frontdesk.view/manage, Cache-Control private no-store, local disk; upload validated (image mimes, 5 MB).
- PaymentService::create: amount 1..100,000,000 (staff), provider whitelist, closed folio / finished reservation refused, disabled gateway refused; production refuses unconfigured gateway.
- PaymentService::handleCallback: signature verified per driver, event key = sha256(body) with unique constraint → replay acknowledged and ignored; settle() locks the intent and is idempotent via isFinal().
- PaymentService::settle: single posting per intent under lockForUpdate.
- Public deposit: bound to manage token; amount capped at booking balance.
- Pay link: 8-char random code + 10-hex HMAC sig; startPayment caps at balance minus pending intents, link locked; apply() locks link/invoice and re-checks invoice balance.
- Inbound MoMo relay: HMAC over body with hotel callback_secret (app key only outside production); dedupe by transaction_id; auto-post only ≥90 score and amount ≤ balance.
- InboundMatcher::post: status must be 'review', amount ≤ link balance.
- PayoutService::create: only approved payroll, one payout per payroll (duplicate check); markPaid blocked once payout completes (status transition).
- PayoutService::postPaid: run locked, GL posted once per item (journal_entry_id null filter).
- PosService::settle: check locked, must be open, no pending comps, payments must equal total, unknown/inactive methods refused, shift required for cash/card/MoMo, loyalty tender validated and member-locked.
- PosService::chargeToRoom: check locked, only checked_in reservations.
- PosService::voidLine/voidCheck/discount: need pos.void/pos.discount; discount ≤ subtotal-comp; void after settlement impossible (assertOpen).
- POS comps: non-supervisor requests are pending and block settlement until pos.comp decides.
- PriceList (happy hour) applied server-side from current time; client cannot pick a price list.
- Ledger::post: balanced, >0, accounts active+postable, period open, year not locked, idempotent via source_event; number sequence in transaction.
- Period close: accounting.close_period; trial balance must balance, suspense cleared; direct reopen limited to finance-director/group-owner and audited; PeriodLocking four-eyes path refuses requester==approver.
- Consolidated report: requires all_hotels.
- ReceivablesController::pay: receivables.manage; amount ≤ invoice balance (invoice not locked — minor race, hardening).
- Payroll approve: draft only, preparer≠approver (first creator only — see candidate), optional review checklist, certified rule pack in production; pay needs payroll.approve.
- Payroll payslip/mine: own employee or payroll.view/manage; tenant scoped via run lookup.
- Rule pack certify: group-owner/finance-director only.
- SelfService: acts only on employee linked to user; bank/MoMo changes are requests; profile masks account numbers; contract signing OTP.
- LifecycleService: assertSod on compensation changes and transfers; countersign SoD.
- StockService: item + balance rows locked; negative stock refused except POS sale when hotel allows; consume/transfer/receive require qty>0.
- Stock count post: needs stock.adjust; delta computed against live on-hand.
- Supplier return: controller min:0.001, cannot exceed received minus returned (GRN line locked).
- Procurement: PO approve refuses requester, dual-approval role above threshold; invoice must 3-way match within tolerance; pay ≤ total and only approved invoices; invoice approval needs payables.manage AND purchasing.approve plus A-B approval chain.
- Loyalty redeem: member locked, points ≤ balance, value ≤ folio balance, folio must belong to member; POS tender same.
- Loyalty negative adjust: locked, cannot go below zero.
- Card replace: refuses already-replaced; new number generated (Luhn) — no reuse.
- Reservations: amount_override not accepted from staff validate(); cancel fee waiver needs reservations.waive_fees; modify/cancel/no-show lock the row; inventory rows locked; room clash check.
- Public booking lookup: returns only non-sensitive booking fields + guest first/last name; manage token required for everything else.
- Verify JSON: type/number/hotel/amount/status only, throttled 30/min/ip.
- WhatsApp webhook: X-Hub-Signature-256 verified before any processing; GET verify token hash_equals.
- Demo request: throttle 5/10min + honeypot, no queue dispatch.
- Edge sync: payload max 1000 changes, pull limit ≤1000, signed node tokens, throttle:api.
- Audit log endpoint paginated and limited to caller's group; User activity log excludes password/MFA.
- All public routes carry a throttle; no unthrottled public endpoint found.
- Scheduled jobs: withoutOverlapping/onOneServer; per-hotel helper rather than all-hotels-in-one-transaction (not deep-read per command).
- Uploads: validated size+mime; nginx/php limits 25M/20M.
- No preg_* with user-controlled pattern found (ReDoS n/a).

## Hardening notes (no concrete principal/resource beyond what is in candidates)

- FolioService::refund looks up the payment method with firstOrFail() without `active=true` (payment() requires active) — refunds can go out on a retired method.
- FolioController::charge/transfer have no upper bound on amount (bigint); consider a per-hotel max line amount to limit fat-finger/abuse.
- PosService::discount allows 100% discount with pos.discount; consider a cap or comp path.
- PayoutService::confirmManual with empty item_ids confirms every bank/cash item in one call; require explicit ids.
- Payout/HR: payroll markPaid accepts any `account` string → GL account chosen by caller (Ledger validates existence/postable only).
- AccountingController::postManual: `journal` is a free string (max:10); recommend whitelist from Journal table.
- Receivables pay and PayLinkService invoice branch: ReceivablesController::pay does not lock the invoice (PayLinkService::apply does) — minor over-payment race.
- InboundMatcher public relay: in non-production the HMAC secret is APP_KEY; if APP_KEY ever leaks, forged inbound receipts auto-post to pay links. Tie to a per-hotel secret always.
- DryRun fallback keyed on `app()->isProduction()` (APP_ENV === 'production' exact string) — fragile; see candidate.
- Onboarding import upload (OnboardingController:78) has size but no mimes; Importer parses untrusted spreadsheet/CSV — review parser limits (rows) separately.
- Guest model activity log is logAll → every guest edit stores id_number etc. in activity_log; anonymise() covers it, but retention of this log for non-erased guests is unbounded.
- Employee bank/MoMo/CNPS/tax exposed to hr.view (candidate, low) — also paymentFile endpoint (payroll.manage) returns all destinations in clear (expected for the role).
- LogDriver (Notifications/Application/Drivers/LogDriver.php:23) writes full message bodies (OTP codes, guest names) to the app log when no real channel is configured — ensure never used in production.
- PosController::sync processes up to 200 checks × unlimited items per request with a NumberSequence lock per check — heavy but authenticated; consider items max.
- FolioController::index guest_id/company_id/reservation_id filters are not validated as integers (Postgres cast error → 500 on junk input); harmless but noisy.
- No per-user limit on creating pay links / payment intents (staff) — each intent calls the gateway initiate; 1200/min api limit is the only cap.
