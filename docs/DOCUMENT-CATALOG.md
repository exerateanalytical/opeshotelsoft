# Printable document catalog

Every document the platform can or should print. Status: **PDF** = proper PDF exists today, **TPL** = editable template exists today, **EXP** = data export only (no print layout), **SCR** = printed from a screen with the browser (not clean), **NEW** = does not exist.

## The standard every document must follow

1. **Prints clean.** The PDF is the print path: no sidebar, no top bar, no browser header or footer (a PDF carries none). Every on-screen "Print" button opens the PDF in a new tab. A4 portrait by default, A4 landscape for wide tables, 80 mm for POS receipts and kitchen tickets, ID-1 for cards.
2. **QR code to verify.** Each issued document gets a record in `issued_documents` (type, number, hotel, issued at, issued by, SHA-256 of the content, status, verify code). The QR holds `https://<host>/verify/<code>`. The public verify page shows type, number, hotel, issue time, amount where relevant, and status: **valid**, **voided**, **replaced by a later version**. Scanning never reveals more than that.
3. **Time-stamped.** Issue time (hotel time zone and UTC) printed on every page footer. Re-prints keep the original issue time, print a "Copy / Duplicata n" mark and log who re-printed.
4. **Platform watermark.** Chosen once in the onboarding wizard (image or text, opacity, position; applies to every document, with an option to restrict to drafts and copies). The same watermark appears on every document kind.
5. **Letterhead.** Seven designs to choose from (below), filled from hotel branding: logo, colours, NIU, RCCM, address, phone, bank details, footer.
6. **FR / EN.** Guest-facing documents follow the guest language; the legal invoice stays French; staff documents follow the document language chosen at issue.
7. **No dead ends.** Voided or corrected documents are never deleted; the old QR page then says "replaced by …".

## Catalog (62 documents)

