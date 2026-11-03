<?php

namespace App\Modules\Stock\Operations\Application;

use App\Modules\Stock\Application\ProcurementService;
use App\Modules\Stock\Domain\StockItem;
use App\Modules\Stock\Domain\Store;
use App\Modules\Stock\Domain\Supplier;
use App\Modules\Stock\Operations\Domain\Rfq;
use App\Modules\Stock\Operations\Domain\RfqAward;
use App\Modules\Stock\Operations\Domain\RfqLine;
use App\Modules\Stock\Operations\Domain\RfqQuote;
use App\Modules\Stock\Operations\Domain\RfqSupplier;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Domain\DomainException;
use App\Platform\Domain\NumberSequence;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Request for quotation: lines go to several suppliers, quotes are recorded, the comparison grid shows the best
 * price per line, awards are per line (or the whole RFQ to one supplier) and convert into one draft PO per supplier.
 * Supplier-facing printable layouts are produced from supplierSheet() by the document agents.
 */
class RfqService
{
    public function __construct(private ProcurementService $procurement, private EventBus $bus, private TenantContext $ctx) {}

    /** @param array{title:string,store_id:int,due_date?:?string,notes?:?string,supplier_ids:int[],lines:array<int,array{stock_item_id:int,quantity:float,notes?:?string}>} $data */
    public function create(array $data): Rfq
    {
        return DB::transaction(function () use ($data) {
            Store::findOrFail($data['store_id']);
            $hotel = Hotel::findOrFail($this->ctx->requireHotelId());
            $rfq = Rfq::create([
                'number' => NumberSequence::next($hotel->id, 'rfq', 'DA'), 'title' => $data['title'], 'store_id' => $data['store_id'],
                'due_date' => $data['due_date'] ?? null, 'notes' => $data['notes'] ?? null, 'created_by' => $this->ctx->actor()?->id,
            ]);
            foreach ($data['lines'] as $l) {
                StockItem::findOrFail($l['stock_item_id']);
                RfqLine::create(['rfq_id' => $rfq->id, 'stock_item_id' => $l['stock_item_id'], 'quantity' => $l['quantity'], 'notes' => $l['notes'] ?? null]);
            }
            foreach (array_unique($data['supplier_ids']) as $sid) {
                Supplier::findOrFail($sid);
                RfqSupplier::create(['rfq_id' => $rfq->id, 'supplier_id' => $sid]);
            }

            return $rfq;
        });
    }

    public function detail(Rfq $rfq): array
    {
        $items = StockItem::whereIn('id', RfqLine::where('rfq_id', $rfq->id)->pluck('stock_item_id'))->get()->keyBy('id');
        $lines = RfqLine::where('rfq_id', $rfq->id)->orderBy('id')->get()->map(fn ($l) => $l->toArray() + [
            'item_code' => $items[$l->stock_item_id]->code, 'item_name' => $items[$l->stock_item_id]->name, 'unit' => $items[$l->stock_item_id]->unit,
        ])->all();
        $suppliers = RfqSupplier::where('rfq_id', $rfq->id)->get()->map(fn ($s) => $s->toArray() + ['name' => Supplier::find($s->supplier_id)?->name])->all();

        return $rfq->toArray() + ['lines' => $lines, 'suppliers' => $suppliers];
    }

    public function send(Rfq $rfq): Rfq
    {
        $this->assertStatus($rfq, ['draft']);
        RfqSupplier::where('rfq_id', $rfq->id)->update(['sent_at' => now()]);
        $rfq->update(['status' => 'sent']);
        $this->bus->record('stock.rfq_sent', ['id' => $rfq->id, 'number' => $rfq->number, 'suppliers' => RfqSupplier::where('rfq_id', $rfq->id)->pluck('supplier_id')->all()], 'rfq', $rfq->id);

        return $rfq;
    }

