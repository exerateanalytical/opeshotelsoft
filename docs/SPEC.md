# OpesHotelSoft — Product & Technical Specification

**Version:** 0.2 (draft) · **Date:** 2026-11-02 · **Status:** for review

---

## 1. Purpose

OpesHotelSoft is a hotel management platform (hotel ERP) for the CEMAC region (Cameroon, Gabon, Republic of Congo, Chad, Central African Republic, Equatorial Guinea). It runs a single hotel or a whole hotel group from one system: reservations, front desk, guests, loyalty, housekeeping, maintenance, POS, stock, accounting, HR and payroll, with a web app for staff, a guest portal, and a mobile app.

Target clients: Krystal Palace, Akwa Palace, Guims group, Hilton subsidiaries in CEMAC, and similar independent hotels and groups.

### 1.1 Goals
- One system for the whole hotel. No separate accounting, payroll or stock tools needed.
- Multi-hotel and multi-group from day one.
- Built for CEMAC: XAF, French + English, SYSCOHADA accounting, CNPS payroll, Mobile Money, unreliable connectivity.
- Production grade: secure, audited, backed up, fast at the front desk.

### 1.2 Out of scope (v1)
- Being a public booking marketplace (we connect to OTAs; we are not one).
- Processing or holding money (we integrate with payment providers).
- Building hardware (door locks, printers, card readers are integrations).
- Non-hotel business lines of a conglomerate (general ERP).
- Recipe costing and kitchen production management (candidate for v2).

---

## 2. Users & roles

| Level | Example roles |
|---|---|
| Platform | Super admin (Opes staff), support |
| Group (tenant) | Group owner, group finance director, group HR, group revenue manager, auditor |
| Hotel | General manager, front office manager, receptionist, night auditor, reservations agent, housekeeping manager, room attendant, maintenance tech, F&B/POS cashier, storekeeper, accountant, HR officer |
| Guest | Guest / loyalty member (portal + mobile app) |

- Permissions via `spatie/laravel-permission`. Every role assignment carries a **scope**: whole group, or a list of hotels.
- A user sees only data for hotels in their scope. Enforced server-side (global scopes / policies), never only in the UI.

---

## 3. Tenancy model (hotel network)

```
Platform
 └── Group (tenant)          e.g. Guims group
      ├── Hotel / Property   e.g. Hotel A (Douala), Hotel B (Yaoundé)
      │    ├── Rooms, rates, outlets, stores, staff, books
      └── Shared: guest profiles, loyalty programme, group reports, group chart of accounts template
```

- **Single database, row-level tenancy.** Every business table has `group_id`; hotel-level tables also have `hotel_id`. A global scope applies the current group + allowed hotels on every query.
- Groups are fully isolated from each other. Hotels inside a group share guests and loyalty.
- Each hotel is its own **legal/accounting entity** (own books, tax ID, invoice numbering); the group gets consolidated reports.
- Cross-property: staff can search availability and book a sister hotel; guests see all hotels of a group in the portal.
- Per-hotel settings: currency (default XAF), timezone, tax rates, check-in/out times, country (drives tax + payroll rules), language.

---

## 4. Modules

Each module lives in `app/Modules/<Name>` (models, actions, controllers, policies, events) and talks to others through events and service interfaces, not direct table access.

### 4.1 Core
- Groups, hotels, users, roles/scopes, settings, countries, currencies and exchange rates.
- Audit log of every create/update/delete (`spatie/laravel-activitylog`).
- Notifications (email, SMS, WhatsApp), file storage (`spatie/laravel-medialibrary`).
- Numbering sequences per hotel (invoices, receipts, POs) — gapless where law requires.

### 4.2 Property
- Room types (capacity, beds, amenities, photos), rooms (number, floor, wing, status).
- Outlets (restaurant, bar, spa, laundry, minibar), meeting/event spaces.
- Room status: vacant clean / vacant dirty / occupied / out of order / out of service.

### 4.3 Reservations & rates
- Rate plans (BAR, corporate, package, non-refundable), seasons, min stay, closed to arrival.
- Availability and inventory per room type per night; overbooking limits.
- Individual and **group bookings** (room blocks, rooming lists, master billing).
- Corporate accounts and travel agents with negotiated rates and commission.
- Deposits, guarantees, cancellation / no-show policies.
- Booking channels: front desk, phone, guest portal/app, OTAs via channel manager (integration, see §6).
- Waitlist, cross-property booking.

