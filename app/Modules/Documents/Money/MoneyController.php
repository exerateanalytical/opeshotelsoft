<?php

namespace App\Modules\Documents\Money;

use App\Modules\Documents\Application\DocumentRenderer;
use App\Modules\FrontOffice\Domain\Invoice;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Http\Request;

/**
 * D-B money documents. GET /api/v1/documents/money/{kind}/{id?}. Every kind declares the permissions that may print it
 * (any of); amounts come straight from the source records, and tenant isolation is the sources' own hotel scope.
 */
class MoneyController
{
    private const FO = ['folios.view', 'folios.manage', 'cashier.view', 'cashier.manage'];

    private const ACC = ['accounting.view', 'accounting.manage'];

    public function __construct(private Sheets $sheets, private Printer $printer, private DocumentRenderer $engine, private TenantContext $ctx) {}

    /** kind => [permissions (any), language policy guest|staff|fr, builder] */
    private function kinds(Request $r, ?int $id): array
    {
        $q = $r->query();
        $need = fn () => $id ?? abort(404);

        return [
            'cancellation' => [['reservations.view', 'reservations.manage'], 'guest', fn () => $this->sheets->cancellation($need())],
            'payment-receipt' => [self::FO, 'guest', fn () => $this->sheets->folioPayment($need(), 'payment_receipt')],
            'deposit-receipt' => [self::FO, 'guest', fn () => $this->sheets->folioPayment($need(), 'deposit_receipt')],
            'payment-receipt-pos' => [['pos.manage', 'pos.view', ...self::FO], 'guest', fn () => $this->sheets->posPayment($need())],
            'payment-receipt-online' => [[...self::FO, 'payments.view', 'payments.manage'], 'guest', fn () => $this->sheets->intentReceipt($need())],
            'payment-receipt-link' => [[...self::FO, 'payments.view', 'payments.manage'], 'guest', fn () => $this->sheets->linkReceipt($need())],
            'proforma' => [['folios.view', 'folios.manage'], 'guest', fn () => $this->sheets->proforma($need())],
            'express-checkout' => [['folios.view', 'folios.manage'], 'guest', fn () => $this->sheets->expressCheckout($need())],
            'credit-note' => [['folios.view', 'accounting.view', 'receivables.view', 'invoices.credit'], 'fr', fn () => $this->sheets->creditNote($need())],
            'refund-voucher' => [self::FO, 'guest', fn () => $this->sheets->refundVoucher($need())],
            'guest-statement' => [['folios.view', 'folios.manage'], 'guest', fn () => $this->sheets->guestStatement($need(), $q['from'] ?? null, $q['to'] ?? null)],
            'room-move' => [['frontdesk.manage', 'frontdesk.view', 'reservations.view'], 'guest', fn () => $this->sheets->roomMove($need(), isset($q['event']) ? (int) $q['event'] : null)],
            'pos-receipt' => [['pos.manage', 'pos.view'], 'guest', fn () => $this->sheets->posReceipt($need())],
            'shift-report' => [['cashier.view', 'cashier.manage'], 'staff', fn () => $this->sheets->shiftReport($need())],
            'void-slip' => [['folios.void', 'folios.view', 'folios.manage'], 'staff', fn () => $this->sheets->voidSlipFolio($need())],
            'void-slip-pos' => [['pos.void', 'pos.manage', 'pos.view'], 'staff', fn () => $this->sheets->voidSlipPos($need())],
            'company-statement' => [['receivables.view', 'receivables.manage', 'accounting.view'], 'staff', fn () => $this->sheets->companyStatement($need())],
            'reminder' => [['receivables.view', 'receivables.manage', 'accounting.view'], 'staff', fn () => $this->sheets->reminder($need(), (int) ($q['level'] ?? 1), $this->lang)],
            'journal-voucher' => [self::ACC, 'staff', fn () => $this->sheets->journalVoucher($need())],
            'supplier-payment' => [['payables.view', 'payables.manage', 'accounting.view'], 'staff', fn () => $this->sheets->supplierPayment($need())],
            'bank-reconciliation' => [self::ACC, 'staff', fn () => $this->sheets->bankReconciliation($need(), $q)],
            'asset-register' => [self::ACC, 'staff', fn () => $this->sheets->assetRegister($q, false)],
            'depreciation-schedule' => [self::ACC, 'staff', fn () => $this->sheets->assetRegister($q, true)],
            'budget-variance' => [self::ACC, 'staff', fn () => $this->sheets->budgetVariance($need(), $q)],
            'daily-report' => [['reports.view', 'frontdesk.night_audit'], 'staff', fn () => $this->sheets->dailyReport($q)],
            ...collect(['trial-balance', 'general-ledger', 'income-statement', 'balance-sheet', 'vat-return', 'cash-flow', 'ageing'])
                ->mapWithKeys(fn ($k) => [$k => [[...self::ACC, 'reports.view'], 'staff', fn () => $this->sheets->report($k, $q)]])->all(),
        ];
    }

