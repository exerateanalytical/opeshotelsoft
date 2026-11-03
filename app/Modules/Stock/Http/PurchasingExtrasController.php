<?php

namespace App\Modules\Stock\Http;

use App\Modules\Stock\Application\PurchasingExtrasService;
use App\Modules\Stock\Domain\GoodsReceipt;
use App\Modules\Stock\Domain\PurchaseRequisition;
use App\Modules\Stock\Domain\StockItem;
use App\Modules\Stock\Domain\SupplierInvoice;
use App\Modules\Stock\Domain\SupplierPaymentRun;
use App\Modules\Stock\Domain\SupplierPrice;
use App\Modules\Stock\Domain\SupplierReturn;
use Illuminate\Http\Request;

class PurchasingExtrasController
{
    public function __construct(private PurchasingExtrasService $svc) {}

    // Requisitions: any stock/purchasing user may request; approval needs purchasing.approve.
    public function requisitions(Request $request)
    {
        $this->any($request, ['purchasing.view', 'purchasing.manage', 'stock.view', 'stock.manage']);

        return PurchaseRequisition::with('lines')
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('id')->paginate(50);
    }

    public function createRequisition(Request $request)
    {
        $this->any($request, ['purchasing.manage', 'stock.manage']);
        $data = $request->validate([
            'department' => ['required', 'string', 'max:50'], 'store_id' => ['required', 'integer'],
            'needed_by' => ['nullable', 'date'], 'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'], 'lines.*.stock_item_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.001'], 'lines.*.supplier_id' => ['nullable', 'integer'],
        ]);

        return response()->json($this->svc->createRequisition($data), 201);
    }

    public function submitRequisition(Request $request, PurchaseRequisition $requisition)
    {
        $this->any($request, ['purchasing.manage', 'stock.manage']);

        return $this->svc->submitRequisition($requisition);
    }

    public function approveRequisition(Request $request, PurchaseRequisition $requisition)
    {
        $this->any($request, ['purchasing.approve']);

        return $this->svc->decideRequisition($requisition, true);
    }

    public function rejectRequisition(Request $request, PurchaseRequisition $requisition)
    {
        $this->any($request, ['purchasing.approve']);

        return $this->svc->decideRequisition($requisition, false);
    }

    public function convertRequisitions(Request $request)
    {
        $this->any($request, ['purchasing.manage']);
        $data = $request->validate(['requisition_ids' => ['required', 'array', 'min:1'], 'requisition_ids.*' => ['integer'], 'store_id' => ['nullable', 'integer']]);

        return response()->json(['orders' => $this->svc->convert($data['requisition_ids'], $data['store_id'] ?? null)], 201);
    }

    // Price lists.
    public function prices(Request $request)
    {
        $this->any($request, ['purchasing.view', 'purchasing.manage']);

        return SupplierPrice::query()
            ->when($request->query('supplier_id'), fn ($q, $v) => $q->where('supplier_id', $v))
            ->when($request->query('stock_item_id'), fn ($q, $v) => $q->where('stock_item_id', $v))
            ->orderBy('stock_item_id')->orderBy('supplier_id')->paginate(100);
    }

    public function storePrice(Request $request)
    {
        $this->any($request, ['purchasing.manage']);
        $data = $this->validatePrice($request);
        StockItem::findOrFail($data['stock_item_id']);

        return response()->json(SupplierPrice::create($data), 201);
    }

    public function updatePrice(Request $request, SupplierPrice $price)
    {
        $this->any($request, ['purchasing.manage']);
        $price->update($this->validatePrice($request));

        return $price;
    }

    public function destroyPrice(Request $request, SupplierPrice $price)
    {
        $this->any($request, ['purchasing.manage']);
        $price->delete();

        return response()->noContent();
    }

    public function lookupPrice(Request $request)
    {
        $this->any($request, ['purchasing.view', 'purchasing.manage']);
        $d = $request->validate(['supplier_id' => ['required', 'integer'], 'stock_item_id' => ['required', 'integer'], 'quantity' => ['nullable', 'numeric']]);
        $p = $this->svc->activePrice($d['supplier_id'], $d['stock_item_id'], (float) ($d['quantity'] ?? 0));

        return ['price' => $p, 'tolerance_pct' => PurchasingExtrasService::PRICE_TOLERANCE * 100];
    }

    // Returns to supplier.
    public function returns(Request $request)
    {
        $this->any($request, ['purchasing.view', 'purchasing.manage', 'payables.view', 'payables.manage']);

        return SupplierReturn::query()
            ->when($request->query('supplier_id'), fn ($q, $v) => $q->where('supplier_id', $v))
            ->when($request->query('credit_status'), fn ($q, $v) => $q->where('credit_status', $v))
            ->orderByDesc('id')->paginate(50);
    }

    public function createReturn(Request $request, GoodsReceipt $receipt)
    {
        $this->any($request, ['purchasing.manage']);
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:200'], 'lines' => ['required', 'array', 'min:1'],
            'lines.*.goods_receipt_line_id' => ['required', 'integer'], 'lines.*.quantity' => ['required', 'numeric', 'min:0.001'],
        ]);

        return response()->json($this->svc->returnToSupplier($receipt, $data['lines'], $data['reason'] ?? null), 201);
    }

    public function applyCredit(Request $request, SupplierReturn $return)
    {
        $this->any($request, ['payables.manage']);
        $data = $request->validate(['supplier_invoice_id' => ['required', 'integer'], 'amount' => ['nullable', 'integer', 'min:1']]);

        return $this->svc->applyCredit($return, SupplierInvoice::findOrFail($data['supplier_invoice_id']), $data['amount'] ?? null);
    }

    // Wastage.
    public function wastageReasons(Request $request)
    {
        $this->any($request, ['stock.view', 'stock.manage']);

        return PurchasingExtrasService::WASTAGE_REASONS;
    }

    public function wastageReport(Request $request)
    {
        $this->any($request, ['stock.view', 'stock.manage', 'reports.view']);
        $d = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);

        return $this->svc->wastageReport($d['from'], $d['to']);
    }

    // Payables: payment run and ageing.
    public function dueInvoices(Request $request)
    {
        $this->any($request, ['payables.view', 'payables.manage']);
        $d = $request->validate(['due_by' => ['nullable', 'date'], 'supplier_id' => ['nullable', 'integer']]);

        return $this->svc->dueInvoices($d['due_by'] ?? null, isset($d['supplier_id']) ? (int) $d['supplier_id'] : null);
    }

    public function paymentRuns(Request $request)
    {
        $this->any($request, ['payables.view', 'payables.manage']);

        return SupplierPaymentRun::orderByDesc('id')->paginate(50);
    }

    public function createPaymentRun(Request $request)
    {
        $this->any($request, ['payables.manage']);
        $data = $request->validate([
            'invoice_ids' => ['required', 'array', 'min:1'], 'invoice_ids.*' => ['integer'],
            'account' => ['required', 'string', 'max:20'], 'reference' => ['nullable', 'string', 'max:100'],
        ]);
        $allowed = app(ProcurementController::class)->paymentAccounts($request)->pluck('code');
        abort_unless($allowed->contains($data['account']), 422, 'The account is not a treasury account.');

        return response()->json($this->svc->paymentRun($data['invoice_ids'], $data['account'], $data['reference'] ?? null), 201);
    }

    public function ageing(Request $request)
    {
        $this->any($request, ['payables.view', 'payables.manage']);
        $d = $request->validate(['as_of' => ['nullable', 'date']]);

        return $this->svc->ageing($d['as_of'] ?? null);
    }

    private function validatePrice(Request $request): array
    {
        $d = $request->validate([
            'supplier_id' => ['required', 'integer'], 'stock_item_id' => ['required', 'integer'],
            'unit_price' => ['required', 'integer', 'min:0'], 'min_quantity' => ['nullable', 'numeric', 'min:0'],
            'valid_from' => ['required', 'date'], 'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
        ]);
        $d['min_quantity'] ??= 0;

        return $d;
    }

    private function any(Request $request, array $perms): void
    {
        $u = $request->user();
        abort_unless(collect($perms)->contains(fn ($p) => $u->can($p)), 403);
    }
}
