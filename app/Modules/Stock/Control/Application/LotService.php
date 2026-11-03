<?php

namespace App\Modules\Stock\Control\Application;

use App\Modules\Stock\Application\PurchasingExtrasService;
use App\Modules\Stock\Application\StockService;
use App\Modules\Stock\Control\Domain\ItemControl;
use App\Modules\Stock\Control\Domain\StockLot;
use App\Modules\Stock\Domain\StockItem;
use App\Modules\Stock\Domain\StockMovement;
use App\Modules\Stock\Domain\Store;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Domain\DomainException;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Batch/lot and expiry tracking on top of the perpetual stock ledger.
 * Lots are a sub-ledger: stock movements stay authoritative (and post to the GL as before); lots follow them
 * (receipt creates a lot, issue/sale/wastage take First-Expired-First-Out, transfer moves lots between stores).
 * Stock received before an item was lot-tracked is "unlotted": consumption drains lots first, the rest is untracked.
 */
class LotService
{
    public function __construct(private StockService $stock, private EventBus $bus, private TenantContext $ctx) {}

    public function control(int $itemId): ItemControl
    {
        return ItemControl::firstOrCreate(['stock_item_id' => $itemId]);
    }

    public function setControl(StockItem $item, array $data): ItemControl
    {
        $c = $this->control($item->id);
        $c->update(collect($data)->only(['tracks_lots', 'shelf_life_days', 'expiry_alert_days'])->all());

        return $c;
    }

    /** First-expired-first-out order: dated lots by expiry, undated lots last, then oldest received. */
    public function fefoQuery(int $itemId, int $storeId)
    {
        return StockLot::where('stock_item_id', $itemId)->where('store_id', $storeId)->where('quantity_remaining', '>', 0)
            ->orderByRaw('expiry_date is null')->orderBy('expiry_date')->orderBy('received_date')->orderBy('id');
    }

    /** Event hook: stock.movement_posted. Idempotent per movement. */
    public function onMovement(int $movementId): void
    {
        $m = StockMovement::find($movementId);
        if (! $m || DB::table('stock_lot_movements')->where('stock_movement_id', $m->id)->exists()) {
            return;
        }
        match ($m->kind) {
            'receipt' => $this->onReceipt($m),
            'sale', 'issue', 'return' => $this->take($m, (int) $m->from_store_id, (float) $m->quantity),
            'wastage' => $m->source_type === 'lot_writeoff' ? $this->takeFromLot($m) : $this->take($m, (int) $m->from_store_id, (float) $m->quantity),
            'adjustment' => $m->from_store_id ? $this->take($m, (int) $m->from_store_id, abs((float) $m->quantity)) : null,
            'transfer' => $this->move($m),
            default => null,
        };
    }

    private function onReceipt(StockMovement $m): void
    {
        if (StockLot::where('receipt_movement_id', $m->id)->exists()) {
            return;
        }
        $control = ItemControl::where('stock_item_id', $m->stock_item_id)->first();
        if (! $control?->tracks_lots) {
            return;
        }
        $this->createLot($m, $m->reference ?: 'LOT-'.$m->id, $control->shelf_life_days ? $m->date->copy()->addDays($control->shelf_life_days) : null);
    }

    private function createLot(StockMovement $m, string $number, ?Carbon $expiry): StockLot
    {
        $lot = StockLot::create([
            'stock_item_id' => $m->stock_item_id, 'store_id' => $m->to_store_id, 'lot_number' => $number, 'received_date' => $m->date,
            'expiry_date' => $expiry, 'quantity_received' => $m->quantity, 'quantity_remaining' => $m->quantity, 'unit_cost' => $m->unit_cost, 'receipt_movement_id' => $m->id,
        ]);
        $this->log($lot, $m->id, 'receipt', (float) $m->quantity);

        return $lot;
    }

    /** Receive stock directly into a named lot (also turns lot tracking on for the item). */
    public function receiveLot(StockItem $item, Store $store, float $qty, float $unitCost, string $lotNumber, ?string $expiry, array $meta = []): StockLot
    {
        return DB::transaction(function () use ($item, $store, $qty, $unitCost, $lotNumber, $expiry, $meta) {
            $this->control($item->id)->update(['tracks_lots' => true]);
            $m = $this->stock->receive($item, $store, $qty, $unitCost, $meta + ['reference' => $lotNumber, 'source_type' => 'lot_receipt']);
            $lot = StockLot::where('receipt_movement_id', $m->id)->first();
            $expiryDate = $expiry ? Carbon::parse($expiry) : null;
            if ($lot) {
                $lot->update(['lot_number' => $lotNumber, 'expiry_date' => $expiryDate]);
            } else {
                $lot = $this->createLot($m, $lotNumber, $expiryDate);
            }

            return $lot;
        });
    }

    /** Draw $qty from the store's lots, FEFO. Quantity beyond the lots is unlotted stock and is left alone. */
    private function take(StockMovement $m, int $storeId, float $qty): void
    {
        foreach ($this->fefoQuery($m->stock_item_id, $storeId)->lockForUpdate()->get() as $lot) {
            if ($qty <= 0.00005) {
                break;
            }
            $use = min($qty, (float) $lot->quantity_remaining);
            $lot->update(['quantity_remaining' => (float) $lot->quantity_remaining - $use]);
            $this->log($lot, $m->id, $m->kind, -$use);
            $qty -= $use;
        }
    }

    private function takeFromLot(StockMovement $m): void
    {
        $lot = StockLot::find((int) $m->source_id);
        if ($lot) {
            $use = min((float) $m->quantity, (float) $lot->quantity_remaining);
            $lot->update(['quantity_remaining' => (float) $lot->quantity_remaining - $use]);
            $this->log($lot, $m->id, 'write_off', -$use);
        }
    }

