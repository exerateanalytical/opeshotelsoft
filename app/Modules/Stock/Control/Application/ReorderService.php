<?php

namespace App\Modules\Stock\Control\Application;

use App\Modules\Stock\Application\ProcurementService;
use App\Modules\Stock\Application\PurchasingExtrasService;
use App\Modules\Stock\Application\StockService;
use App\Modules\Stock\Control\Domain\ParLevel;
use App\Modules\Stock\Control\Domain\SupplierLeadTime;
use App\Modules\Stock\Domain\StockItem;
use App\Modules\Stock\Domain\Store;
use App\Modules\Stock\Domain\Supplier;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Domain\DomainException;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Par levels and automatic reorder suggestions.
 * Position = on hand + still to arrive on open purchase orders (drafts included, so a second click never doubles up).
 * Reorder point = max(minimum level, average daily use over the last 28 days x supplier lead time).
 * Suggested quantity orders back up to par, rounded up to whole purchase units.
 */
class ReorderService
{
    public const USAGE_DAYS = 28;
    public const DEFAULT_LEAD_DAYS = 2;

    public function __construct(private StockService $stock, private PurchasingExtrasService $extras, private ProcurementService $procurement, private TenantContext $ctx) {}

    public function leadTime(?int $supplierId, ?int $override = null): int
    {
        if ($override !== null) {
            return $override;
        }

        return $supplierId ? (int) (SupplierLeadTime::where('supplier_id', $supplierId)->value('lead_time_days') ?? self::DEFAULT_LEAD_DAYS) : self::DEFAULT_LEAD_DAYS;
    }

    public function setLeadTime(int $supplierId, int $days): SupplierLeadTime
    {
        Supplier::findOrFail($supplierId);

        return SupplierLeadTime::updateOrCreate(['supplier_id' => $supplierId], ['lead_time_days' => $days]);
    }

    /** @return array<int, array> one row per par line that needs ordering */
    public function suggestions(?int $storeId = null): array
    {
        $hotel = Hotel::findOrFail($this->ctx->requireHotelId());
        $today = $hotel->business_date;
        $since = $today->copy()->subDays(self::USAGE_DAYS)->toDateString();
        $out = [];
        foreach (ParLevel::when($storeId, fn ($q, $v) => $q->where('store_id', $v))->get() as $par) {
            $item = StockItem::find($par->stock_item_id);
            if (! $item || ! $item->active) {
                continue;
            }
            $onHand = $this->stock->onHand($par->store_id, $item->id);
            $onOrder = (float) DB::table('purchase_order_lines as l')->join('purchase_orders as o', 'o.id', '=', 'l.purchase_order_id')
                ->where('o.hotel_id', $hotel->id)->where('o.store_id', $par->store_id)->where('l.stock_item_id', $item->id)
                ->whereIn('o.status', ['draft', 'submitted', 'approved', 'partially_received'])
                ->selectRaw('coalesce(sum(greatest(l.quantity - l.received_quantity, 0)),0) as q')->value('q') * (float) $item->purchase_factor;
            $used = (float) DB::table('stock_movements')->where('hotel_id', $hotel->id)->where('from_store_id', $par->store_id)->where('stock_item_id', $item->id)
                ->whereIn('kind', ['sale', 'issue', 'wastage'])->where('date', '>', $since)->sum('quantity');
            $daily = $used / self::USAGE_DAYS;
            $supplierId = $par->supplier_id ?? $item->preferred_supplier_id;
            $lead = $this->leadTime($supplierId, $par->lead_time_days);
            $point = max((float) $par->min_level, $daily * $lead);
            $position = $onHand + $onOrder;
            if ($position > $point + 0.00005 || $position >= (float) $par->par_level - 0.00005) {
                continue;
            }
            $factor = max(0.0001, (float) $item->purchase_factor);
            $qty = max(1, (int) ceil(((float) $par->par_level - $position) / $factor - 0.00001));
            $out[] = [
                'store_id' => $par->store_id, 'stock_item_id' => $item->id, 'code' => $item->code, 'name' => $item->name, 'unit' => $item->unit,
                'purchase_unit' => $item->purchase_unit ?: $item->unit, 'purchase_factor' => $factor, 'on_hand' => $onHand, 'on_order' => $onOrder,
                'par_level' => (float) $par->par_level, 'min_level' => (float) $par->min_level, 'daily_usage' => round($daily, 4), 'reorder_point' => round($point, 4),
                'suggested_quantity' => $qty, 'supplier_id' => $supplierId, 'lead_time_days' => $lead,
                'expected_date' => $today->copy()->addDays($lead)->toDateString(),
                'unit_price' => $supplierId ? $this->price($supplierId, $item, $qty) : 0,
            ];
        }

        return $out;
    }

