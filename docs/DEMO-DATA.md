# Demo data (local development only)

Load: `php artisan db:seed --class=DemoBulkSeeder` then `php artisan db:seed --class=DemoInvoicesSeeder`.
Run time about 25 minutes. Running them again adds more data on top; it does not reset.

Tunables (env): DEMO_BOOKINGS (1000), DEMO_HOTELS (12), DEMO_PAST_DAYS (14), DEMO_FUTURE_DAYS (10), DEMO_GUESTS (1200).

What it builds, through the real services so the books balance:
- 12 hotels in Cameroon, 1 000 rooms in 5 types (standard, twin, deluxe, family, suite) with bed types: 1 512 beds.
- 1 200 guests, 28 companies on account, 6 travel agents, 180 loyalty members.
- About 1 050 reservations simulated day by day (check-in, room charges at night audit, POS charges to room, payment, check-out): about 430 checked out, 120 in house, 390 future, plus cancellations and no-shows.
- 1 630 POS checks, 24 menu items with recipes, 288 stock items with opening stock, 10 suppliers.
- 103 employees with contracts, salaries and payment methods, 43 leave requests, two months of payroll approved and paid per hotel (24 runs).
- 314 invoices with dates spread over 75 days for the ageing reports.
- Every employee uses one shared generated avatar: `public/demo/avatar.png` (an illustration, not a photograph). Replace that file and every employee shows the new picture.

Occupancy is low (about 12% in house) because 1 000 bookings over 1 000 rooms is thin. Raise DEMO_BOOKINGS for busier hotels.