### 4.4 Front desk
- Arrivals, in-house, departures lists; check-in (ID scan/capture, registration card, signature), room assignment, room move, early/late check-out.
- **Guest folio**: room charges, POS charges, extras, payments, splits, transfers between folios, routing rules (e.g. room to company, extras to guest).
- **Night audit**: post room charges, roll the business date, no-show processing, daily reports. Posts to accounting.
- Cashier shifts and cash-up per user.
- Police / immigration guest register report (required in CEMAC countries).

### 4.5 Guests & CRM
- Single guest profile per group: identity documents, nationality, preferences, history across all hotels, blacklist, VIP flags.
- Duplicate detection and merge.
- Companies and contacts.
- Data-protection: consent flags, export and deletion on request.

### 4.6 Loyalty
- Group-wide programme: tiers (e.g. Silver/Gold/Platinum), points earn rules (per XAF spent, per night, per outlet), burn rules (free nights, upgrades, F&B).
- **Member-first** benefits: priority booking, member rates, late check-out, upgrades by tier.
- Physical and digital **loyalty cards** (QR/barcode in app, printable card number).
- Points ledger with expiry; tier qualification and review.
- Points liability posted to accounting.
- Partner earning (v2).

### 4.7 Housekeeping
- Room status board, task assignment to attendants, checklists, inspections.
- Mobile-friendly attendant view; status updates sync to front desk in real time.
- Linen and amenities consumption → stock issues.
- Lost & found.

### 4.8 Maintenance
- Work orders (from staff or guest complaints), priority, assignment, parts used (→ stock), time spent.
- Preventive maintenance schedules per asset; asset register.
- Room out-of-order linked to work orders.

### 4.9 POS (F&B and outlets)
- Menus and items per outlet, modifiers, price levels, happy hour.
- Tables, open checks, split bills, transfers, voids/comps with reason + approval.
- Settle by cash, card, Mobile Money, **charge to room**, or to company account.
- Kitchen/bar order printing (KOT).
- Each sale deducts stock via item → stock recipe mapping (simple BOM, not full recipe costing).
- Offline mode for outlets (queue and sync).

### 4.10 Stock / inventory
- Item master (units, conversions, categories), suppliers, supplier price lists.
- Multiple **stores per hotel**: main store, kitchen, bar, housekeeping, maintenance, minibar.
- Purchase requisition → purchase order (approval workflow) → goods received note → supplier invoice match.
- Transfers between stores, issues to departments, returns, wastage/breakage.
- Stock counts (full and cycle), variance reports.
- Valuation: weighted average cost (FIFO as option).
- Reorder levels and low-stock alerts.
- All movements post to accounting (inventory, cost of sales, variances).

### 4.11 Accounting (SYSCOHADA)
- Chart of accounts on **SYSCOHADA révisé** (group template, per-hotel copy).
- Journals: sales, purchases, cash, bank, Mobile Money, payroll, inventory, miscellaneous.
- **Automatic posting** from operations: night audit (revenue by department), payments, POS, stock, payroll, loyalty — via configurable posting rules. Manual journal entries with approval.
- Accounts receivable (city ledger: companies, agents, guests) with ageing and statements.
- Accounts payable (suppliers) with ageing and payment runs.
- Bank and Mobile Money reconciliation.
- VAT (TVA) and other taxes per country; tax declarations reports.
- Fixed assets and depreciation.
- Periods: month close, year-end close, locking.
- Reports: trial balance, general ledger, balance sheet, income statement, cash flow, SYSCOHADA financial statements (DSF-ready exports), departmental P&L (USALI-style), **group consolidation**.
- Multi-currency with XAF as functional currency; exchange gains/losses.

### 4.12 HR & payroll
- Employees: personal data, documents, contracts (CDI/CDD/interns), job, department, hotel, salary grade.
- Org chart, recruitment (v2), onboarding checklist.
- Shifts and rosters, attendance (clock-in via web/mobile/biometric integration), overtime.
- Leave types, balances, requests and approvals; public holidays per country.
- **Payroll** with country rule packs (Cameroon first, then Gabon, Congo, others):
  - Gross: base, allowances (housing, transport), overtime, bonuses, service charge distribution.
  - Deductions: **CNPS** (or country equivalent) employee/employer, **IRPP**, CAC, CFC, RAV, TDL and other local levies (rates as configurable data, not code).
  - Loans and salary advances.
  - Payslips (FR/EN), bank/Mobile Money payment files, statutory declarations.
  - Payroll posts to accounting.