    /** Transfers carry their lots (and expiry) to the destination store. */
    private function move(StockMovement $m): void
    {
        $qty = (float) $m->quantity;
        foreach ($this->fefoQuery($m->stock_item_id, (int) $m->from_store_id)->lockForUpdate()->get() as $lot) {
            if ($qty <= 0.00005) {
                break;
            }
            $use = min($qty, (float) $lot->quantity_remaining);
            $lot->update(['quantity_remaining' => (float) $lot->quantity_remaining - $use]);
            $this->log($lot, $m->id, 'transfer_out', -$use);
            $dest = StockLot::where('store_id', $m->to_store_id)->where('stock_item_id', $lot->stock_item_id)->where('lot_number', $lot->lot_number)
                ->where('expiry_date', $lot->expiry_date)->first();
            if ($dest) {
                $dest->update(['quantity_remaining' => (float) $dest->quantity_remaining + $use, 'quantity_received' => (float) $dest->quantity_received + $use]);
            } else {
                $dest = StockLot::create([
                    'stock_item_id' => $lot->stock_item_id, 'store_id' => $m->to_store_id, 'lot_number' => $lot->lot_number, 'received_date' => $lot->received_date,
                    'expiry_date' => $lot->expiry_date, 'quantity_received' => $use, 'quantity_remaining' => $use, 'unit_cost' => $lot->unit_cost, 'parent_lot_id' => $lot->id,
                ]);
            }
            $this->log($dest, $m->id, 'transfer_in', $use);
            $qty -= $use;
        }
    }

    private function log(StockLot $lot, ?int $movementId, string $kind, float $qty): void
    {
        DB::table('stock_lot_movements')->insert(['stock_lot_id' => $lot->id, 'stock_movement_id' => $movementId, 'kind' => $kind, 'quantity' => $qty, 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Write off a lot (all of what remains, or $qty): a wastage movement with reason "expired" (or the given reason),
     * so the existing stock posting rule books Dr cost / Cr inventory at the item's average cost.
     */
    public function writeOff(StockLot $lot, ?float $qty = null, string $reason = 'expired', ?string $note = null): StockMovement
    {
        if (! in_array($reason, PurchasingExtrasService::WASTAGE_REASONS, true)) {
            throw new DomainException('Unknown write-off reason.', 'reason-invalid');
        }
        $remaining = (float) $lot->quantity_remaining;
        $qty ??= $remaining;
        if ($qty <= 0 || $qty > $remaining + 0.00005) {
            throw new DomainException("Lot {$lot->lot_number} has only {$remaining} left.", 'lot-insufficient');
        }

        return DB::transaction(fn () => $this->stock->consume('wastage', StockItem::findOrFail($lot->stock_item_id), Store::findOrFail($lot->store_id), $qty, [
            'reference' => "Lot {$lot->lot_number}".($note ? " - {$note}" : ''), 'reason' => $reason, 'source_type' => 'lot_writeoff', 'source_id' => $lot->id,
        ]));
    }

    /** @return array{expired:array,expiring:array} lots past or near expiry (per-item alert window unless $days given). */
    public function expiry(?int $days = null, ?int $storeId = null): array
    {
        $today = Hotel::findOrFail($this->ctx->requireHotelId())->business_date->toDateString();
        $rows = StockLot::where('quantity_remaining', '>', 0)->whereNotNull('expiry_date')
            ->when($storeId, fn ($q, $v) => $q->where('store_id', $v))->orderBy('expiry_date')->get();
        $controls = ItemControl::whereIn('stock_item_id', $rows->pluck('stock_item_id'))->get()->keyBy('stock_item_id');
        $items = StockItem::whereIn('id', $rows->pluck('stock_item_id'))->get()->keyBy('id');
        $out = ['expired' => [], 'expiring' => []];
        foreach ($rows as $lot) {
            $window = $days ?? ($controls->get($lot->stock_item_id)?->expiry_alert_days ?? 7);
            $left = (int) Carbon::parse($today)->diffInDays($lot->expiry_date, false);
            $row = $lot->toArray() + ['item' => $items->get($lot->stock_item_id)?->name, 'unit' => $items->get($lot->stock_item_id)?->unit, 'days_left' => $left,
                'value' => (int) round((float) $lot->quantity_remaining * (float) $lot->unit_cost)];
            if ($left < 0) {
                $out['expired'][] = $row;
            } elseif ($left <= $window) {
                $out['expiring'][] = $row;
            }
        }

        return $out;
    }

    /** Raise stock.lot_expiring once per lot (alerted_at) for lots inside their alert window or expired. */
    public function raiseAlerts(): int
    {
        $n = 0;
        $found = $this->expiry();
        foreach (['expired', 'expiring'] as $bucket) {
            foreach ($found[$bucket] as $row) {
                $lot = StockLot::find($row['id']);
                if ($lot && ! $lot->alerted_at) {
                    $this->bus->record('stock.lot_expiring', [
                        'lot_id' => $lot->id, 'lot_number' => $lot->lot_number, 'item' => $row['item'], 'store_id' => $lot->store_id, 'expiry_date' => $lot->expiry_date->toDateString(),
                        'days_left' => $row['days_left'], 'quantity' => (float) $lot->quantity_remaining, 'value' => $row['value'], 'expired' => $bucket === 'expired',
                    ], 'stock_lot', $lot->id);
                    $lot->forceFill(['alerted_at' => now()])->save();
                    $n++;
                }
            }
        }

        return $n;
    }
}
