# Document visual QA (D1)

## Method

The Browser pane cannot show PDFs and no rasteriser may be installed. A throw-away harness (scratchpad, not shipped) boots the
app, signs in as general manager, calls the real document routes in-process and swaps dompdf's backend for its built-in **GD adapter**,
which writes one PNG per page; the PNGs were then looked at (single pages and contact sheets). Limits of this method: the GD backend
draws the QR as a placeholder pattern (no SVG), ignores CSS rotation and opacity (so the diagonal watermark and image opacity were checked
by size and position only), and black and white was simulated by a grey-scale filter. Data: a clone of the dev database
(`opeshotelsoft_d1_qa`); fixtures were created through the real API or services (refund, reversal, room move, invoice and credit note,
bank account, budget, supplier payment, purchase order and goods receipt, return, requisition, stock issue and transfer, stocktake, RFQ,
room block, lost and found, raise, transfer, sanctions, exits, leave approval). Hotel 1 in the clone got logo, RCCM, address, phone, bank
and footer. Nothing in the real dev database was changed.

## Defects fixed

Shared layout (`resources/views/pdf/layout.blade.php`) and engine:
1. Right-aligned headers were left-aligned on every grid table (specificity of `table.grid th` over `.r`).
2. Amounts wrapped inside cells ("12 886 / 140"): numeric cells are no-wrap.
3. QR code 16 mm to 20 mm (A4 and 80 mm), white backing so the watermark never lowers contrast; bottom margins enlarged to fit.
4. Watermark text overflowed the page ("BROUILLON" cut at both edges) and tile rows clustered at the top: text size now fits the page width, tile rows are spread over the page height, effective opacity capped at 35 percent so text under it stays readable.
5. Executive letterhead: the left colour band hid the start of the footer (DUPLICATA, stamp); the footer is now offset.
6. Letterhead colours: small text in the accent colour (a gold such as #d4a017 is unreadable on white and in black and white) and light brand colours behind white text. `Letterhead::derive()` adds `color_text`, `accent_text`, `on_color` (darkened until readable; dark text on light brand bars); the Branding preview recomputes them after overriding colours.
7. Key/value blocks with amounts: amounts now right-aligned in a 125 mm block (money and operations sheets).
8. Payslip spilled three total lines to a second page with a four-line letterhead: tighter row padding, one page again.
9. Staff ID card: QR 9 mm to 15 mm bottom right (card is 54 mm high, 20 mm does not fit), number, issue time and Duplicata mark, hotel name shrinks instead of wrapping.
10. Folio status printed "closed": now Ouvert / Clôturé / Soldé / Annulé (FR) and Open / Closed / Settled / Void (EN).
11. Kitchen ticket printed "Table -" without a table: now "Sans table" / "No table".

## Letterhead designs (invoice, hotel with logo, RCCM, bank, footer)

Each of the 7 designs was rendered with: opaque logo, transparent-background logo, no logo, and a 110-character hotel name.
No overlap with the title or the tables, long names wrap (Classic 2 lines, Modern right block, Executive band, Heritage 3 lines, Resort 3 lines in the bar), missing logo leaves the name only,
transparent logo is on white in all designs (Resort and Executive put logos on a white plate). Black and white (grey-scale): navy bar prints dark grey, gold rule mid grey, still visible;
with a light brand colour the bars print light grey and the text on them switches to dark. All designs print the legal footer (NIU, RCCM, bank, footer text).
Watermark: text centre, diagonal, tile; image centre and tile; "drafts and copies only" (shows on a copy, not on an original); 80 mm ticket. Text is behind content and under 35 percent opacity; QR stays on a white square.

## Documents checked (FR unless stated; pages in brackets)

| # | Document | Result |
|---|---|---|
| 1 | Booking confirmation | OK (1), letter template |
| 2 | Quote | OK (1) |
| 3 | Cancellation confirmation | OK |
| 4 | Deposit receipt | OK (EN guest) |
| 5 | Pro-forma | OK |
| 6 | Registration card | OK |
| 7 | Guest folio | OK, status label fixed |
| 8 | Express check-out | OK |
| 9 | Tax invoice | OK, French, headers aligned |
| 10 | Credit note | OK (AV number, partial) |
| 11 | Payment receipt (A4, POS 80 mm) | OK |
| 12 | Refund voucher | OK |
| 13 | Guest statement | OK |
| 14 | Guest letter | OK |
| 15 | Group contract and rooming list | OK |
| 16 | Police register | OK, landscape, 7 pages, header repeats |
| 17 | Lost and found receipts | OK (hand-in; return uses same layout) |
| 18 | Room-move slip | OK |
| 19 | Loyalty card | OK |
| 20 | Loyalty statement | OK |
| 21 | POS receipt 80 mm | OK, QR 20 mm |
| 22 | Kitchen / bar ticket 80 mm | OK; fixed 200 mm roll (open, below) |
| 23 | Z report | OK |
| 24 | POS day report | OK |
| 25 | Void / comp slip | OK (folio reversal; POS variant not reproducible, no voided POS line) |
| 26 | Company statement | OK |
| 27 | Reminders 1 and 3 | OK |
| 28 | Journal voucher | OK |
| 29 | Supplier payment and remittance (FR, EN) | OK |
| 30 to 36 | Trial balance, ledger, income statement, balance sheet, VAT, cash flow, ageing | OK; trial balance, balance sheet and ageing 2 pages, header repeats; amounts no longer wrap |
| 37 | Bank reconciliation | OK (3 pages, amount block fixed) |
| 38 | Asset register, depreciation | OK |
| 39 | Budget variance | OK (2 pages) |
| 40 | Daily report | OK (2 pages) |
| 41 | Purchase order | Not viewed: PO PDF route not reached from the clone data (the PO made through the API is in draft/approved; document 41 shares the generic `pdf/purchase-order` view, header alignment fix applies). Open |
| 42 to 46 | Goods receipt, requisition, issue, transfer, return, stocktake sheet and variance | OK |
| 47 | Stock valuation | OK (2 pages) |
| 48 | RFQ | OK |
| 49 | Payslip | OK, one page after fix |
| 50 | Employment contract | Rendered (2 pages), page 2 not individually inspected |
| 51 to 53 | Offer letter, attestation, salary certificate | OK |
| 54 to 56 | Raise, transfer, warning and suspension letters | OK |
| 57, 58 | Termination and resignation letters, final settlement | OK |
| 59 | Work certificate | Open: route needs an approved exit, not created in the clone |
| 60 | Leave slip | OK after approval |
| 61 | Staff ID card | OK |
| 62 | Payroll journal | OK (2 pages) |

## Open

- Kitchen / bar and POS tickets use a fixed 200 mm roll page, so short tickets leave blank paper at the bottom. A dynamic roll height needs a measuring pass in `DocumentRenderer`; not done because it is not simple and safe (the footer is fixed to the page bottom).
- Untranslated or English fragments in French documents: stock and group status words (tentative, draft, counting, confirmed, returned), final settlement basis text ("month(s)", "day(s)", "banded"), department names such as "beverage".
- Transfer letter prints "****" for the position when the employee has none; the suspension letter prints "Faits constatés :" with nothing after it when no facts were entered.
- Purchase order (41) and work certificate (59) and POS void slip not visually checked (see table); employment contract page 2 and the second page of other multi-page documents were seen for the money and stock reports only.
- QR on the staff ID card is 15 mm (card limits), all other documents 20 mm.
- Rotation and opacity of the watermark could not be seen with the GD backend.

## Accent sweep

French pages checked: é è ê ô î ç à œ in names, titles, footers ("Propulsé par OPES Hôtels Soft", "Émis le", "Réimpression", "Clôturé", "Bon de remboursement", Cameroonian names). English documents: receipts, express check-out, supplier payment (EN) render correctly; mixed-language values come from data (for example "Espèces").

## Tests

See the final report of this run for results (own database `opeshotelsoft_test_d1`, dropped and recreated, suites run one at a time).

### Test results (own database `opeshotelsoft_test_d1`, dropped and recreated, suites one at a time, nothing else connected)

| Suite | Tests | Result |
|---|---|---|
| tests/Feature/Documents | 32 | 32 passed |
| tests/Feature/Onboarding | 16 | 15 passed, 1 failed |
| tests/Feature/Loyalty | 16 | 16 passed |
| tests/Feature/HR | 65 | 65 passed |
| tests/Feature/People | 13 | 13 passed |

The Onboarding failure (`test_confirmation_and_quote_print_from_reservation_with_custom_template`) is `column "public_code" does not exist` on `reservations`: a column another workstream's code now queries and whose migration is not in this tree; it is not in the document engine or views changed here. Earlier runs in this session that showed many errors were polluted by two of my own test chains running on the same database at once; the table above is the clean run.