- Employee self-service in mobile app: payslips, leave requests, schedule.

### 4.13 Reports & dashboards
- Hotel dashboard: occupancy, ADR, RevPAR, arrivals/departures, revenue by department, outstanding balances.
- Group dashboard: same KPIs across hotels, comparisons.
- Standard reports per module; export to PDF / Excel / CSV; scheduled email reports.

### 4.14 Guest portal & mobile app
- **Guest**: search and book across group hotels, manage booking, pay deposit (Mobile Money/card), online check-in, digital key (via lock integration, v2), loyalty card and points, F&B/room-service orders, requests and complaints, invoices.
- **Staff** (same app, staff login): housekeeping tasks, maintenance work orders, manager dashboard, HR self-service.
- Offline-first for staff features.

---

## 5. Non-functional requirements

| Area | Requirement |
|---|---|
| Languages | French and English everywhere (UI, documents, emails, payslips). Default FR. |
| Currency | XAF default, multi-currency supported; amounts stored as integers in minor units. |
| Performance | Front desk actions < 1 s; dashboards < 3 s for a 500-room hotel. |
| Availability | 99.5% target; night audit must complete even with partial outages. |
| Connectivity | Web works on slow links (compressed assets, minimal payloads); POS and mobile staff features work offline and sync. |
| Security | HTTPS only, hashed passwords, 2FA for finance/admin roles, role + hotel scoping enforced server-side, rate limiting, OWASP Top 10 review before launch. |
| Audit | Every financial and guest-data change logged with user, time, before/after. Financial records never hard-deleted — reversed. |
| Data | Daily automated backups, off-site copy, tested restores; point-in-time recovery for PostgreSQL. |
| Compliance | SYSCOHADA, national tax rules, CNPS, guest register (police), data-protection law per country. |
| Accessibility | WCAG 2.1 AA for web. |

---

## 6. Integrations

| Integration | Purpose | Approach |
|---|---|---|
| Mobile Money (MTN MoMo, Orange Money, Airtel) | Payments, refunds, payroll payouts | Direct APIs or aggregator (e.g. CinetPay, Campay) |
| Card payments | Cards at desk and online | Gateway (e.g. Stripe where available, local acquirers) |
| Channel manager / OTAs | Sell on Booking.com, Expedia, etc. | Integrate an existing channel manager (SiteMinder or similar) first; native OTA links later |
| Door locks | Key cards, digital keys | Vendor SDKs (ASSA ABLOY/VingCard, Salto, Dormakaba) — v2 |
| SMS / WhatsApp / email | Confirmations, OTP, alerts | Provider APIs |
| Fiscal / e-invoicing | National tax authority requirements | Per country as they apply |
| Biometric clocks | Attendance | Import / device API |

---

## 7. Technical architecture

Benchmark: the platform must stand alongside **Oracle OPERA Cloud, Mews, Apaleo and Cloudbeds**. The reference pattern is Apaleo/Mews: an **API-first platform** where every first-party app (web, mobile, POS, kiosk) is just another API client, and partners get the same API as a service.

### 7.1 Architectural principles
1. **API-first.** The API is the product. Each endpoint is designed in **OpenAPI 3.1 before code**. No feature exists only in a UI; anything a screen does, the API does.
2. **Domain-driven design.** Each module is a **bounded context** with its own ubiquitous language, aggregates, invariants and data. Contexts never read each other's tables; they talk through published APIs (in-process interfaces) and events.
3. **Event-driven.** State changes emit **domain events** (internal) and **integration events** (published to webhooks/partners). Cross-context side effects (posting to accounting, stock deduction, loyalty points, notifications) happen through events, not direct calls.
4. **Modular monolith first, services-ready.** One deployable Laravel application for v1 (operational simplicity in CEMAC), but boundaries are strict enough that any context (e.g. Booking Engine, Channel, Payroll) can be extracted into its own service later without rewriting.
5. **Secure by default, multi-tenant by construction.** Tenant (group) and hotel scope are part of every request context, every query and every event.
6. **Everything auditable and replayable.** Financial contexts are append-only (reversals, never edits); all events are stored.

### 7.2 Bounded contexts