    /** @param array<int, array{rfq_line_id:int,unit_price:int,lead_time_days?:?int,valid_until?:?string,notes?:?string}> $lines */
    public function recordQuote(Rfq $rfq, int $supplierId, array $lines): Rfq
    {
        $this->assertStatus($rfq, ['sent']);
        if (! RfqSupplier::where('rfq_id', $rfq->id)->where('supplier_id', $supplierId)->exists()) {
            throw new DomainException('This supplier was not invited to the RFQ.', 'rfq-supplier');
        }

        return DB::transaction(function () use ($rfq, $supplierId, $lines) {
            foreach ($lines as $l) {
                RfqLine::where('rfq_id', $rfq->id)->findOrFail($l['rfq_line_id']);
                RfqQuote::updateOrCreate(
                    ['rfq_line_id' => $l['rfq_line_id'], 'supplier_id' => $supplierId],
                    ['rfq_id' => $rfq->id, 'unit_price' => $l['unit_price'], 'lead_time_days' => $l['lead_time_days'] ?? null, 'valid_until' => $l['valid_until'] ?? null, 'notes' => $l['notes'] ?? null],
                );
            }
            $this->bus->record('stock.rfq_quoted', ['id' => $rfq->id, 'supplier_id' => $supplierId], 'rfq', $rfq->id);

            return $rfq;
        });
    }

    /** One row per line with each supplier's quote, the lowest price flagged, and a total per supplier. */
    public function grid(Rfq $rfq): array
    {
        $suppliers = RfqSupplier::where('rfq_id', $rfq->id)->pluck('supplier_id');
        $names = Supplier::whereIn('id', $suppliers)->pluck('name', 'id');
        $quotes = RfqQuote::where('rfq_id', $rfq->id)->get()->groupBy('rfq_line_id');
        $awards = RfqAward::where('rfq_id', $rfq->id)->get()->keyBy('rfq_line_id');
        $totals = [];
        $complete = [];
        foreach ($suppliers as $sid) {
            $totals[$sid] = 0;
            $complete[$sid] = true;
        }
        $rows = [];
        foreach ($this->detail($rfq)['lines'] as $line) {
            $q = $quotes->get($line['id'], collect())->keyBy('supplier_id');
            $best = $q->min('unit_price');
            $cells = [];
            foreach ($suppliers as $sid) {
                $quote = $q->get($sid);
                if ($quote) {
                    $totals[$sid] += (int) round($quote->unit_price * $line['quantity']);
                } else {
                    $complete[$sid] = false;
                }
                $cells[] = [
                    'supplier_id' => $sid, 'unit_price' => $quote?->unit_price, 'line_total' => $quote ? (int) round($quote->unit_price * $line['quantity']) : null,
                    'lead_time_days' => $quote?->lead_time_days, 'valid_until' => $quote?->valid_until?->toDateString(),
                    'lowest' => $quote && $quote->unit_price === $best, 'awarded' => ($awards[$line['id']]->supplier_id ?? null) === $sid,
                ];
            }
            $rows[] = $line + ['quotes' => $cells, 'best_price' => $best, 'awarded_supplier_id' => $awards[$line['id']]->supplier_id ?? null];
        }

        return [
            'rfq' => $rfq->only(['id', 'number', 'title', 'status']),
            'suppliers' => $suppliers->map(fn ($sid) => ['id' => $sid, 'name' => $names[$sid] ?? null, 'total' => $totals[$sid], 'complete' => $complete[$sid]])->values()->all(),
            'lines' => $rows,
            'cheapest_complete_supplier_id' => collect($totals)->filter(fn ($t, $sid) => $complete[$sid])->sort()->keys()->first(),
        ];
    }

