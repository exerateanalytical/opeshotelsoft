<?php

namespace App\Modules\Stock\Operations\Application;

use App\Models\User;
use App\Modules\Stock\Application\StockService;
use App\Modules\Stock\Domain\StockItem;
use App\Modules\Stock\Domain\Store;
use App\Modules\Stock\Operations\Domain\Stocktake;
use App\Modules\Stock\Operations\Domain\StocktakeLine;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Domain\DomainException;
use App\Platform\Domain\NumberSequence;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Blind stocktake. Counters see item names only, never the expected quantity.
 * Lines whose first count differs from the snapshot by more than the tolerance (percent of expected, with a
 * minimum quantity) must be recounted by a different person. The variance is approved by a third person
 * (neither the starter nor any counter) and posted as stock adjustments, so the GL entries come from the
 * existing stock.movement_posted rules and balance.
 */
class StocktakeService
{
    /** Shortage value (XAF) above which a leakage signal is raised for Insights. */
    public const LEAKAGE_SIGNAL_VALUE = 50_000;

    public function __construct(private StockService $stock, private EventBus $bus, private TenantContext $ctx) {}

    public function start(int $storeId, bool $blind = true, ?float $tolerancePct = null, ?float $toleranceQty = null, bool $includeAll = false): Stocktake
    {
        $store = Store::findOrFail($storeId);

        return DB::transaction(function () use ($store, $blind, $tolerancePct, $toleranceQty, $includeAll) {
            $hotel = Hotel::findOrFail($this->ctx->requireHotelId());
            $st = Stocktake::create(array_filter([
                'number' => NumberSequence::next($hotel->id, 'stocktake', 'INV'), 'store_id' => $store->id, 'blind' => $blind,
                'tolerance_pct' => $tolerancePct, 'tolerance_qty' => $toleranceQty, 'date' => $hotel->business_date, 'started_by' => $this->ctx->actor()?->id,
            ], fn ($v) => $v !== null));
            $balances = DB::table('stock_balances')->where('store_id', $store->id)->pluck('quantity', 'stock_item_id');
            $ids = $includeAll ? StockItem::where('active', true)->pluck('id')->merge($balances->keys())->unique() : $balances->keys();
            foreach ($ids as $itemId) {
                StocktakeLine::create(['stocktake_id' => $st->id, 'stock_item_id' => $itemId, 'expected' => (float) ($balances[$itemId] ?? 0)]);
            }
            $this->bus->record('stock.stocktake_started', ['id' => $st->id, 'number' => $st->number, 'store_id' => $store->id], 'stocktake', $st->id);

            return $st->fresh();
        });
    }

    /** The counter's sheet: no expected quantities. In recount only the lines to recount, without the first count. */
    public function sheet(Stocktake $st): array
    {
        $this->assertStatus($st, ['counting', 'recount']);
        $recount = $st->status === 'recount';
        $items = StockItem::whereIn('id', StocktakeLine::where('stocktake_id', $st->id)->pluck('stock_item_id'))->get()->keyBy('id');
        $lines = StocktakeLine::where('stocktake_id', $st->id)->when($recount, fn ($q) => $q->where('recount_required', true))->orderBy('id')->get()
            ->map(function ($l) use ($items, $recount) {
                $i = $items[$l->stock_item_id];
                $mine = $recount ? $l->count_2 : $l->count_1;

                return ['stock_item_id' => $i->id, 'code' => $i->code, 'name' => $i->name, 'unit' => $i->unit, 'counted' => $mine, 'done' => $mine !== null];
            })->values()->all();

        return ['id' => $st->id, 'number' => $st->number, 'status' => $st->status, 'phase' => $recount ? 'recount' : 'count', 'store_id' => $st->store_id, 'lines' => $lines];
    }