| Context | Core aggregates | Publishes (examples) |
|---|---|---|
| Identity & Access | User, Role, Scope, OAuthClient, ApiKey | `UserInvited`, `RoleGranted` |
| Tenancy & Property | Group, Hotel, RoomType, Room, Outlet, Asset | `HotelCreated`, `RoomStatusChanged` |
| Distribution & Rates | RatePlan, Rate, Restriction, Availability | `RatesUpdated`, `AvailabilityChanged` |
| Reservations | Reservation, Block, Company, Agent | `ReservationCreated/Modified/Cancelled`, `NoShowMarked` |
| Front Office | Stay, BusinessDay, GuestRegisterEntry | `GuestCheckedIn/Out`, `RoomMoved`, `BusinessDayClosed` |
| Folio & Payments | Folio, Charge, Payment, Refund, CashierShift | `ChargePosted`, `PaymentCaptured`, `FolioClosed` |
| Guest Profile (CRM) | Guest, Company contact, Consent | `GuestProfileMerged`, `ConsentChanged` |
| Loyalty | Programme, Membership, Card, PointsLedger | `PointsEarned/Redeemed`, `TierChanged` |
| Housekeeping | HkTask, Inspection, LostItem | `RoomCleaned`, `RoomInspected` |
| Maintenance | WorkOrder, PmSchedule | `WorkOrderOpened/Closed`, `RoomOutOfOrder` |
| POS | Outlet menu, Check, PosPayment | `CheckSettled`, `CheckChargedToRoom` |
| Procurement & Stock | Item, Store, PurchaseOrder, GoodsReceipt, StockMovement, StockCount | `GoodsReceived`, `StockIssued`, `StockCounted` |
| Accounting (General Ledger) | Account, Journal, JournalEntry, FiscalPeriod | `EntryPosted`, `PeriodClosed` |
| Receivables & Payables | ArInvoice, ApInvoice, Settlement | `InvoiceIssued`, `InvoicePaid` |
| HR | Employee, Contract, Shift, Attendance, Leave | `EmployeeHired`, `LeaveApproved` |
| Payroll | PayrollRun, Payslip, RulePack | `PayrollApproved`, `PayslipIssued` |
| Notifications | Template, Message | `MessageSent` |
| Reporting & Analytics | read models only | — |

A **context map** (upstream/downstream, anti-corruption layers for OTAs, payment providers, locks) is maintained in `docs/architecture/context-map.md`.

### 7.3 API platform (API as a service)
- **Style:** REST + JSON, resource-oriented, `/api/v1/...`; JSON:API-style pagination, filtering, sparse fields. **OpenAPI 3.1** specs per context in `docs/api/<context>.yaml`, merged into one published spec.
- **Contract-first workflow:** spec → review → mock server → contract tests → implementation. CI fails if code and spec diverge.
- **Versioning:** URL major version; additive changes only within a version; deprecation headers and 12-month sunset policy.
- **Reliability:** `Idempotency-Key` header on all POSTs that create money or bookings; optimistic concurrency via `ETag`/`If-Match`; standard error format (RFC 9457 Problem Details).
- **Public surfaces:**
  - **Staff/Operations API** — everything the PMS does.
  - **Booking API** — availability, rates, booking for websites, metasearch, travel agents.
  - **Partner API** — for integrators (revenue management, CRM, BI, locks, channel managers).
  - **Webhooks** — subscribe to integration events; signed (HMAC), retried with backoff, delivery log, replay.
- **Developer portal:** API reference generated from OpenAPI, sandbox tenant with seed data, API keys/OAuth app self-registration, changelog, SDKs generated from the spec (TypeScript, Dart, PHP).
- **Governance:** per-client rate limits and quotas, usage metering (for API-as-a-service billing), API keys scoped to group/hotel and permissions.

### 7.4 Authentication & authorization
- **OAuth 2.1 / OpenID Connect** authorization server (Laravel Passport or a dedicated IdP such as Keycloak):
  - Authorization Code + **PKCE** for web SPA and mobile.
  - **Client Credentials** for server-to-server partners.
  - Short-lived access tokens (JWT), rotating refresh tokens, token revocation.
- **MFA** (TOTP; SMS fallback) mandatory for finance, payroll and admin roles.
- **SSO** for groups that need it (SAML/OIDC with Azure AD / Google Workspace) — Hilton-type clients.
- **Authorization:** RBAC (roles/permissions) + **scope** (group / hotel list) + **ABAC** rules for sensitive actions (e.g. void over limit needs manager, payroll visible only to HR). Enforced in policies at the API layer.
- **Guest auth:** email/phone + OTP, social login optional.
- **Sessions & devices:** device list, remote sign-out; POS PIN login on registered devices.