    /** Suggestions grouped by supplier, with estimated net totals. */
    public function grouped(?int $storeId = null): array
    {
        $names = Supplier::pluck('name', 'id');
        $groups = [];
        foreach ($this->suggestions($storeId) as $s) {
            $key = $s['supplier_id'] ?? 0;
            $groups[$key] ??= ['supplier_id' => $s['supplier_id'], 'supplier' => $s['supplier_id'] ? ($names[$s['supplier_id']] ?? '#'.$s['supplier_id']) : null,
                'lead_time_days' => $s['lead_time_days'], 'expected_date' => $s['expected_date'], 'estimated_total' => 0, 'lines' => []];
            $groups[$key]['lines'][] = $s;
            $groups[$key]['estimated_total'] += (int) round($s['suggested_quantity'] * $s['unit_price']);
            $groups[$key]['lead_time_days'] = max($groups[$key]['lead_time_days'], $s['lead_time_days']);
        }

        return array_values($groups);
    }

    /** Contract price, else last receipt price from this supplier, else average cost x purchase factor. */
    private function price(int $supplierId, StockItem $item, float $qty): int
    {
        $p = $this->extras->activePrice($supplierId, $item->id, $qty);
        if ($p) {
            return (int) $p->unit_price;
        }
        $last = DB::table('goods_receipt_lines as l')->join('goods_receipts as g', 'g.id', '=', 'l.goods_receipt_id')
            ->where('g.supplier_id', $supplierId)->where('l.stock_item_id', $item->id)->orderByDesc('g.id')->value('l.unit_price');

        return (int) ($last ?? round((float) $item->avg_cost * (float) $item->purchase_factor));
    }

    /**
     * One click: a draft purchase order per (supplier, store) from the current suggestions.
     * Lines with no supplier are skipped and reported. @return array{orders:array,skipped:array}
     */
    public function convert(?array $supplierIds = null, ?int $storeId = null): array
    {
        $orders = [];
        $skipped = [];
        $byOrder = [];
        foreach ($this->suggestions($storeId) as $s) {
            if (! $s['supplier_id']) {
                $skipped[] = ['stock_item_id' => $s['stock_item_id'], 'name' => $s['name'], 'reason' => 'no_supplier'];

                continue;
            }
            if ($supplierIds !== null && ! in_array($s['supplier_id'], $supplierIds, true)) {
                continue;
            }
            $byOrder[$s['supplier_id'].'|'.$s['store_id']][] = $s;
        }
        DB::transaction(function () use ($byOrder, &$orders) {
            foreach ($byOrder as $lines) {
                $first = $lines[0];
                Store::findOrFail($first['store_id']);
                $po = $this->procurement->createOrder([
                    'supplier_id' => $first['supplier_id'], 'store_id' => $first['store_id'],
                    'expected_date' => collect($lines)->max('expected_date'),
                    'notes' => 'Reorder suggestion (par levels)',
                    'lines' => array_map(fn ($l) => ['stock_item_id' => $l['stock_item_id'], 'quantity' => $l['suggested_quantity'], 'unit_price' => $l['unit_price']], $lines),
                ]);
                $orders[] = ['id' => $po->id, 'number' => $po->number, 'supplier_id' => $po->supplier_id, 'store_id' => $po->store_id, 'status' => $po->status, 'total' => $po->total, 'lines' => count($lines)];
            }
        });
        if (! $orders && ! $skipped) {
            throw new DomainException('Nothing to reorder.', 'nothing-to-reorder');
        }

        return ['orders' => $orders, 'skipped' => $skipped];
    }
}
