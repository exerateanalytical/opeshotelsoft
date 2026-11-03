# Certification register

Status of every module against the seven delivery gates (SPEC §10).
**No module is certified.** Gates 2, 3 and 6 need human sign-off from people who are not the builder (domain expert, accountant, payroll expert, product owner). This register is the record those people fill in.

Legend: ✅ done · 🟡 done, awaiting human review · ⬜ not started · — not applicable

| # | Bounded context | 1 Plan | 2 Verify (design) | 3 Certify (design) | 4 Build | 5 Verify (build) | 6 Certify (release) | 7 Deploy |
|---|---|---|---|---|---|---|---|---|
| 1 | Platform (tenancy, OAuth, outbox, webhooks, idempotency) | 🟡 SPEC §3, §7 | ⬜ | ⬜ | ✅ API | ✅ isolation + architecture tests | ⬜ | ⬜ |
| 2 | Property (room types, rooms, outlets, assets) | 🟡 SPEC §4.2 | ⬜ | ⬜ | ✅ API | ✅ via workflow tests | ⬜ | ⬜ |
| 3 | Rates & Reservations (ARI, restrictions, blocks) | 🟡 SPEC §4.3 | ⬜ | ⬜ | ✅ API | ✅ 8 workflow tests | ⬜ | ⬜ |
| 4 | Front Office (check-in/out, police register, night audit) | 🟡 SPEC §4.4 | ⬜ | ⬜ | ✅ API | ✅ 7 workflow tests | ⬜ | ⬜ |
| 5 | Folio & Payments, invoices, cashier | 🟡 SPEC §4.4 | ⬜ | ⬜ | ✅ API | ✅ (GL balance asserted) | ⬜ | ⬜ |
| 6 | Guests / CRM | 🟡 SPEC §4.5 | ⬜ | ⬜ | ✅ API | ✅ isolation tests | ⬜ | ⬜ |
| 7 | Loyalty (tiers, cards, earn/burn, liability) | 🟡 SPEC §4.6 | ⬜ | ⬜ | ✅ API | ✅ 2 tests | ⬜ | ⬜ |
| 8 | Housekeeping | 🟡 SPEC §4.7 | ⬜ | ⬜ | ✅ API | ✅ 1 test | ⬜ | ⬜ |
| 9 | Maintenance (work orders, OOO, preventive) | 🟡 SPEC §4.8 | ⬜ | ⬜ | ✅ API | ✅ 1 test | ⬜ | ⬜ |
| 10 | POS (checks, KOT, room charge, offline sync) | 🟡 SPEC §4.9 | ⬜ | ⬜ | ✅ API | ✅ 5 tests | ⬜ | ⬜ |
| 11 | Procurement & Stock (PO, GRN, 3-way match, counts, WAC) | 🟡 SPEC §4.10 | ⬜ | ⬜ | ✅ API | ✅ 4 tests | ⬜ | ⬜ |
| 12 | Accounting — SYSCOHADA GL, VAT, statements, consolidation | 🟡 SPEC §4.11 | ⬜ | ⬜ accountant | ✅ API | ✅ every flow asserts a balanced trial balance | ⬜ accountant | ⬜ |
| 13 | Receivables / Payables | 🟡 SPEC §4.11 | ⬜ | ⬜ | ✅ API | ✅ 2 tests | ⬜ | ⬜ |
| 14 | HR (employees, contracts, shifts, attendance, leave) | 🟡 SPEC §4.12 | ⬜ | ⬜ | ✅ API | ✅ 2 tests | ⬜ | ⬜ |
| 15 | Payroll — Cameroon rule pack | 🟡 SPEC §4.12 | ⬜ | ⬜ payroll expert | ✅ API | ✅ 4 worked examples + GL test | ⬜ payroll expert | ⬜ |
| 16 | Channels — Booking.com, Expedia, Tripadvisor, Channex, MyAllocator, Cloudbeds, SiteMinder | 🟡 SPEC §6 | ⬜ | ⬜ | ✅ API (dry-run) | ✅ 7 tests (formats, idempotency, signatures) | ⬜ partner certification per channel | ⬜ |
| 17 | Booking engine (public Booking API) | 🟡 SPEC §4.14 | ⬜ | ⬜ | ✅ API | ✅ 1 test | ⬜ | ⬜ |
| 18 | Reports & dashboards | 🟡 SPEC §4.13 | ⬜ | ⬜ | ✅ API + web dashboard | ✅ 1 test | ⬜ | ⬜ |
| 19 | Staff web UI (all screens) | 🟡 PLAN-PHASE-2 | ⬜ | ⬜ | ✅ every module screen, FR/EN shell | 🟡 RBAC matrix (19 roles × 35 pages) + browser smoke; all module pages FR/EN (6 language files, browser-checked), not every flow clicked | ⬜ | ⬜ |
| 20 | Mobile app (Flutter, guest + staff) | 🟡 SPEC §4.14 | ⬜ | ⬜ | ✅ `mobile/` | 🟡 `flutter analyze` clean, 14 tests; no APK (Android SDK missing) | ⬜ | ⬜ |
| 21 | Hotel edge node (offline-first) | 🟡 SPEC §7.5a | ⬜ | ⬜ | ⬜ next phase | ⬜ | ⬜ | ⬜ |
| 22 | Access control & admin (RBAC menus/guards, 2FA TOTP, audit log, currencies, i18n) | 🟡 SPEC §2, §5 | ⬜ | ⬜ | ✅ | ✅ RbacMatrixTest, ShellFeaturesTest, AdminTest | ⬜ security review | ⬜ |
| 23 | Guest booking website & portal | 🟡 SPEC §4.14 | ⬜ | ⬜ | ✅ | ✅ PublicSiteTest, BookingEngineTest | ⬜ | ⬜ |
| 24 | Payments (MTN MoMo, Orange Money, card) | 🟡 SPEC §6 | ⬜ | ⬜ | ✅ dry-run drivers | ✅ PaymentsTest (signed/duplicate callbacks, refunds, balanced GL) | ⬜ provider certification | ⬜ |
| 25 | Notifications (email, SMS, WhatsApp) | 🟡 SPEC §4.1, §6 | ⬜ | ⬜ | ✅ log driver until keys | ✅ NotificationsTest | ⬜ | ⬜ |
| 26 | Documents & exports (PDF, Excel/CSV, scheduled reports) | 🟡 SPEC §4.13 | ⬜ | ⬜ | ✅ | ✅ DocumentsTest | ⬜ layout sign-off (invoice, payslip) | ⬜ |