    /** Award per line: [{rfq_line_id, supplier_id}], or the whole RFQ to one supplier. */
    public function award(Rfq $rfq, array $awards = [], ?int $wholeSupplierId = null): Rfq
    {
        $this->assertStatus($rfq, ['sent', 'awarded']);

        return DB::transaction(function () use ($rfq, $awards, $wholeSupplierId) {
            if (RfqAward::where('rfq_id', $rfq->id)->whereNotNull('purchase_order_id')->exists()) {
                throw new DomainException('Awards were already converted to purchase orders.', 'rfq-converted');
            }
            if ($wholeSupplierId) {
                $awards = RfqLine::where('rfq_id', $rfq->id)->pluck('id')->map(fn ($id) => ['rfq_line_id' => $id, 'supplier_id' => $wholeSupplierId])->all();
            }
            if (! $awards) {
                throw new DomainException('Nothing to award.', 'rfq-empty');
            }
            foreach ($awards as $a) {
                $line = RfqLine::where('rfq_id', $rfq->id)->findOrFail($a['rfq_line_id']);
                $quote = RfqQuote::where('rfq_line_id', $line->id)->where('supplier_id', $a['supplier_id'])->first()
                    ?? throw new DomainException('Cannot award a line to a supplier that did not quote it.', 'rfq-no-quote');
                RfqAward::updateOrCreate(['rfq_line_id' => $line->id], ['rfq_id' => $rfq->id, 'supplier_id' => $a['supplier_id'], 'unit_price' => $quote->unit_price, 'quantity' => $line->quantity]);
            }
            $rfq->update(['status' => 'awarded']);
            $this->bus->record('stock.rfq_awarded', ['id' => $rfq->id, 'number' => $rfq->number], 'rfq', $rfq->id);

            return $rfq;
        });
    }

    /** One draft purchase order per awarded supplier. @return \App\Modules\Stock\Domain\PurchaseOrder[] */
    public function convert(Rfq $rfq): array
    {
        $this->assertStatus($rfq, ['awarded']);

        return DB::transaction(function () use ($rfq) {
            $pos = [];
            foreach (RfqAward::where('rfq_id', $rfq->id)->whereNull('purchase_order_id')->get()->groupBy('supplier_id') as $supplierId => $awards) {
                $lines = $awards->map(fn ($a) => ['stock_item_id' => RfqLine::find($a->rfq_line_id)->stock_item_id, 'quantity' => (float) $a->quantity, 'unit_price' => (int) $a->unit_price])->all();
                $lead = RfqQuote::where('rfq_id', $rfq->id)->where('supplier_id', $supplierId)->max('lead_time_days');
                $po = $this->procurement->createOrder([
                    'supplier_id' => $supplierId, 'store_id' => $rfq->store_id, 'notes' => "RFQ {$rfq->number}", 'lines' => $lines,
                    'expected_date' => $lead !== null ? Hotel::findOrFail($this->ctx->requireHotelId())->business_date->copy()->addDays((int) $lead)->toDateString() : null,
                ]);
                RfqAward::whereIn('id', $awards->pluck('id'))->update(['purchase_order_id' => $po->id]);
                $pos[] = $po->fresh('lines');
            }
            if (! RfqAward::where('rfq_id', $rfq->id)->whereNull('purchase_order_id')->exists()) {
                $rfq->update(['status' => 'converted']);
            }

            return $pos;
        });
    }

    public function cancel(Rfq $rfq): Rfq
    {
        $this->assertStatus($rfq, ['draft', 'sent', 'awarded']);
        $rfq->update(['status' => 'cancelled']);

        return $rfq;
    }

    /** Data for the supplier's printable request: lines to quote, quantities, deadline, quote already given. */
    public function supplierSheet(Rfq $rfq, int $supplierId): array
    {
        RfqSupplier::where('rfq_id', $rfq->id)->where('supplier_id', $supplierId)->firstOrFail();
        $quoted = RfqQuote::where('rfq_id', $rfq->id)->where('supplier_id', $supplierId)->get()->keyBy('rfq_line_id');

        return [
            'rfq' => $rfq->only(['id', 'number', 'title', 'due_date', 'notes']), 'supplier' => Supplier::findOrFail($supplierId)->only(['id', 'code', 'name']),
            'lines' => collect($this->detail($rfq)['lines'])->map(fn ($l) => [
                'line_id' => $l['id'], 'item_code' => $l['item_code'], 'item_name' => $l['item_name'], 'unit' => $l['unit'],
                'quantity' => $l['quantity'], 'notes' => $l['notes'], 'quoted_price' => $quoted[$l['id']]->unit_price ?? null,
            ])->all(),
        ];
    }

    private function assertStatus(Rfq $rfq, array $allowed): void
    {
        if (! in_array($rfq->status, $allowed, true)) {
            throw new DomainException("RFQ {$rfq->number} is {$rfq->status}.", 'invalid-state');
        }
    }
}
