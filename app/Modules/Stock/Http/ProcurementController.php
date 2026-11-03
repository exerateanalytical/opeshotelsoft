<?php

namespace App\Modules\Stock\Http;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Stock\Application\ProcurementService;
use App\Modules\Stock\Application\PurchasingExtrasService;
use App\Modules\Stock\Domain\GoodsReceipt;
use App\Modules\Stock\Domain\PurchaseOrder;
use App\Modules\Stock\Domain\SupplierInvoice;
use Illuminate\Http\Request;

class ProcurementController
{
    public function __construct(private ProcurementService $procurement) {}

    public function orders(Request $request)
    {
        $this->can($request, 'view');

        return PurchaseOrder::with('lines', 'supplier:id,name')
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('supplier_id'), fn ($q, $v) => $q->where('supplier_id', $v))
            ->orderByDesc('id')->paginate(50);
    }

    public function showOrder(Request $request, PurchaseOrder $order)
    {
        $this->can($request, 'view');

        return $order->load('lines', 'supplier');
    }

    public function createOrder(Request $request)
    {
        $this->can($request, 'manage');
        $data = $request->validate([
            'supplier_id' => ['required', 'integer'], 'store_id' => ['required', 'integer'],
            'expected_date' => ['nullable', 'date'], 'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'], 'lines.*.stock_item_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.001'], 'lines.*.unit_price' => ['nullable', 'integer', 'min:0'],
            'lines.*.vat_rate' => ['nullable', 'numeric', 'between:0,100'],
        ]);

        [$data['lines'], $warnings] = app(PurchasingExtrasService::class)->priceOrderLines((int) $data['supplier_id'], $data['lines']);

        return response()->json($this->procurement->createOrder($data)->toArray() + ['price_warnings' => $warnings], 201);
    }

    public function submit(Request $request, PurchaseOrder $order)
    {
        $this->can($request, 'manage');

        return $this->procurement->submit($order);
    }

    public function approve(Request $request, PurchaseOrder $order)
    {
        abort_unless($request->user()->can('purchasing.approve'), 403);

        return $this->procurement->approve($order);
    }

    public function cancel(Request $request, PurchaseOrder $order)
    {
        $this->can($request, 'manage');

        return $this->procurement->cancel($order);
    }

    public function receive(Request $request, PurchaseOrder $order)
    {
        abort_unless($request->user()->can('stock.manage') || $request->user()->can('purchasing.manage'), 403);
        $data = $request->validate([
            'delivery_note' => ['nullable', 'string', 'max:100'],
            'lines' => ['required', 'array', 'min:1'], 'lines.*.purchase_order_line_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.001'], 'lines.*.unit_price' => ['nullable', 'integer', 'min:0'],
        ]);

        return response()->json($this->procurement->receive($order, $data['lines'], $data['delivery_note'] ?? null), 201);
    }

    public function receipts(Request $request)
    {
        $this->can($request, 'view');

        return GoodsReceipt::with('lines')->orderByDesc('id')->paginate(50);
    }

    /** Treasury accounts (SYSCOHADA class 5: bank, cash, mobile money) that can fund a payment. */
    public function paymentAccounts(Request $request)
    {
        abort_unless($request->user()->can('payables.view') || $request->user()->can('payables.manage'), 403);

        // 52 banks (incl. mobile money), 53-55 other financial institutions / e-money, 57 cash.
        // 50 securities, 56 overdrafts and 58 internal transfers are not payment sources.
        return Account::query()->where('class', 5)->where('postable', true)->where('active', true)
            ->where(fn ($q) => collect(['52', '53', '54', '55', '57'])->each(fn ($p) => $q->orWhere('code', 'like', $p.'%')))
            ->orderBy('code')->get(['id', 'code', 'name']);
    }

    public function invoices(Request $request)
    {
        abort_unless($request->user()->can('payables.view') || $request->user()->can('payables.manage') || $request->user()->can('purchasing.view'), 403);

        return SupplierInvoice::query()
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('supplier_id'), fn ($q, $v) => $q->where('supplier_id', $v))
            ->orderBy('due_date')->paginate(50);
    }

    public function recordInvoice(Request $request)
    {
        abort_unless($request->user()->can('payables.manage'), 403);
        $data = $request->validate([
            'supplier_id' => ['required', 'integer'], 'reference' => ['required', 'string', 'max:50'],
            'date' => ['required', 'date'], 'due_date' => ['nullable', 'date'],
            'net_total' => ['required', 'integer', 'min:0'], 'tax_total' => ['nullable', 'integer', 'min:0'],
            'goods_receipt_ids' => ['sometimes', 'array'], 'goods_receipt_ids.*' => ['integer'],
            'expense_lines' => ['sometimes', 'array'], 'expense_lines.*.account' => ['required', 'string'],
            'expense_lines.*.amount' => ['required', 'integer'], 'expense_lines.*.description' => ['nullable', 'string'],
        ]);

        return response()->json($this->procurement->recordInvoice($data), 201);
    }

    public function approveInvoice(Request $request, SupplierInvoice $invoice)
    {
        abort_unless($request->user()->can('payables.manage') && $request->user()->can('purchasing.approve'), 403);

        return $this->procurement->approveInvoice($invoice);
    }

    public function payInvoice(Request $request, SupplierInvoice $invoice)
    {
        abort_unless($request->user()->can('payables.manage'), 403);
        $data = $request->validate(['amount' => ['required', 'integer', 'min:1'], 'account' => ['required', 'string'], 'reference' => ['nullable', 'string']]);

        return $this->procurement->pay($invoice, $data['amount'], $data['account'], $data['reference'] ?? null);
    }

    private function can(Request $request, string $ability): void
    {
        $u = $request->user();
        abort_unless($u->can('purchasing.manage') || ($ability === 'view' && ($u->can('purchasing.view') || $u->can('stock.view'))), 403);
    }
}