### 7.5 Event architecture
- **Transactional outbox:** events written in the same DB transaction as the state change, then dispatched — no lost or phantom events.
- **Bus:** Redis Streams / queue (v1); swappable for Kafka/RabbitMQ when contexts are extracted.
- **Event envelope:** `id`, `type`, `version`, `occurred_at`, `group_id`, `hotel_id`, `actor`, `correlation_id`, `causation_id`, `payload`. Event schemas documented with **AsyncAPI** in `docs/api/events.yaml`.
- **Consumers are idempotent** (dedupe by event id); dead-letter queue with alerting and replay.
- **Sagas / process managers** for multi-step workflows (e.g. booking with deposit: hold inventory → request payment → confirm or release).
- **CQRS read models** for dashboards and reports, rebuilt from events; heavy reporting off the write path.

### 7.5a Offline-first (hybrid cloud + hotel edge)
CEMAC internet is unreliable; a hotel must never stop operating because the line is down.
- **Deployment modes:** (a) cloud only; (b) **cloud + hotel edge node** (recommended for every production hotel): a small server on the hotel LAN running the same application, PostgreSQL and Redis for that hotel.
- **Normal operation:** staff devices on the hotel network talk to the edge node (fast, works offline); the edge node syncs with the cloud continuously through the event log (outbox → cloud inbox, and back).
- **Outage:** front desk, folios, POS, housekeeping, maintenance, stock issues continue on the edge node. Guest portal/OTA bookings continue in the cloud. On reconnect, events replay both ways in order, idempotently.
- **Conflict rules:** cloud is master for inventory/availability; during an outage the edge may sell only an **allotment** of rooms reserved for it, preventing double-booking. Financial documents use per-node number ranges so legal numbering stays gapless and unique. Master data (rates, items, chart of accounts) is edited in the cloud only; last-writer-wins is never used for money.
- **Clients:** web SPA is a PWA (service worker, cached shell, IndexedDB queue) as a third layer for short drop-outs even without an edge node; Flutter app offline-first with SQLite.
- **Ops:** edge nodes auto-update from the release pipeline, are monitored remotely, and back up to the cloud.

### 7.6 Technology stack

| Layer | Choice |
|---|---|
| Backend / API | Laravel 13, PHP 8.3+ |
| Auth server | Laravel Passport (OAuth 2.1/OIDC) — Keycloak if enterprise SSO demand grows |
| Web staff app | React 19 + TypeScript SPA (Vite), Tailwind v4, Radix UI, TanStack Query/Table — **consumes the public API only** |
| Guest web / booking engine | React SPA or SSR site consuming the Booking API |
| Mobile | Flutter (guest + staff), offline-first (SQLite + sync queue) |
| Database | PostgreSQL 16 (row-level security as a second tenant guard) |
| Cache / queue / events | Redis (Horizon), outbox → Redis Streams |
| Files | S3-compatible object storage |
| Search | PostgreSQL full-text → Meilisearch when needed |
| API tooling | OpenAPI 3.1, AsyncAPI 3, Spectral (spec linting), Prism (mocks), Schemathesis/Dredd (contract tests) |
| Observability | OpenTelemetry traces, structured logs, Prometheus/Grafana, Sentry |

> **Decision recorded (replaces earlier one):** to be truly API-first, the staff web app becomes a **React SPA that uses the public API**, instead of Inertia pages that read data server-side. The existing React components and Navy + Gold design system are kept; only the data layer changes. Inertia will be removed in Phase 0.

### 7.7 Code layout (per bounded context)
```
app/Modules/<Context>/
  Domain/          Aggregates, Entities, ValueObjects, Events, Repositories (interfaces), Policies
  Application/     Commands, Queries, Handlers, DTOs, Sagas
  Infrastructure/  Eloquent repositories, outbox, external adapters (ACL)
  Http/            API controllers, requests, resources (match OpenAPI)
  Database/        Migrations, seeders, factories
  Tests/           Unit (domain), Feature (API), Contract
docs/api/<context>.yaml        OpenAPI
docs/modules/<context>/        PLAN, workflows, CERTIFICATION
resources/js/                  SPA (apps/staff), generated API client
```
Architecture rules are enforced with automated tests (e.g. Pest arch tests / deptrac): no context imports another context's `Domain` or `Infrastructure`.

