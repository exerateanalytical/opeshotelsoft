<?php

namespace App\Modules\Stock\Application;

use App\Modules\Accounting\Domain\ChartOfAccounts;
use App\Modules\Stock\Domain\GoodsReceipt;
use App\Modules\Stock\Domain\PurchaseOrder;
use App\Modules\Stock\Domain\StockItem;
use App\Modules\Stock\Domain\Store;
use App\Modules\Stock\Domain\SupplierInvoice;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Domain\DomainException;
use App\Platform\Domain\NumberSequence;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/** Purchase order → approval → goods receipt → supplier invoice (3-way match) → payment. */
class ProcurementService
{
    /** Purchase orders above this net total (XAF) need a second approver. */
    public const DUAL_APPROVAL_THRESHOLD = 5_000_000;

    public function __construct(private StockService $stock, private EventBus $bus, private TenantContext $ctx) {}

    public function createOrder(array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($data) {
            $hotel = Hotel::findOrFail($this->ctx->requireHotelId());
            $po = PurchaseOrder::create([
                'number' => NumberSequence::next($hotel->id, 'po', 'BC'),
                'supplier_id' => $data['supplier_id'], 'store_id' => $data['store_id'],
                'order_date' => $hotel->business_date, 'expected_date' => $data['expected_date'] ?? null,
                'notes' => $data['notes'] ?? null, 'requested_by' => $this->ctx->actor()?->id,
            ]);
            foreach ($data['lines'] as $l) {
                $item = StockItem::findOrFail($l['stock_item_id']);
                $po->lines()->create([
                    'stock_item_id' => $item->id, 'quantity' => $l['quantity'], 'unit_price' => $l['unit_price'],
                    'vat_rate' => $l['vat_rate'] ?? $item->vat_rate,
                ]);
            }
            $this->totals($po);
            $this->bus->record('purchasing.order_created', $po->only(['id', 'number', 'supplier_id', 'total']), 'purchase_order', $po->id);

            return $po->load('lines');
        });
    }

    public function submit(PurchaseOrder $po): PurchaseOrder
    {
        $this->assertStatus($po, 'draft');
        $po->update(['status' => 'submitted']);
        $this->bus->record('purchasing.order_submitted', $po->only(['id', 'number', 'total']), 'purchase_order', $po->id);

        return $po;
    }

    public function approve(PurchaseOrder $po): PurchaseOrder
    {
        $this->assertStatus($po, 'submitted');
        $actor = $this->ctx->actor();
        if ($po->requested_by === $actor?->id) {
            throw new DomainException('You cannot approve your own purchase order (segregation of duties).', 'sod');
        }
        if ($po->net_total > self::DUAL_APPROVAL_THRESHOLD && ! $actor?->hasRole(['general-manager', 'group-owner', 'finance-director'])) {
            throw new DomainException('Orders above '.number_format(self::DUAL_APPROVAL_THRESHOLD).' need GM or finance-director approval.', 'approval-level');
        }
        $po->update(['status' => 'approved', 'approved_by' => $actor?->id, 'approved_at' => now()]);
        $this->bus->record('purchasing.order_approved', $po->only(['id', 'number', 'supplier_id', 'total']), 'purchase_order', $po->id);

        return $po;
    }

    public function cancel(PurchaseOrder $po): PurchaseOrder
    {
        if (! in_array($po->status, ['draft', 'submitted', 'approved'], true)) {
            throw new DomainException("Purchase order {$po->number} is {$po->status}; only draft, submitted or approved orders with nothing received can be cancelled.", 'invalid-state');
        }
        $po->update(['status' => 'cancelled']);
        $this->bus->record('purchasing.order_cancelled', $po->only(['id', 'number', 'supplier_id', 'total']), 'purchase_order', $po->id);

        return $po;
    }

    /** @param array<int, array{purchase_order_line_id:int, quantity:float, unit_price?:int}> $lines */
    public function receive(PurchaseOrder $po, array $lines, ?string $deliveryNote = null): GoodsReceipt
    {
        if (! in_array($po->status, ['approved', 'partially_received'], true)) {
            throw new DomainException("Purchase order {$po->number} is {$po->status}; only approved orders can be received.", 'invalid-state');
        }

        return DB::transaction(function () use ($po, $lines, $deliveryNote) {
            $hotel = Hotel::findOrFail($this->ctx->requireHotelId());
            $store = Store::findOrFail($po->store_id);
            $grn = GoodsReceipt::create([
                'number' => NumberSequence::next($hotel->id, 'grn', 'BR'),
                'purchase_order_id' => $po->id, 'supplier_id' => $po->supplier_id, 'store_id' => $store->id,
                'date' => $hotel->business_date, 'delivery_note' => $deliveryNote, 'received_by' => $this->ctx->actor()?->id,
            ]);
            $net = 0;
            foreach ($lines as $l) {
                $pol = $po->lines()->lockForUpdate()->findOrFail($l['purchase_order_line_id']);
                $remaining = $pol->quantity - $pol->received_quantity;
                if ($l['quantity'] > $remaining + 0.0001) {
                    throw new DomainException("Cannot receive more than ordered ({$remaining} left).", 'over-receipt');
                }
                $price = $l['unit_price'] ?? $pol->unit_price;
                $item = StockItem::findOrFail($pol->stock_item_id);
                $grn->lines()->create([
                    'purchase_order_line_id' => $pol->id, 'stock_item_id' => $item->id,
                    'quantity' => $l['quantity'], 'unit_price' => $price, 'vat_rate' => $pol->vat_rate,
                ]);
                $pol->update(['received_quantity' => $pol->received_quantity + $l['quantity']]);
                $stockQty = $l['quantity'] * $item->purchase_factor;
                $this->stock->receive($item, $store, $stockQty, $price / $item->purchase_factor, [
                    'reference' => $grn->number, 'source_type' => 'goods_receipt', 'source_id' => $grn->id, 'supplier_id' => $po->supplier_id,
                ]);
                $net += (int) round($l['quantity'] * $price);
            }
            $grn->update(['net_total' => $net]);
            $fully = $po->lines()->get()->every(fn ($pl) => $pl->received_quantity >= $pl->quantity - 0.0001);
            $po->update(['status' => $fully ? 'received' : 'partially_received']);
            $this->bus->record('purchasing.goods_received', ['id' => $grn->id, 'number' => $grn->number, 'po' => $po->number, 'net_total' => $net], 'goods_receipt', $grn->id);

            return $grn->load('lines');
        });
    }

    /**
     * Record a supplier invoice. Stock invoices are matched to goods receipts
     * (net amount must agree within tolerance); expense invoices carry GL lines.
     */
    public function recordInvoice(array $data): SupplierInvoice
    {
        return DB::transaction(function () use ($data) {
            $grns = GoodsReceipt::whereIn('id', $data['goods_receipt_ids'] ?? [])->where('supplier_id', $data['supplier_id'])->get();
            if ($grns->where('invoiced', true)->isNotEmpty()) {
                throw new DomainException('One of these goods receipts is already invoiced.', 'duplicate-invoice');
            }
            $received = (int) $grns->sum('net_total');
            $expenses = (int) collect($data['expense_lines'] ?? [])->sum('amount');
            $variance = $data['net_total'] - $received - $expenses;
            $tolerance = max(500, (int) round($data['net_total'] * 0.01));
            if (abs($variance) > $tolerance) {
                throw new DomainException("Invoice net {$data['net_total']} does not match receipts ($received) + expenses ($expenses).", 'match-failed');
            }

            $inv = SupplierInvoice::create([
                'supplier_id' => $data['supplier_id'], 'reference' => $data['reference'], 'date' => $data['date'],
                'due_date' => $data['due_date'] ?? null, 'net_total' => $data['net_total'], 'tax_total' => $data['tax_total'] ?? 0,
                'total' => $data['net_total'] + ($data['tax_total'] ?? 0), 'goods_receipt_ids' => $grns->pluck('id')->all(),
                'expense_lines' => $data['expense_lines'] ?? [], 'match_variance' => $variance,
            ]);
            GoodsReceipt::whereIn('id', $grns->pluck('id'))->update(['invoiced' => true]);

            return $inv->refresh();
        });
    }

    public function approveInvoice(SupplierInvoice $inv): SupplierInvoice
    {
        if ($inv->status !== 'pending') {
            throw new DomainException('Only pending invoices can be approved.');
        }
        app(\App\Modules\Accounting\Payables\InvoiceApprovals::class)->assertCleared($inv); // A-B: amount-based approval chain
        $received = (int) GoodsReceipt::whereIn('id', $inv->goods_receipt_ids)->sum('net_total');
        $lines = [];
        if ($received > 0) {
            $lines[] = ['account' => ChartOfAccounts::role('supplier_accrual'), 'amount' => $received];
        }
        foreach ($inv->expense_lines as $e) {
            $lines[] = ['account' => $e['account'], 'amount' => (int) $e['amount']];
        }
        if ($inv->match_variance !== 0) {
            // Price difference between invoice and receipts (within match tolerance).
            $lines[] = ['account' => ChartOfAccounts::role('stock_variance'), 'amount' => $inv->match_variance];
        }
        $inv->update(['status' => 'approved', 'approved_by' => $this->ctx->actor()?->id]);
        $this->bus->record('payables.invoice_approved', [
            'id' => $inv->id, 'supplier_id' => $inv->supplier_id, 'reference' => $inv->reference, 'date' => $inv->date->toDateString(),
            'total' => $inv->total, 'tax' => $inv->tax_total, 'lines' => $lines,
        ], 'supplier_invoice', $inv->id);

        return $inv;
    }

    public function pay(SupplierInvoice $inv, int $amount, string $account, ?string $reference = null): SupplierInvoice
    {
        if (! in_array($inv->status, ['approved', 'partially_paid'], true)) {
            throw new DomainException('Only approved invoices can be paid.');
        }
        if ($amount <= 0 || $inv->paid + $amount > $inv->total) {
            throw new DomainException('Payment exceeds the amount due.');
        }

        return DB::transaction(function () use ($inv, $amount, $account, $reference) {
            $hotel = Hotel::findOrFail($this->ctx->requireHotelId());
            $wht = app(\App\Modules\Accounting\Tax\Withholding::class)->forPayment($inv, $amount); // A-B: retenue à la source
            $id = DB::table('supplier_payments')->insertGetId([
                'group_id' => $hotel->group_id, 'hotel_id' => $hotel->id, 'supplier_id' => $inv->supplier_id,
                'supplier_invoice_id' => $inv->id, 'date' => $hotel->business_date, 'amount' => $amount,
                'account' => $account, 'reference' => $reference, 'withheld' => $wht['amount'] ?? 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($wht) {
                app(\App\Modules\Accounting\Tax\Withholding::class)->issue($inv->supplier_id, $id, $hotel->business_date->toDateString(), $wht);
            }
            $paid = $inv->paid + $amount;
            $inv->update(['paid' => $paid, 'status' => $paid >= $inv->total ? 'paid' : 'partially_paid']);
            $this->bus->record('payables.payment_made', [
                'id' => $id, 'supplier_id' => $inv->supplier_id, 'invoice_id' => $inv->id, 'amount' => $amount,
                'account' => $account, 'reference' => $reference ?? $inv->reference, 'date' => $hotel->business_date->toDateString(),
                'withheld' => $wht['amount'] ?? 0,
            ], 'supplier_invoice', $inv->id);

            return $inv;
        });
    }

    private function totals(PurchaseOrder $po): void
    {
        $net = 0;
        $tax = 0;
        foreach ($po->lines as $l) {
            $lineNet = (int) round($l->quantity * $l->unit_price);
            $net += $lineNet;
            $tax += (int) round($lineNet * $l->vat_rate / 100);
        }
        $po->update(['net_total' => $net, 'tax_total' => $tax, 'total' => $net + $tax]);
    }

    private function assertStatus(PurchaseOrder $po, string $status): void
    {
        if ($po->status !== $status) {
            throw new DomainException("Purchase order {$po->number} is {$po->status}.", 'invalid-state');
        }
    }
}
