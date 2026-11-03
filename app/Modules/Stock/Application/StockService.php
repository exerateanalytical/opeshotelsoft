<?php

namespace App\Modules\Stock\Application;

use App\Modules\Stock\Domain\StockItem;
use App\Modules\Stock\Domain\StockMovement;
use App\Modules\Stock\Domain\Store;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Domain\DomainException;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Perpetual inventory with weighted-average cost per hotel item.
 * Every movement emits stock.movement_posted for the general ledger.
 * Negative stock is refused (configurable per hotel: settings.allow_negative_stock).
 */
class StockService
{
    public function __construct(private EventBus $bus, private TenantContext $ctx) {}

    public function onHand(int $storeId, int $itemId): float
    {
        return (float) (DB::table('stock_balances')->where('store_id', $storeId)->where('stock_item_id', $itemId)->value('quantity') ?? 0);
    }

    /** Receive into a store at a unit cost (per stock unit); updates the weighted average. */
    public function receive(StockItem $item, Store $store, float $qty, float $unitCost, array $meta = []): StockMovement
    {
        if ($qty <= 0) {
            throw new DomainException('Received quantity must be positive.');
        }

        return DB::transaction(function () use ($item, $store, $qty, $unitCost, $meta) {
            $item = StockItem::lockForUpdate()->findOrFail($item->id);
            $totalQty = (float) DB::table('stock_balances')->where('stock_item_id', $item->id)->sum('quantity');
            $newAvg = $totalQty + $qty > 0 ? (($totalQty * $item->avg_cost) + ($qty * $unitCost)) / ($totalQty + $qty) : $unitCost;
            $item->update(['avg_cost' => round($newAvg, 4)]);
            $this->addBalance($store->id, $item->id, $qty);

            return $this->record('receipt', $item, null, $store, $qty, $unitCost, $meta);
        });
    }

    /** Consume from a store at average cost (issue to department, POS sale, wastage). */
    public function consume(string $kind, StockItem $item, Store $store, float $qty, array $meta = []): StockMovement
    {
        if (! in_array($kind, ['issue', 'sale', 'wastage'], true) || $qty <= 0) {
            throw new DomainException('Invalid consumption.');
        }

        return DB::transaction(function () use ($kind, $item, $store, $qty, $meta) {
            $item = StockItem::lockForUpdate()->findOrFail($item->id);
            $this->addBalance($store->id, $item->id, -$qty, allowNegative: $kind === 'sale' && $this->allowNegative());

            return $this->record($kind, $item, $store, null, $qty, $item->avg_cost, $meta + ['department' => $store->department]);
        });
    }

    public function transfer(StockItem $item, Store $from, Store $to, float $qty, array $meta = []): StockMovement
    {
        if ($from->id === $to->id || $qty <= 0) {
            throw new DomainException('Invalid transfer.');
        }

        return DB::transaction(function () use ($item, $from, $to, $qty, $meta) {
            $item = StockItem::lockForUpdate()->findOrFail($item->id);
            $this->addBalance($from->id, $item->id, -$qty);
            $this->addBalance($to->id, $item->id, $qty);

            return $this->record('transfer', $item, $from, $to, $qty, $item->avg_cost, $meta);
        });
    }

    /** Count adjustment: signed quantity (+ found, - missing). */
    public function adjust(StockItem $item, Store $store, float $delta, string $reason, array $meta = []): ?StockMovement
    {
        if (abs($delta) < 0.0001) {
            return null;
        }

        return DB::transaction(function () use ($item, $store, $delta, $reason, $meta) {
            $item = StockItem::lockForUpdate()->findOrFail($item->id);
            $this->addBalance($store->id, $item->id, $delta, allowNegative: false);

            return $this->record('adjustment', $item, $delta < 0 ? $store : null, $delta > 0 ? $store : null, $delta, $item->avg_cost, $meta + ['reason' => $reason]);
        });
    }

    public function returnToSupplier(StockItem $item, Store $store, float $qty, float $unitCost, array $meta = []): StockMovement
    {
        return DB::transaction(function () use ($item, $store, $qty, $unitCost, $meta) {
            $this->addBalance($store->id, $item->id, -$qty);

            return $this->record('return', $item, $store, null, $qty, $unitCost, $meta);
        });
    }

    private function addBalance(int $storeId, int $itemId, float $delta, bool $allowNegative = false): void
    {
        DB::table('stock_balances')->insertOrIgnore(['hotel_id' => $this->ctx->requireHotelId(), 'store_id' => $storeId, 'stock_item_id' => $itemId, 'quantity' => 0]);
        $row = DB::table('stock_balances')->where('store_id', $storeId)->where('stock_item_id', $itemId)->lockForUpdate()->first();
        $new = (float) $row->quantity + $delta;
        if ($new < -0.00001 && ! $allowNegative) {
            $item = StockItem::find($itemId);
            throw new DomainException("Not enough {$item->name} in stock (on hand {$row->quantity} {$item->unit}).", 'insufficient-stock');
        }
        DB::table('stock_balances')->where('id', $row->id)->update(['quantity' => $new]);
    }

    private function record(string $kind, StockItem $item, ?Store $from, ?Store $to, float $qty, float $unitCost, array $meta): StockMovement
    {
        $hotel = Hotel::findOrFail($this->ctx->requireHotelId());
        $value = (int) round(abs($qty) * $unitCost);
        $m = StockMovement::create([
            'kind' => $kind, 'stock_item_id' => $item->id, 'from_store_id' => $from?->id, 'to_store_id' => $to?->id,
            'quantity' => $qty, 'unit_cost' => $unitCost, 'value' => $kind === 'adjustment' ? ($qty < 0 ? -$value : $value) : $value,
            'date' => $meta['date'] ?? $hotel->business_date, 'department' => $meta['department'] ?? null,
            'reference' => $meta['reference'] ?? null, 'reason' => $meta['reason'] ?? null,
            'source_type' => $meta['source_type'] ?? null, 'source_id' => isset($meta['source_id']) ? (string) $meta['source_id'] : null,
            'user_id' => $this->ctx->actor()?->id,
        ]);

        $this->bus->record('stock.movement_posted', [
            'id' => $m->id, 'kind' => $kind, 'item_id' => $item->id, 'item' => $item->name, 'quantity' => $qty,
            'unit_cost' => $unitCost, 'value' => $m->value, 'date' => $m->date->toDateString(),
            'inventory_account' => $item->inventory_account, 'cost_account' => $item->cost_account,
            'department' => $m->department, 'supplier_id' => $meta['supplier_id'] ?? null,
            'description' => ucfirst($kind)." {$item->name} x {$qty}".($m->reference ? " ({$m->reference})" : ''),
        ], 'stock_movement', $m->id);

        $onHand = (float) DB::table('stock_balances')->where('stock_item_id', $item->id)->sum('quantity');
        if ($item->reorder_level > 0 && $onHand <= $item->reorder_level && in_array($kind, ['issue', 'sale', 'wastage', 'transfer', 'adjustment'], true)) {
            $this->bus->record('stock.reorder_needed', ['item_id' => $item->id, 'item' => $item->name, 'on_hand' => $onHand, 'reorder_level' => $item->reorder_level, 'reorder_quantity' => $item->reorder_quantity], 'stock_item', $item->id);
        }

        return $m;
    }

    private function allowNegative(): bool
    {
        return (bool) (Hotel::find($this->ctx->requireHotelId())->settings['allow_negative_stock'] ?? false);
    }
}