### 7.8 Key domain rules
- Money as integer minor units + ISO currency; never floats.
- Hotel **business date** separate from calendar date; only the night audit closes it.
- Every financial effect produces a **balanced journal entry** through the GL posting service; postings are immutable, corrections are reversals.
- Gapless, per-hotel legal numbering for invoices and receipts.
- All times stored UTC, displayed in hotel timezone.

---

## 8. Core data model (summary)

- **Tenancy:** groups, hotels, users, role_user_scopes, settings, countries, currencies, exchange_rates
- **Property:** room_types, rooms, room_status_logs, outlets, spaces, assets
- **Reservations:** rate_plans, rates (per type/date), reservations, reservation_rooms (per night), room_blocks, companies, agents, cancellations
- **Front desk:** stays, folios, folio_lines, payments, cashier_shifts, business_days, guest_register_entries
- **Guests/loyalty:** guests, guest_documents, guest_preferences, loyalty_programmes, tiers, memberships, cards, points_transactions, rewards, redemptions
- **Housekeeping/maintenance:** hk_tasks, inspections, lost_found, work_orders, pm_schedules
- **POS:** menus, menu_items, item_stock_links, pos_checks, pos_check_lines, pos_payments
- **Stock:** stock_items, units, stores, suppliers, purchase_orders(+lines), goods_receipts(+lines), stock_movements, stock_counts, stock_balances
- **Accounting:** accounts, journals, journal_entries, journal_lines, posting_rules, fiscal_periods, ar_invoices, ap_invoices, bank_accounts, reconciliations, tax_codes, fixed_assets
- **HR/payroll:** employees, contracts, departments, positions, shifts, attendances, leave_types, leave_requests, payroll_rule_packs, payroll_runs, payslips(+lines), loans

Every table: `id`, `group_id` (and `hotel_id` where hotel-level), timestamps, soft deletes only for non-financial master data.

---

## 9. Delivery phases

| Phase | Scope | Outcome |
|---|---|---|
| **0. Foundation** | Architecture skeleton (bounded-context layout, arch tests), OAuth 2.1/OIDC auth + MFA, tenancy (groups/hotels) + scoped roles, OpenAPI/AsyncAPI tooling and contract-test pipeline, transactional outbox + event bus, webhooks, audit, i18n FR/EN, CI/CD, observability; convert web app to API-consuming SPA | Secure, API-first, event-driven multi-hotel platform core |
| **1. Property & reservations** | Room types, rooms, rate plans, availability, bookings, group bookings, companies | A hotel can take bookings |
| **2. Front desk & billing** | Check-in/out, folios, payments (cash, card, Mobile Money), night audit, guest register, invoices | A hotel can operate daily |
| **3. Accounting** | SYSCOHADA chart, journals, auto-posting from phase 2, AR/AP, bank, VAT, financial statements | Books produced from operations |
| **4. POS & stock** | Outlets, menus, checks, charge-to-room; stores, purchasing, receiving, transfers, counts, valuation | F&B and stores run in the system |
| **5. Housekeeping & maintenance** | Status board, tasks, inspections, work orders, assets | Rooms and assets managed |
| **6. HR & payroll** | Employees, contracts, shifts, attendance, leave, Cameroon payroll pack, payslips, declarations | Staff paid from the system |
| **7. Guests, loyalty & group** | CRM, loyalty programme and cards, group dashboards, consolidation | Network-wide loyalty and reporting |
| **8. Guest portal & mobile app** | Booking engine, Flutter app (guest + staff), online check-in | Guests and staff on mobile |
| **9. Integrations & certification** | Channel manager, more payment providers, other country payroll packs, security audit, load test, pilot | Production launch |

Every module inside every phase goes through the seven gates in §10 (Plan → Verify → Certify → Build → Verify → Certify → Deploy). A phase is complete only when all its modules are release-certified. The first pilot hotel goes live after phase 3 (operations + books), with later phases rolled in.

---

## 10. Delivery lifecycle: Plan → Verify → Certify → Build → Verify → Certify → Deploy

**Every module and every workflow** goes through the same seven gates. Nothing moves to the next gate until the current one is passed and signed off. Gate records live in `docs/modules/<context>/`.

### 10.1 The gates