## Automated verification evidence (gate 5)

Run: `php artisan test` (PostgreSQL test database `opeshotelsoft_test`).

| Suite | What it proves |
|---|---|
| `Platform/TenancyIsolationTest` | Users of one group/hotel cannot read or select another; writes are stamped with the active tenant; permissions enforced |
| `Platform/ArchitectureTest` | Every tenant table model is tenant-scoped; no DELETE endpoints on financial records; Platform layer independent of feature modules; no module reaches into another module's HTTP layer |
| `Reservations/ReservationWorkflowTest` | Pricing per night, last-room concurrency (409), Idempotency-Key replay, cancellation penalties, restrictions, modify/re-price, group blocks |
| `FrontOffice/StayLifecycleTest` | Full stay posts balanced GL (VAT 19.25 % incl., tourist tax), checkout blocked on open balance, city-ledger direct bill, void permission + GL reversal, night audit idempotency, police-register ID, cashier variance |
| `Operations/PosAndStockTest` | PO → approval (segregation of duties) → GRN → transfer → POS sale → stock & cost of sales; charge to room; negative stock refused; count variance posted; supplier 3-way match & payment; offline POS sync idempotent |
| `Operations/HousekeepingMaintenanceLoyaltyTest` | Departure task → clean → inspect; OOO removes room from sale and restores it; loyalty earn on stay + liability; members-only rate |
| `HR/PayrollWorkflowTest`, `Unit/CameroonPayslipTest` | Payroll compute → approve (SoD) → pay with balanced GL; CNPS ceiling; IRPP progressive; RAV/TDL brackets; overtime; leave balance |
| `Channels/ChannelConnectivityTest` | ARI push on rate change with markup; live send with credentials (faked HTTP) and secret redaction; Channex & Booking.com OTA XML inbound create/cancel/duplicate; HMAC signature; Expedia EQC & SiteMinder SOAP payloads; Tripadvisor price pull |
| `GuestPortal/BookingEngineTest` | Anonymous search → book → lookup → cancel with signed token; dashboard KPIs; city-ledger invoice payment |
| `Platform/RbacMatrixTest` | Every demo role sees exactly its sidebar and gets 200/403 on all 35 staff pages |
| `Admin/AdminTest`, `Platform/ShellFeaturesTest` | 2FA enrolment, challenge and recovery codes; audit log scoped to group; currencies and rates; locale switch |
| `Payments/PaymentsTest` | Dry-run success/fail/expiry, signed and duplicate callbacks, bad signature rejected, refunds, deposit from booking site, books balance |
| `Notifications/NotificationsTest` | Event-driven sends in guest language, consent rules, retry/backoff, log driver without keys |
| `Documents/DocumentsTest` | Invoice, folio, registration-card, payslip and PO PDFs only for authorised users; xlsx/csv exports; scheduled report run |
| `FrontOffice/FrontDeskExtrasTest`, `ListFiltersTest` | Routing rules keep books balanced, late/early check-out fees, registration card capture, list filters |
| Phase-2 extras in Reservations, Guests, POS, Stock, Accounting, HR | Seasons, waitlist, tape chart; guest history limited to allowed hotels, anonymisation keeps financial rows; happy hour, comps balanced; requisitions, returns, payment runs balanced; reconciliation, depreciation, FX, year-end close; overtime worked example, holidays, org-chart cycles |

## Sign-off

| Module | Gate | Name | Role | Date | Signature |
|---|---|---|---|---|---|
| | | | | | |
