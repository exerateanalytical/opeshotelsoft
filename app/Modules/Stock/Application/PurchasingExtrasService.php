<?php

namespace App\Modules\Stock\Application;

use App\Modules\Accounting\Domain\ChartOfAccounts;
use App\Modules\Stock\Domain\GoodsReceipt;
use App\Modules\Stock\Domain\PurchaseOrder;
use App\Modules\Stock\Domain\PurchaseRequisition;
use App\Modules\Stock\Domain\PurchaseRequisitionLine;
use App\Modules\Stock\Domain\StockItem;
use App\Modules\Stock\Domain\Store;
use App\Modules\Stock\Domain\Supplier;
use App\Modules\Stock\Domain\SupplierInvoice;
use App\Modules\Stock\Domain\SupplierPaymentRun;
use App\Modules\Stock\Domain\SupplierPrice;
use App\Modules\Stock\Domain\SupplierReturn;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Domain\DomainException;
use App\Platform\Domain\NumberSequence;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Requisitions → purchase orders, supplier price lists, returns to supplier
 * (with credit notes), wastage reporting, supplier payment runs and AP ageing.
 */
class PurchasingExtrasService
{
    /** Fixed list of reasons for wastage / breakage issues. */
    public const WASTAGE_REASONS = ['expired', 'spoiled', 'breakage', 'damaged', 'theft', 'kitchen_error', 'guest_complaint', 'other'];

    /** Price deviation from the active list that triggers a warning (fraction). */
    public const PRICE_TOLERANCE = 0.05;

    public function __construct(private ProcurementService $procurement, private StockService $stock, private EventBus $bus, private TenantContext $ctx) {}

    // ---- Requisitions -------------------------------------------------

    public function createRequisition(array $data): PurchaseRequisition
    {
        return DB::transaction(function () use ($data) {
            $hotel = $this->hotel();
            Store::findOrFail($data['store_id']);
            $req = PurchaseRequisition::create([
                'number' => NumberSequence::next($hotel->id, 'pr', 'DA'),
                'department' => $data['department'], 'store_id' => $data['store_id'],
                'needed_by' => $data['needed_by'] ?? null, 'notes' => $data['notes'] ?? null,
                'requested_by' => $this->ctx->actor()?->id,
            ]);
            foreach ($data['lines'] as $l) {
                StockItem::findOrFail($l['stock_item_id']);
                $req->lines()->create(['stock_item_id' => $l['stock_item_id'], 'quantity' => $l['quantity'], 'supplier_id' => $l['supplier_id'] ?? null]);
            }
            $this->bus->record('purchasing.requisition_created', $req->only(['id', 'number', 'department']), 'purchase_requisition', $req->id);

            return $req->load('lines');
        });
    }

    public function submitRequisition(PurchaseRequisition $req): PurchaseRequisition
    {
        $this->assertReq($req, 'draft');
        $req->update(['status' => 'submitted']);

        return $req;
    }

    public function decideRequisition(PurchaseRequisition $req, bool $approve): PurchaseRequisition
    {
        $this->assertReq($req, 'submitted');
        $actor = $this->ctx->actor();
        if ($req->requested_by === $actor?->id) {
            throw new DomainException('You cannot approve your own requisition (segregation of duties).', 'sod');
        }
        $req->update(['status' => $approve ? 'approved' : 'rejected', 'approved_by' => $actor?->id, 'approved_at' => now()]);
        $this->bus->record($approve ? 'purchasing.requisition_approved' : 'purchasing.requisition_rejected', $req->only(['id', 'number']), 'purchase_requisition', $req->id);

        return $req;
    }