| # | Gate | What happens | Output (artifact) | Pass criteria |
|---|---|---|---|---|
| 1 | **Plan** | Domain modelling with hotel experts (event storming); define ubiquitous language, aggregates, invariants, workflows, API and events | `PLAN.md`, `workflows.md`, `docs/api/<context>.yaml`, event schemas, data model, UI wireframes | Every workflow in the catalogue (§10.3) covered; every endpoint and event specified |
| 2 | **Verify (design)** | Review the plan against real hotel operations and standards; lint specs; run API mocks with UI prototypes | Review notes, Spectral lint report, mock-server walkthrough | Zero spec lint errors; domain expert confirms workflows match how Akwa Palace / Hilton-class hotels actually work; open issues closed |
| 3 | **Certify (design)** | Formal design sign-off | `CERTIFICATION.md` § Design, signed | Signed by product owner, lead architect, domain expert (and accountant / HR-payroll expert for finance contexts) |
| 4 | **Build** | Implement contract-first: domain → application → API → UI; tests written alongside (TDD for domain rules) | Code, migrations, tests, generated SDK, UI screens | Code matches the certified spec; no undocumented endpoints |
| 5 | **Verify (build)** | Automated + manual testing | CI report, test coverage, contract test report, QA report, performance & security scan results | All checks in §10.2 green |
| 6 | **Certify (release)** | UAT on staging with real hotel staff using realistic data; compliance sign-off | `CERTIFICATION.md` § Release, signed; UAT scripts with results | Every workflow executed end-to-end by hotel users with no blocking defects; finance outputs signed by accountant; payroll signed per country |
| 7 | **Deploy** | Release to production via pipeline; pilot hotel first, then rollout | Release notes, deployment record, rollback plan, runbook | Smoke tests pass in production; monitoring and alerts live; hypercare period (2 weeks) completed |

### 10.2 Verification checklist (gate 5) — applied to every module

**Functional**
- Unit tests for every domain invariant (aggregate rules) — target ≥ 90% domain coverage.
- API feature tests for every endpoint, including permission-denied and validation cases.
- **Contract tests**: implementation vs OpenAPI (Schemathesis/Dredd) and events vs AsyncAPI.
- End-to-end tests (Playwright for web, integration tests for Flutter) for every workflow in the catalogue.

**Multi-tenancy & security**
- Isolation tests: a user/API key from group X / hotel A can never read or change group Y / hotel B (every endpoint, automated).
- OWASP ASVS Level 2 checks; dependency vulnerability scan; secrets scan; SAST.
- Authorization matrix test: every role × every endpoint matches the permission design.

**Financial integrity** (any context that touches money)
- Every posting balances (debits = credits), property-based tests.
- Night audit, folio and GL totals reconcile to the unit.
- Reversal-only corrections; no update/delete paths on financial records.

**Reliability**
- Idempotency: replaying the same request or event never double-books or double-charges.
- Concurrency: simultaneous bookings for the last room → exactly one succeeds.
- Offline/sync scenarios for POS and mobile (conflict resolution tested).

**Performance**
- Load test at target size (e.g. 1,000 rooms per hotel, 20 hotels per group, peak check-out hour); p95 API latency < 300 ms for operational endpoints.

**Quality**
- PHPStan level max (or 8+), Pint, ESLint, TypeScript strict, architecture rules (no cross-context imports).
- Accessibility (WCAG 2.1 AA) and FR/EN translation completeness.

### 10.3 Workflow catalogue (minimum; each becomes a certified test script)

