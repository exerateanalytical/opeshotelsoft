# Sidebar order by role

Source of truth: `app/Http/Navigation.php` (`ITEMS`, `SECTIONS`, `ROLE_PROFILES`). Exact per-role lists are pinned in `tests/Feature/Platform/RbacMatrixTest.php` (`SIDEBAR`, `PRIMARY`).

## Rules
1. **Visibility first, then order.** `Navigation::visible()` filters `ITEMS` by permission (the security boundary). `Navigation::order()` only reorders that list. It can never add an entry (this is tested: `test_order_never_changes_visibility`).
2. **Top group (no heading).** It holds the role's `primary` paths in order, then Dashboard and Tasks if the profile did not list them, then the employee-only self-service entries (My space, Clock in and out, My requests). Those last three only appear for logins linked to an employee record. Dashboard and Tasks are always in the top group. Specialist roles (floor, desk, finance, HR) put their workspace first. Management roles put the owner view first and Dashboard second.
3. **Sections below.** These follow the role's `sections` order. Sections the role did not list come after, in the default order: owner, front office, guests and service, rooms and maintenance, revenue and distribution, billing and payments, finance, stock and purchasing, people and HR, reports, settings. Setup always comes last. Inside a section, entries follow `ITEMS` order: the hub first, then sub-screens by need.
4. **Single-entry sections.** When two or more sections are left with one entry each, those entries merge into one "More" group at the end.
5. **Multi-role and custom roles.** The order of the `ROLE_PROFILES` keys is the priority: a user gets the profile of the first listed role they hold. The list runs broadest scope first: group-owner, general-manager, hotel-owner, finance-director, auditor, revenue-manager, front-office-manager, accountant, payroll-officer, hr-officer, night-auditor, receptionist, reservations-agent, fnb-manager, housekeeping-manager, storekeeper, pos-cashier, maintenance-technician, room-attendant. A user with no profiled role gets the `ITEMS` order.
6. **Phone tabs.** The phone shows the first four entries of the ordered menu (`pickTabs` in `navModel.ts`), so the tabs follow the same need order. Everything else is under More.
7. **Desktop sections fold.** Each section header can be clicked to fold it. The folded state is remembered per user in the browser (`nav.closed.<userId>`). The top group, the icon rail and the section holding the current page always stay open.

## Per role (top group, then section order; first four = phone tabs)
| Role | Top group (in order) | Section order | Why |
|---|---|---|---|
| group-owner | Group view, Owner view, Dashboard, Alerts, Tasks | owner, revenue, reports, finance, billing, front office, guests, rooms, stock, HR, settings | Portfolio first, then money, then operations |
| general-manager | Owner view, Dashboard, Alerts, Tasks, Front desk | owner, front office, revenue, guests, rooms, billing, finance, reports, HR, stock, settings | Runs the hotel day: KPIs, then the floor |
| hotel-owner | Owner view, Dashboard, Alerts, Reports, Tasks | owner, revenue, reports, finance, ... | Read-only owner: results and reports |
| finance-director | Finance, Dashboard, Owner view, Payables and tax, Tasks | finance, billing, reports, owner, stock, HR, settings | Ledger and approvals |
| auditor | Audit log, Reports, Finance, Dashboard, Tasks | finance, billing, reports, owner, ... | Evidence first |
| revenue-manager | Rate suggestions, Tape chart, Channels, Dashboard, Tasks | revenue, reports, front office, guests, settings | Pricing and availability |
| front-office-manager | Front desk, Arrivals, Reservations, In-house, Dashboard, Tasks | front office, guests, billing, rooms, revenue, reports, HR, settings | Desk supervision |
| accountant | Finance, Folios, Invoices, Cashier, Dashboard, Tasks | finance, billing, reports, stock, settings | Daily posting and receivables |
| payroll-officer | HR, Salary payouts, Workforce, Payables and tax, Dashboard, Tasks | HR, billing, finance | Pay cycle |
| hr-officer | HR, Workforce, Performance and tasks, Lifecycle, Dashboard, Tasks | HR, stock | Staff records and rosters |
| night-auditor | Front desk, Cashier, Folios, Reports, Dashboard, Tasks | billing, front office, reports, guests, revenue, settings | Night close |
| receptionist | Arrivals, Front desk (departures are on it), Reservations, In-house, Guests, Cashier, Dashboard, Tasks | front office, billing, guests, rooms, revenue, settings | Check-in and check-out loop |
| reservations-agent | Reservations, Tape chart, Groups, Guests, Dashboard, Tasks | front office, guests, revenue, billing, settings | Bookings |
| fnb-manager | POS, Stock, Stock control (recipes), Reports, Dashboard, Tasks | stock, billing, guests, reports, HR | Outlet and cost of sales |
| housekeeping-manager | Housekeeping, Maintenance, Service requests, Tasks, Dashboard | rooms, guests, stock, HR, settings | Room status board |
| storekeeper | Stock, Stock operations (RFQ), Stock control, Tasks, Dashboard | stock | Store |
| pos-cashier | POS, Payment links, Service requests, Loyalty counter, Tasks, Dashboard | billing, guests | Till |
| maintenance-technician | Maintenance, Tasks, Service requests, Dashboard | rooms, guests, settings | Work orders |
| room-attendant | Housekeeping, Tasks, Service requests, Dashboard | rooms, guests | Room list |

Phone tabs are the first four entries above. Receptionist example: Arrivals, Front desk, Reservations, In-house.

## Newly linked screens (they existed, but no menu entry led to them)
Clock in and out, My requests (employee-only); Loyalty counter, members, promotions; Finance: month-end close, accruals, bank feed, tax and VAT, dunning, cash forecast, statements pack, payroll filings, consolidation, dimensions; Stock: stocktake, scan, reorder, issues, minibar, variance, valuation, batches, menu engineering; HR: time and attendance, leave, labour cost, reviews, feedback, staff tasks, discipline, exits, skills, training, succession planning, compliance; Settings: service request settings (shown only with `servicerequests.settings`), messaging templates, data import, document templates; Reports: issued documents (`documents.verify.log`).