    /** @param array<int, array{stock_item_id:int, quantity:float}> $counts */
    public function record(Stocktake $st, array $counts, User $by): Stocktake
    {
        $this->assertStatus($st, ['counting', 'recount']);

        return DB::transaction(function () use ($st, $counts, $by) {
            foreach ($counts as $c) {
                $this->applyCount($st, (int) $c['stock_item_id'], (float) $c['quantity'], $by, false);
            }

            return $this->afterRecord($st);
        });
    }

    /** One scan adds to the count of the scanned item (first count, or recount when in recount). */
    public function scan(Stocktake $st, string $code, float $quantity, User $by, BarcodeService $barcodes): Stocktake
    {
        $this->assertStatus($st, ['counting', 'recount']);
        $item = $barcodes->itemFor($code);

        return DB::transaction(function () use ($st, $item, $quantity, $by) {
            $this->applyCount($st, $item->id, $quantity, $by, true);

            return $this->afterRecord($st);
        });
    }

    private function applyCount(Stocktake $st, int $itemId, float $qty, User $by, bool $add): void
    {
        if ($qty < 0) {
            throw new DomainException('Counted quantity cannot be negative.', 'count-invalid');
        }
        $line = StocktakeLine::where('stocktake_id', $st->id)->where('stock_item_id', $itemId)->lockForUpdate()->first();
        if (! $line) {
            if ($st->status !== 'counting') {
                throw new DomainException('This item is not on the sheet.', 'count-unknown-item');
            }
            StockItem::findOrFail($itemId);
            $line = StocktakeLine::create(['stocktake_id' => $st->id, 'stock_item_id' => $itemId, 'expected' => 0]); // found where the books show nothing
        }
        if ($st->status === 'counting') {
            $line->update(['count_1' => ($add ? (float) $line->count_1 : 0) + $qty, 'counted_by' => $by->id]);

            return;
        }
        if (! $line->recount_required) {
            throw new DomainException('This line does not need a recount.', 'count-not-required');
        }
        if ($line->counted_by === $by->id) {
            throw new DomainException('A recount must be done by a different person than the first count.', 'count-same-counter');
        }
        $line->update(['count_2' => ($add ? (float) $line->count_2 : 0) + $qty, 'recounted_by' => $by->id]);
    }

    private function afterRecord(Stocktake $st): Stocktake
    {
        if ($st->status === 'recount' && ! StocktakeLine::where('stocktake_id', $st->id)->where('recount_required', true)->whereNull('count_2')->exists()) {
            $st->update(['status' => 'review']);
        }

        return $st->fresh();
    }

    /** Counting is finished: apply the recount rule. Uncounted lines are refused unless explicitly skipped. */
    public function closeCount(Stocktake $st, bool $skipUncounted = false): Stocktake
    {
        $this->assertStatus($st, ['counting']);
        if (! $skipUncounted && StocktakeLine::where('stocktake_id', $st->id)->whereNull('count_1')->exists()) {
            throw new DomainException('Some items have not been counted.', 'count-incomplete');
        }
        StocktakeLine::where('stocktake_id', $st->id)->whereNull('count_1')->update(['count_1' => DB::raw('expected')]);
        $recount = 0;
        foreach (StocktakeLine::where('stocktake_id', $st->id)->get() as $l) {
            if (abs($l->count_1 - $l->expected) > $this->tolerance($st, $l->expected)) {
                $l->update(['recount_required' => true]);
                $recount++;
            }
        }
        $st->update(['status' => $recount ? 'recount' : 'review']);

        return $st->fresh();
    }

    private function tolerance(Stocktake $st, float $expected): float
    {
        return max((float) $st->tolerance_qty, abs($expected) * (float) $st->tolerance_pct / 100);
    }

    public function finalQty(StocktakeLine $l): float
    {
        return (float) ($l->count_2 ?? $l->count_1 ?? $l->expected);
    }