    public const KINDS = [
        'cancellation', 'payment-receipt', 'deposit-receipt', 'payment-receipt-pos', 'payment-receipt-online', 'payment-receipt-link', 'proforma', 'express-checkout',
        'credit-note', 'refund-voucher', 'guest-statement', 'room-move', 'pos-receipt', 'shift-report', 'void-slip', 'void-slip-pos', 'company-statement', 'reminder',
        'journal-voucher', 'supplier-payment', 'bank-reconciliation', 'asset-register', 'depreciation-schedule', 'budget-variance', 'daily-report',
        'trial-balance', 'general-ledger', 'income-statement', 'balance-sheet', 'vat-return', 'cash-flow', 'ageing',
    ];

    private string $lang = 'fr';

    public function show(Request $request, string $kind, ?int $id = null)
    {
        $kinds = $this->kinds($request, $id);
        abort_unless(isset($kinds[$kind]), 404);
        [$perms, $policy, $build] = $kinds[$kind];
        abort_unless($this->can($request, $perms), 403, 'You are not allowed to print this document.');

        $hotel = $this->ctx->hotelId() ? Hotel::find($this->ctx->hotelId()) : null;
        $explicit = in_array($request->query('lang'), ['fr', 'en'], true) ? $request->query('lang') : null;
        $this->lang = $explicit ?? ($hotel && str_starts_with((string) $hotel->locale, 'en') ? 'en' : 'fr');
        $this->sheets->currentLang = $this->lang;
        $built = $build();

        $lang = match (true) {
            $policy === 'fr' || ($built['lang'] ?? null) === 'fr' => 'fr',
            $explicit !== null => $explicit,
            $policy === 'guest' && ($gl = $this->sheets->guestLang($built['guest_id'] ?? null)) => $gl,
            default => $this->lang,
        };
        $this->sheets->currentLang = $lang;
        if ($lang !== $this->lang && $kind !== 'reminder') {
            $built = $build(); // rebuild so amounts in words follow the document language
        }

        return $this->engine->response($this->printer->render($kind, $built, $lang));
    }

    /** POST documents/money/credit-notes {invoice_id, reason, amount?}: issue an avoir and print-ready record. */
    public function storeCreditNote(Request $request, CreditNotes $notes)
    {
        abort_unless($this->can($request, ['invoices.credit']), 403, 'Credit notes need accountant permission.');
        $data = $request->validate(['invoice_id' => ['required', 'integer'], 'reason' => ['required', 'string', 'max:255'], 'amount' => ['nullable', 'integer', 'min:1']]);
        $note = $notes->issue(Invoice::findOrFail($data['invoice_id']), $data['reason'], $data['amount'] ?? null);

        return response()->json($note, 201);
    }

    private function can(Request $request, array $perms): bool
    {
        $u = $request->user();
        foreach ($perms as $p) {
            try {
                if ($u->can($p)) {
                    return true;
                }
            } catch (\Throwable) {
                // permission not defined in this install: ignore
            }
        }

        return false;
    }
}