### Guest and front office
| # | Document | Status |
|---|---|---|
| 1 | Booking confirmation | TPL |
| 2 | Quote / proposal | TPL |
| 3 | Cancellation confirmation | NEW |
| 4 | Deposit / prepayment receipt | NEW |
| 5 | Pro-forma invoice | NEW |
| 6 | Registration card (fiche d'arrivée) | PDF |
| 7 | Guest folio / bill | PDF |
| 8 | Express check-out summary | NEW |
| 9 | Tax invoice (facture, French legal) | PDF |
| 10 | Credit note (avoir) | NEW |
| 11 | Payment receipt (any payment: cash, MoMo, card, transfer) | NEW |
| 12 | Refund voucher | NEW |
| 13 | Guest statement of account | NEW |
| 14 | Guest letter (welcome, apology, general) | TPL |
| 15 | Group contract and rooming list | NEW |
| 16 | Police register extract (fiche de police) | EXP |
| 17 | Lost and found receipt | NEW |
| 18 | Room-move slip | NEW |
| 19 | Loyalty card | PDF |
| 20 | Loyalty points statement | PDF |

### Cashier and POS
| # | Document | Status |
|---|---|---|
| 21 | POS receipt (80 mm) | NEW |
| 22 | Kitchen / bar ticket (80 mm) | SCR |
| 23 | Cashier shift report (Z report) | NEW |
| 24 | POS day report | SCR |
| 25 | Void / comp authorisation slip | NEW |

### Accounting
| # | Document | Status |
|---|---|---|
| 26 | Company statement of account (city ledger) | NEW |
| 27 | Payment reminder letters (1st, 2nd, final notice) | NEW |
| 28 | Journal voucher (pièce comptable) | NEW |
| 29 | Supplier payment voucher and remittance advice | NEW |
| 30 | Trial balance | EXP |
| 31 | General ledger | EXP |
| 32 | Income statement | EXP |
| 33 | Balance sheet | EXP |
| 34 | VAT return | EXP |
| 35 | Cash-flow statement | EXP |
| 36 | Receivables / payables ageing | EXP |
| 37 | Bank reconciliation statement | NEW |
| 38 | Fixed-asset register and depreciation schedule | NEW |
| 39 | Budget variance report | NEW |
| 40 | Manager's daily report / night audit report | EXP |

### Stock and purchasing
| # | Document | Status |
|---|---|---|
| 41 | Purchase order | PDF |
| 42 | Goods receipt note (bon de réception) | NEW |
| 43 | Requisition / stock issue note | NEW |
| 44 | Stock transfer note | NEW |
| 45 | Supplier return / debit note | NEW |
| 46 | Stock count sheet and variance report | NEW |
| 47 | Stock valuation report | EXP |
| 48 | Request for quotation (RFQ) | NEW |

### HR
| # | Document | Status |
|---|---|---|
| 49 | Payslip | PDF |
| 50 | Employment contract (CDI, CDD, internship, day worker) | PDF |
| 51 | Offer / employment letter (lettre d'embauche) | NEW |
| 52 | Employment attestation (attestation de travail) | NEW |
| 53 | Salary certificate (attestation de salaire) | NEW |
| 54 | Raise / promotion letter | NEW |
| 55 | Transfer letter | NEW |
| 56 | Warning / suspension letter | NEW |
| 57 | Termination / resignation-acceptance letter | NEW |
| 58 | Final settlement statement (solde de tout compte) | NEW |
| 59 | Work certificate (certificat de travail) | PDF |
| 60 | Leave approval slip | NEW |
| 61 | Staff ID card (photo and QR) | NEW |
| 62 | Payroll journal and CNPS declaration | EXP |

Totals today: 9 PDF, 3 editable templates, 12 exports or screen prints that need a proper print layout, and **38 that do not exist yet**.

## Seven letterhead designs

Flat design only: no gradients, no shadows. All use the hotel's brand colour, logo, and print the legal footer (NIU, RCCM, bank).

| # | Name | Look |
|---|---|---|
| 1 | Classic | Centered logo and hotel name, thin rule, address line under, legal footer |
| 2 | Modern | Logo left, contact block right, solid brand-colour bar across the top |
| 3 | Executive | Narrow solid brand-colour band down the left edge carrying contact details |
| 4 | Minimal | Small logo, one hairline, lots of white space, tiny footer |
| 5 | Heritage | Serif type, double rule in the accent colour, crest-style logo, centered footer |
| 6 | Corporate | Logo left, document title right, legal identifiers in a footer strip in brand colour |
| 7 | Resort | Full-width solid brand-colour header with white logo and hotel name, accent underline |

## Build plan (3 agents, in order, max 4 agents at once overall)

| Agent | Scope | Done when |
|---|---|---|
| D-A Engine | `issued_documents` table and service; verify code with HMAC; public `/verify/{code}` page (FR/EN, flat); QR on every PDF using the shared QR helper; issue time stamp in footer; copy / duplicate marking and reprint log; platform watermark setting in the onboarding wizard and applied on every PDF; the 7 letterhead designs with a picker and live preview in Branding; shared print-clean layout and page sizes (A4, A4 landscape, 80 mm, ID-1); every existing PDF (rows 6, 7, 9, 19, 20, 41, 49, 50, 59) moved onto it | Every existing PDF carries QR, timestamp, watermark and the chosen letterhead; scanning the QR opens a verify page; voiding a source document marks its verify page voided; tests for tamper, voided, copy count |
| D-B Money documents | Rows 3, 4, 5, 8, 10, 11, 12, 13, 18, 21, 23, 25, 26, 27, 28, 29, 37, 38, 39, 40, 30 to 36 as print layouts; every "Print" button on screens opens the PDF; POS receipt 80 mm | Each document has a PDF route, a print button, FR/EN, and test |
| D-C Operations and HR documents | Rows 15, 16, 17, 22, 24, 42 to 48, 51 to 58, 60 to 62: HR letters from editable templates (offer letter, attestation de travail, attestation de salaire, raise, transfer, warning, termination, settlement, leave slip), staff ID card with the shared photo and QR, stock notes, group rooming list | Same as D-B |

D-B and D-C start after D-A lands, because they build on its engine.