    /**
     * Convert approved requisitions into draft purchase orders, one per supplier.
     * The supplier is the line's, else the cheapest active price list, else the item's preferred supplier.
     *
     * @return Collection<int, PurchaseOrder>
     */
    public function convert(array $requisitionIds, ?int $storeId = null): Collection
    {
        return DB::transaction(function () use ($requisitionIds, $storeId) {
            $reqs = PurchaseRequisition::with('lines')->whereIn('id', $requisitionIds)->lockForUpdate()->get();
            if ($reqs->count() !== count(array_unique($requisitionIds))) {
                throw new DomainException('Requisition not found.', 'not-found');
            }
            foreach ($reqs as $r) {
                $this->assertReq($r, 'approved');
            }
            $storeId ??= $reqs->first()->store_id;
            $bySupplier = [];
            foreach ($reqs as $r) {
                foreach ($r->lines as $l) {
                    $item = StockItem::findOrFail($l->stock_item_id);
                    $supplierId = $l->supplier_id ?? $this->cheapest($item->id, $l->quantity)?->supplier_id ?? $item->preferred_supplier_id;
                    if (! $supplierId) {
                        throw new DomainException("No supplier for {$item->name}: set one on the requisition line, a price list or the item.", 'no-supplier');
                    }
                    $bySupplier[$supplierId][$item->id] ??= ['qty' => 0, 'lines' => []];
                    $bySupplier[$supplierId][$item->id]['qty'] += $l->quantity;
                    $bySupplier[$supplierId][$item->id]['lines'][] = $l->id;
                }
            }
            $orders = collect();
            foreach ($bySupplier as $supplierId => $items) {
                $lines = [];
                foreach ($items as $itemId => $agg) {
                    $price = $this->activePrice($supplierId, $itemId, $agg['qty'])?->unit_price
                        ?? (int) round(StockItem::find($itemId)->avg_cost * StockItem::find($itemId)->purchase_factor);
                    $lines[] = ['stock_item_id' => $itemId, 'quantity' => $agg['qty'], 'unit_price' => $price];
                }
                $po = $this->procurement->createOrder([
                    'supplier_id' => $supplierId, 'store_id' => $storeId, 'lines' => $lines,
                    'notes' => 'From requisitions '.$reqs->pluck('number')->implode(', '),
                ]);
                PurchaseRequisitionLine::whereIn('id', collect($items)->pluck('lines')->flatten())->update(['purchase_order_id' => $po->id]);
                $orders->push($po);
            }
            PurchaseRequisition::whereIn('id', $reqs->pluck('id'))->update(['status' => 'converted']);

            return $orders;
        });
    }

    // ---- Price lists --------------------------------------------------

    public function activePrice(int $supplierId, int $itemId, float $quantity = 0, ?string $date = null): ?SupplierPrice
    {
        return $this->activeQuery($itemId, $quantity, $date)->where('supplier_id', $supplierId)
            ->orderByDesc('min_quantity')->orderByDesc('valid_from')->first();
    }

    public function cheapest(int $itemId, float $quantity = 0): ?SupplierPrice
    {
        return $this->activeQuery($itemId, $quantity)->orderBy('unit_price')->first();
    }

