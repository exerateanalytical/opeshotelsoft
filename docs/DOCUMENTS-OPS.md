# Operations and HR documents (D-C)

All render through the D-A engine (`DocumentRenderer`): letterhead, QR, issue stamp, watermark, copy marks.
Code: `app/Modules/Documents/Operations/` (`OpsController`, builders `StockSheets`, `FieldSheets`, `HrSheets`, `HrTemplates`, `Versions`),
views `Resources/views/operations/{sheet,letter,ticket80,idcard}.blade.php`, labels `lang/{fr,en}/documents4.json`.

Route: `GET /api/v1/documents/ops/{kind}/{id?}` (`?lang=fr|en`). Each kind needs the permission of its source module (any of the list in
`OpsController::kinds`); sources are read through tenant-scoped models, so another hotel's record is a 404. Staff letters follow `?lang`,
then the employee's `payslip_language`, then the hotel locale. Personal documents (HR) carry no amount on the public verify page.

| Kind | Id | Catalog row |
|---|---|---|
| group-contract / rooming-list | room block | 15 |
| police-register | none (`from`, `to`) | 16 |
| lost-found-handin / lost-found-return | lost and found item | 17 |
| kot (80 mm) | POS check (`fired_at`) | 22 |
| pos-day-report | none (`date`, `outlet_id`) | 24 |
| goods-receipt | goods receipt | 42 |
| requisition / stock-issue | purchase requisition / issue movement (one reference = one note) | 43 |
| stock-transfer | transfer movement | 44 |
| supplier-return | supplier return | 45 |
| stocktake-sheet (`blind=1`; counters always get the blind sheet) / stocktake-variance (`stocktake.review`) | stocktake | 46 |
| stock-valuation | none (`as_of`, `store_id`, `category`) | 47 |
| rfq | RFQ (`supplier`) | 48 |
| offer-letter, employment-attestation, salary-certificate, staff-id-card | employee | 51, 52, 53, 61 |
| raise-letter / transfer-letter | compensation change / transfer (approved) | 54 / 55 |
| warning-letter (warning or suspension) | People sanction | 56 |
| termination-letter (termination or resignation acceptance) / final-settlement | People exit | 57 / 58 |
| leave-slip | approved leave request | 60 |
| payroll-journal | payroll run | 62 |

## HR letter templates

Kinds `hr_offer hr_attestation hr_salary_cert hr_raise hr_transfer hr_warning hr_suspension hr_termination hr_resignation`
have FR and EN defaults (`HrTemplates`), appear in the Templates screen, and use whitelisted placeholders only
(`employee.*`, `doc.number`, plus `salary.*`, `change.*`, `transfer.*`, `action.*`, `exit.*` per kind).

## Versions

`POST /api/v1/documents/ops/{kind}/{id}/regenerate {reason}` (permission `documents.ops.regenerate`) issues a new version of an HR letter or
ID card (number suffix `-R2`...), and the previous verify page says "replaced by ...". Reprints of the current version are copies.
Withdrawing a People sanction voids its letter(s). The final settlement is always flagged as needing payroll-expert review.