    /** Reviewer view: expected, counted, variance and value. */
    public function review(Stocktake $st): array
    {
        $items = StockItem::whereIn('id', StocktakeLine::where('stocktake_id', $st->id)->pluck('stock_item_id'))->get()->keyBy('id');
        $lines = [];
        $total = 0;
        foreach (StocktakeLine::where('stocktake_id', $st->id)->orderBy('id')->get() as $l) {
            $i = $items[$l->stock_item_id];
            $final = $this->finalQty($l);
            $delta = $final - (float) $l->expected;
            $value = (int) round($delta * (float) $i->avg_cost);
            $total += $value;
            $lines[] = ['stock_item_id' => $i->id, 'code' => $i->code, 'name' => $i->name, 'unit' => $i->unit, 'expected' => (float) $l->expected,
                'count_1' => $l->count_1, 'count_2' => $l->count_2, 'final' => $final, 'variance_qty' => round($delta, 4), 'variance_value' => $value, 'recounted' => $l->recount_required];
        }

        return ['id' => $st->id, 'number' => $st->number, 'status' => $st->status, 'store_id' => $st->store_id, 'blind' => $st->blind,
            'tolerance_pct' => $st->tolerance_pct, 'tolerance_qty' => $st->tolerance_qty, 'started_by' => $st->started_by, 'approved_by' => $st->approved_by,
            'variance_value' => $st->status === 'posted' ? $st->variance_value : $total, 'lines' => $lines];
    }

    /** Segregation of duties: the approver is neither the starter nor any counter. Posts the adjustments. */
    public function approve(Stocktake $st, User $approver, ?string $note = null): Stocktake
    {
        $this->assertStatus($st, ['review']);
        $involved = StocktakeLine::where('stocktake_id', $st->id)->get()->flatMap(fn ($l) => [$l->counted_by, $l->recounted_by])->push($st->started_by)->filter()->unique();
        if ($involved->contains($approver->id)) {
            throw new DomainException('The approver cannot be someone who started or counted this stocktake.', 'segregation-of-duties', 403);
        }

        return DB::transaction(function () use ($st, $approver, $note) {
            $store = Store::findOrFail($st->store_id);
            $total = 0;
            $shortage = 0;
            $detail = [];
            foreach (StocktakeLine::where('stocktake_id', $st->id)->get() as $l) {
                $delta = $this->finalQty($l) - (float) $l->expected; // movements since the snapshot stay as they are
                if (abs($delta) < 0.0001) {
                    continue;
                }
                $item = StockItem::findOrFail($l->stock_item_id);
                $m = $this->stock->adjust($item, $store, $delta, "Inventaire {$st->number}", ['source_type' => 'stocktake', 'source_id' => $st->id, 'reference' => $st->number]);
                $value = (int) ($m?->value ?? 0);
                $total += $value;
                if ($value < 0) {
                    $shortage += -$value;
                }
                $detail[] = ['stock_item_id' => $item->id, 'item' => $item->name, 'delta' => round($delta, 4), 'value' => $value];
            }
            $st->update(['status' => 'posted', 'approved_by' => $approver->id, 'approved_at' => now(), 'approval_note' => $note, 'variance_value' => $total]);
            $this->bus->record('stock.stocktake_posted', ['id' => $st->id, 'number' => $st->number, 'store_id' => $st->store_id, 'variance_value' => $total, 'shortage_value' => $shortage, 'lines' => $detail], 'stocktake', $st->id);
            if ($shortage >= self::LEAKAGE_SIGNAL_VALUE) {
                $this->bus->record('stock.leakage_suspected', ['source' => 'stocktake', 'id' => $st->id, 'number' => $st->number, 'store_id' => $st->store_id, 'amount' => $shortage, 'lines' => $detail], 'stocktake', $st->id);
            }

            return $st->fresh();
        });
    }

    public function cancel(Stocktake $st): Stocktake
    {
        $this->assertStatus($st, ['counting', 'recount', 'review']);
        $st->update(['status' => 'cancelled']);

        return $st;
    }

    private function assertStatus(Stocktake $st, array $allowed): void
    {
        if (! in_array($st->status, $allowed, true)) {
            throw new DomainException("Stocktake {$st->number} is {$st->status}.", 'invalid-state');
        }
    }
}