    private function activeQuery(int $itemId, float $quantity, ?string $date = null)
    {
        $date ??= $this->hotel()->business_date->toDateString();

        return SupplierPrice::query()->where('stock_item_id', $itemId)->where('valid_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $date))
            ->where('min_quantity', '<=', $quantity);
    }

    /**
     * Fill missing PO line prices from the active price list and warn on lines
     * whose entered price differs from the list by more than 5%.
     *
     * @return array{0: array, 1: array<int, array>}
     */
    public function priceOrderLines(int $supplierId, array $lines): array
    {
        $warnings = [];
        foreach ($lines as $i => $l) {
            $list = $this->activePrice($supplierId, (int) $l['stock_item_id'], (float) $l['quantity']);
            if (! isset($l['unit_price'])) {
                if (! $list) {
                    throw new DomainException('No active price list for line '.($i + 1).'; enter a unit price.', 'no-price');
                }
                $lines[$i]['unit_price'] = $list->unit_price;
            } elseif ($list && $list->unit_price > 0 && abs($l['unit_price'] - $list->unit_price) / $list->unit_price > self::PRICE_TOLERANCE) {
                $warnings[] = [
                    'line' => $i, 'stock_item_id' => (int) $l['stock_item_id'], 'entered' => (int) $l['unit_price'], 'list_price' => $list->unit_price,
                    'difference_pct' => round(($l['unit_price'] - $list->unit_price) * 100 / $list->unit_price, 1),
                ];
            }
        }

        return [$lines, $warnings];
    }

    // ---- Returns to supplier ------------------------------------------

    /** @param array<int, array{goods_receipt_line_id:int, quantity:float}> $lines quantities in purchase units */
    public function returnToSupplier(GoodsReceipt $grn, array $lines, ?string $reason = null): SupplierReturn
    {
        return DB::transaction(function () use ($grn, $lines, $reason) {
            $hotel = $this->hotel();
            $store = Store::findOrFail($grn->store_id);
            $ret = SupplierReturn::create([
                'number' => NumberSequence::next($hotel->id, 'rtv', 'AV'), 'goods_receipt_id' => $grn->id,
                'supplier_id' => $grn->supplier_id, 'date' => $hotel->business_date, 'reason' => $reason,
                'user_id' => $this->ctx->actor()?->id,
            ]);
            $net = 0;
            $tax = 0;
            $out = [];
            foreach ($lines as $l) {
                $gl = $grn->lines()->lockForUpdate()->findOrFail($l['goods_receipt_line_id']);
                $left = $gl->quantity - $gl->returned_quantity;
                if ($l['quantity'] > $left + 0.0001) {
                    throw new DomainException("Cannot return more than received ({$left} left).", 'over-return');
                }
                $item = StockItem::findOrFail($gl->stock_item_id);
                $value = (int) round($l['quantity'] * $gl->unit_price);
                $this->stock->returnToSupplier($item, $store, $l['quantity'] * $item->purchase_factor, $gl->unit_price / $item->purchase_factor, [
                    'reference' => $ret->number, 'source_type' => 'supplier_return', 'source_id' => $ret->id, 'supplier_id' => $grn->supplier_id, 'reason' => $reason,
                ]);
                $gl->update(['returned_quantity' => $gl->returned_quantity + $l['quantity']]);
                $net += $value;
                $tax += (int) round($value * $gl->vat_rate / 100);
                $out[] = ['goods_receipt_line_id' => $gl->id, 'stock_item_id' => $item->id, 'item' => $item->name, 'quantity' => (float) $l['quantity'], 'unit_price' => $gl->unit_price, 'value' => $value];
            }

            if ($grn->invoiced) {
                // Credit note: reverse the payable (401) against the accrual cleared by the stock return, and the VAT.
                $creditLines = [['account' => ChartOfAccounts::role('supplier_accrual'), 'amount' => -$net]];
                if ($tax > 0) {
                    $creditLines[] = ['account' => ChartOfAccounts::role('vat_deductible'), 'amount' => -$tax];
                }
                $ret->update(['lines' => $out, 'net_total' => $net, 'tax_total' => $tax, 'total' => $net + $tax, 'credit_status' => 'open']);
                $this->bus->record('payables.invoice_approved', [
                    'id' => $ret->id, 'supplier_id' => $ret->supplier_id, 'reference' => $ret->number, 'date' => $ret->date->toDateString(),
                    'total' => -($net + $tax), 'tax' => 0, 'lines' => $creditLines, 'credit_note' => true,
                ], 'supplier_return', $ret->id);
            } else {
                // Not yet invoiced: the supplier will invoice the reduced receipt.
                $grn->update(['net_total' => max(0, $grn->net_total - $net)]);
                $ret->update(['lines' => $out, 'net_total' => $net, 'tax_total' => 0, 'total' => $net, 'credit_status' => 'receipt']);
            }
            $this->bus->record('purchasing.supplier_return', $ret->only(['id', 'number', 'supplier_id', 'total']), 'supplier_return', $ret->id);

            return $ret->refresh();
        });
    }

    /** Apply an open credit note to an approved invoice (subledger only; the GL already nets in 401). */
    public function applyCredit(SupplierReturn $ret, SupplierInvoice $inv, ?int $amount = null): SupplierInvoice
    {
        if ($ret->credit_status !== 'open') {
            throw new DomainException('This credit note has nothing left to apply.', 'invalid-state');
        }
        if ($ret->supplier_id !== $inv->supplier_id || ! in_array($inv->status, ['approved', 'partially_paid'], true)) {
            throw new DomainException('Credit can only be applied to an approved invoice of the same supplier.', 'invalid-state');
        }
        $amount ??= min($ret->total - $ret->credit_applied, $inv->total - $inv->paid);
        if ($amount <= 0 || $amount > $ret->total - $ret->credit_applied || $amount > $inv->total - $inv->paid) {
            throw new DomainException('Credit amount exceeds what is available or due.', 'invalid-amount');
        }

        return DB::transaction(function () use ($ret, $inv, $amount) {
            $hotel = $this->hotel();
            DB::table('supplier_payments')->insert([
                'group_id' => $hotel->group_id, 'hotel_id' => $hotel->id, 'supplier_id' => $inv->supplier_id, 'supplier_invoice_id' => $inv->id,
                'date' => $hotel->business_date, 'amount' => $amount, 'account' => ChartOfAccounts::role('supplier'),
                'reference' => $ret->number, 'supplier_return_id' => $ret->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $applied = $ret->credit_applied + $amount;
            $ret->update(['credit_applied' => $applied, 'credit_status' => $applied >= $ret->total ? 'applied' : 'open']);
            $paid = $inv->paid + $amount;
            $inv->update(['paid' => $paid, 'status' => $paid >= $inv->total ? 'paid' : 'partially_paid']);

            return $inv;
        });
    }

    // ---- Wastage report -----------------------------------------------

    public function wastageReport(string $from, string $to): array
    {
        $rows = DB::table('stock_movements as m')->join('stock_items as i', 'i.id', '=', 'm.stock_item_id')
            ->where('m.hotel_id', $this->ctx->requireHotelId())->where('m.kind', 'wastage')
            ->whereBetween('m.date', [$from, $to])
            ->groupBy('m.reason', 'i.id', 'i.code', 'i.name', 'i.unit')
            ->selectRaw("coalesce(m.reason, 'other') as reason, i.id as stock_item_id, i.code, i.name, i.unit, sum(m.quantity) as quantity, sum(m.value) as value")
            ->orderByDesc('value')->get()
            ->map(fn ($r) => ['reason' => $r->reason, 'stock_item_id' => $r->stock_item_id, 'code' => $r->code, 'name' => $r->name, 'unit' => $r->unit, 'quantity' => (float) $r->quantity, 'value' => (int) $r->value]);

        return [
            'from' => $from, 'to' => $to, 'rows' => $rows->values(),
            'by_reason' => $rows->groupBy('reason')->map(fn ($g, $k) => ['reason' => $k, 'value' => $g->sum('value')])->values(),
            'total' => (int) $rows->sum('value'),
        ];
    }

    // ---- Payment run and ageing ---------------------------------------

    public function dueInvoices(?string $dueBy = null, ?int $supplierId = null)
    {
        return SupplierInvoice::query()->whereIn('status', ['approved', 'partially_paid'])
            ->when($dueBy, fn ($q, $d) => $q->where(fn ($w) => $w->whereNull('due_date')->orWhere('due_date', '<=', $d)))
            ->when($supplierId, fn ($q, $s) => $q->where('supplier_id', $s))
            ->orderBy('due_date')->get()
            ->map(fn ($i) => $i->toArray() + ['due' => $i->total - $i->paid, 'supplier_name' => Supplier::find($i->supplier_id)?->name]);
    }

    /** Pay each selected invoice's balance in one batch from one treasury account (one payment per invoice). */
    public function paymentRun(array $invoiceIds, string $account, ?string $reference = null): SupplierPaymentRun
    {
        return DB::transaction(function () use ($invoiceIds, $account, $reference) {
            $hotel = $this->hotel();
            $invoices = SupplierInvoice::whereIn('id', $invoiceIds)->lockForUpdate()->get();
            if ($invoices->count() !== count(array_unique($invoiceIds))) {
                throw new DomainException('Invoice not found.', 'not-found');
            }
            $run = SupplierPaymentRun::create([
                'number' => NumberSequence::next($hotel->id, 'payrun', 'RG'), 'date' => $hotel->business_date,
                'account' => $account, 'reference' => $reference, 'user_id' => $this->ctx->actor()?->id,
            ]);
            $total = 0;
            foreach ($invoices as $inv) {
                $due = $inv->total - $inv->paid;
                if (! in_array($inv->status, ['approved', 'partially_paid'], true) || $due <= 0) {
                    throw new DomainException("Invoice {$inv->reference} is not approved and due.", 'invalid-state');
                }
                $before = DB::table('supplier_payments')->max('id') ?? 0;
                $this->procurement->pay($inv, $due, $account, $reference ?? "{$run->number} {$inv->reference}");
                DB::table('supplier_payments')->where('id', '>', $before)->where('supplier_invoice_id', $inv->id)->update(['payment_run_id' => $run->id]);
                $total += $due;
            }
            $run->update(['payments_count' => $invoices->count(), 'total' => $total]);
            $this->bus->record('payables.payment_run_completed', $run->only(['id', 'number', 'account', 'total', 'payments_count']), 'supplier_payment_run', $run->id);

            return $run->refresh();
        });
    }

    /** Outstanding approved invoices by supplier and days past due; open credit notes shown separately. */
    public function ageing(?string $asOf = null): array
    {
        $asOf = Carbon::parse($asOf ?? $this->hotel()->business_date);
        $buckets = ['current', 'd1_30', 'd31_60', 'd61_90', 'd90_plus'];
        $suppliers = [];
        $empty = array_fill_keys($buckets, 0) + ['credits' => 0, 'total' => 0];
        foreach (SupplierInvoice::whereIn('status', ['approved', 'partially_paid'])->get() as $inv) {
            $due = $inv->total - $inv->paid;
            if ($due <= 0) {
                continue;
            }
            $days = (int) ($inv->due_date ?? $inv->date)->diffInDays($asOf, false);
            $b = match (true) { $days <= 0 => 'current', $days <= 30 => 'd1_30', $days <= 60 => 'd31_60', $days <= 90 => 'd61_90', default => 'd90_plus' };
            $suppliers[$inv->supplier_id] ??= $empty;
            $suppliers[$inv->supplier_id][$b] += $due;
            $suppliers[$inv->supplier_id]['total'] += $due;
        }
        foreach (SupplierReturn::where('credit_status', 'open')->get() as $r) {
            $suppliers[$r->supplier_id] ??= $empty;
            $left = $r->total - $r->credit_applied;
            $suppliers[$r->supplier_id]['credits'] -= $left;
            $suppliers[$r->supplier_id]['total'] -= $left;
        }
        $names = Supplier::whereIn('id', array_keys($suppliers))->pluck('name', 'id');
        $rows = collect($suppliers)->map(fn ($v, $id) => ['supplier_id' => $id, 'supplier' => $names[$id] ?? "#$id"] + $v)->sortBy('supplier')->values();
        $totals = $empty;
        foreach ($rows as $r) {
            foreach ($totals as $k => $_) {
                $totals[$k] += $r[$k];
            }
        }

        return ['as_of' => $asOf->toDateString(), 'buckets' => $buckets, 'rows' => $rows, 'totals' => $totals];
    }

    private function hotel(): Hotel
    {
        return Hotel::findOrFail($this->ctx->requireHotelId());
    }

    private function assertReq(PurchaseRequisition $r, string $status): void
    {
        if ($r->status !== $status) {
            throw new DomainException("Requisition {$r->number} is {$r->status}.", 'invalid-state');
        }
    }
}
