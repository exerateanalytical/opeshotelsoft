# Money documents (D-B)

All render through the D-A engine (`DocumentRenderer`): letterhead, QR, issue stamp, watermark, copy marks.
Code: `app/Modules/Documents/Money/` (`MoneyController`, `Sheets` builders, `Printer`, `CreditNotes`), views
`Resources/views/money/{sheet,receipt80}.blade.php`, labels `lang/{fr,en}/documents3.json`.

Route: `GET /api/v1/documents/money/{kind}/{id?}` (`?lang=fr|en`). Guest-facing kinds follow the guest language,
the credit note stays French, staff kinds follow `?lang` then the hotel locale. Each kind lists the permissions that may
print it (any of); amounts are read from the source records (reports go through `InternalApi`, so they keep the
endpoint's own permission and tenant checks).

| Kind | Id | Catalog row |
|---|---|---|
| cancellation | reservation | 3 |
| deposit-receipt / payment-receipt / refund-voucher / void-slip | folio line | 4 / 11 / 12 / 25 |
| payment-receipt-pos | pos payment | 11 |
| payment-receipt-online / payment-receipt-link | payment intent / pay link | 11 |
| proforma | folio | 5 |
| express-checkout | reservation | 8 |
| credit-note | credit note invoice | 10 |
| guest-statement | guest (`from`,`to`) | 13 |
| room-move | reservation (`event`) | 18 |
| pos-receipt (80 mm) | pos check | 21 |
| shift-report | cashier shift | 23 |
| void-slip-pos | pos check | 25 |
| company-statement | company | 26 |
| reminder | invoice (`level` 1-3) | 27 |
| journal-voucher | journal entry | 28 |
| supplier-payment (voucher + remittance advice) | supplier payment | 29 |
| trial-balance, general-ledger, income-statement, balance-sheet, vat-return, cash-flow, ageing | none (`from`,`to`,`account`) | 30-36 |
| bank-reconciliation | bank account (`from`,`to`) | 37 |
| asset-register, depreciation-schedule | none (`year`) | 38 |
| budget-variance | budget (`by`,`through`) | 39 |
| daily-report | none (`date`) | 40 |

## Credit note (avoir)

`POST /api/v1/documents/money/credit-notes {invoice_id, reason, amount?}` (permission `invoices.credit`).
Creates an `invoices` row of kind `credit_note` (gapless `AV` number, `credits_invoice_id`, status `applied`), posts a
balanced reversing entry in journal VT (debit revenue per department and tax accounts, credit guest or city ledger),
issues the French credit-note document, and when the invoice is credited in full sets it `cancelled` and calls
`IssuedDocuments::replaceSource('invoice', ...)` so its QR page says "replaced by AV-...". A partial credit on a company
invoice is applied to its `paid` amount. No folio line is posted, so the folio balance is unchanged.

## Voided at source

`FolioService::reverse` calls `voidSource` for the payment receipt, deposit receipt and refund voucher of the reversed line.