| Context | Workflows |
|---|---|
| Tenancy & Property | Onboard group; add hotel; configure room types/rooms; set taxes and country rules; invite staff with scoped roles |
| Rates & Distribution | Create rate plan; seasonal pricing; restrictions (min stay, CTA/CTD); push to channel manager; receive OTA booking |
| Reservations | Individual booking; modify; cancel with penalty; no-show; group block → rooming list → pick-up; corporate booking; cross-property booking; waitlist; deposit request and capture |
| Front Office | Check-in (walk-in and reserved, ID capture, police register); room assignment/upgrade; room move; early/late check-out; express check-out; night audit and day close |
| Folio & Payments | Post charges; split/route charges; transfer between folios; take cash/card/Mobile Money; refund; city-ledger transfer; cashier shift open/close; invoice issue |
| Guests & Loyalty | Create/merge profile; enrol member; issue card; earn points (room, POS); redeem; tier upgrade/downgrade; points expiry; member-only rate booking |
| Housekeeping | Generate daily tasks; assign; clean; inspect; mark out-of-order; lost & found |
| Maintenance | Raise work order; assign; consume parts; close; preventive schedule run |
| POS | Open check; order to kitchen; split bill; void/comp with approval; settle cash/card/MoMo; charge to room; outlet day close; offline sale and sync |
| Procurement & Stock | Requisition → PO approval → goods receipt → supplier invoice match; transfer between stores; issue to department; POS auto-deduction; wastage; stock count and variance; reorder alert |
| Accounting | Auto-post night audit revenue; post payments; manual journal with approval; bank & Mobile Money reconciliation; VAT return; month close; year-end close; SYSCOHADA statements; group consolidation |
| AR / AP | Issue company invoice; statement and ageing; receive payment; supplier invoice; payment run |
| HR | Hire employee; contract renewal; roster; clock-in/out; leave request/approval; termination and final settlement |
| Payroll | Monthly payroll run (Cameroon pack): compute, review, approve, payslips, bank/MoMo file, CNPS & tax declarations, GL posting; salary advance and loan recovery |
| Guest portal & app | Search and book; pay deposit; online check-in; view folio; room-service order; loyalty card |
| API platform | Register partner app; OAuth client-credentials flow; webhook subscribe → receive → retry → replay; rate-limit enforcement |

### 10.4 Platform-level certification (before first production hotel)
- External **penetration test** and remediation.
- **PCI DSS** scope minimised (card data only via tokenising gateway; SAQ A/A-EP).
- Data-protection review per country; DPA templates for hotel clients.
- **Disaster recovery drill**: restore from backup within RPO ≤ 15 min, RTO ≤ 4 h.
- Accountant certification of SYSCOHADA outputs; payroll certification per launch country.
- Operational readiness: runbooks, on-call, SLA, support desk, training material (FR/EN).

### 10.5 Environments & deployment
- **Environments:** local (Laragon) → CI → staging (production-like, anonymised data) → sandbox (partner developers) → production.
- **Pipeline:** build → static analysis → tests → contract tests → security scans → deploy to staging → automated E2E → manual approval → production (zero-downtime, database migrations backward-compatible).
- **Infrastructure:** containers (Docker), Nginx + PHP-FPM, PostgreSQL with streaming replica and PITR, Redis, Horizon workers, object storage, CDN. Hosted in-region or nearest compliant cloud region.
- **Operations:** OpenTelemetry tracing, dashboards, alerting, Sentry, uptime checks, audit log retention, feature flags for gradual rollout per hotel.

---

## 11. Open questions

1. Which hotel is the first pilot, and how many rooms/outlets?
2. Which countries are needed at launch (payroll and tax packs)? Cameroon assumed first.
3. Preferred Mobile Money route: direct operator APIs or an aggregator?
4. Channel manager: integrate an existing one (which?) or build later?
5. Hosting: local data-centre requirement, or cloud acceptable?
6. Existing systems at client hotels (Opera, Sage, etc.) that need data migration?
7. Pricing / licensing model (per room, per hotel, per module)?
8. Fiscal e-invoicing requirements per country at launch date?

---

## 12. Current state (2026-11-02, after overnight build)

- **API platform built** for every bounded context in §7.2: 188 paths / 251 operations, OpenAPI 3.1 at `docs/api/openapi.json` (live at `/docs/api` in local).
- Platform: row-level tenancy (group → hotel), OAuth 2.1 (Passport) + first-party login, transactional outbox and event bus, signed webhooks with retries/replay, event feed, Idempotency-Key, RFC 9457 errors, audit log, gapless numbering, scheduler.
- Modules: Property, Rates & Reservations (ARI, restrictions, blocks), Front Office (check-in/out, police register, night audit, cashier), Folio & invoices, Guests/CRM, Loyalty, Housekeeping, Maintenance, POS (offline sync), Procurement & Stock, Accounting (SYSCOHADA, auto-posting, statements, VAT, consolidation), Receivables/Payables, HR, Payroll (Cameroon pack, uncertified), Channels (7 drivers, dry-run until credentials), public Booking API, Reports.
- **Tests:** full suite green on PostgreSQL (see `docs/modules/CERTIFICATION-REGISTER.md`).
- **Web UI:** dashboard consumes the live API; other screens are placeholders.
- **Not started:** Flutter app, edge node, PDFs/notifications, SSO.
- **No module is certified** — gates 2, 3 and 6 await human sign-off. Owner actions in `docs/BLOCKERS.md`.
