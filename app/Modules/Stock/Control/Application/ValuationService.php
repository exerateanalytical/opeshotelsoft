<?php

namespace App\Modules\Stock\Control\Application;

use App\Modules\Stock\Domain\StockItem;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Stock valuation as of any date, shown both ways:
 *  WAC  - the movement ledger replayed: receipts re-average the cost, outflows leave it unchanged.
 *  FIFO - receipts (and found stock) are cost layers consumed oldest first by every outflow; what is left is valued at its own cost.
 * Quantities come from the movement ledger (hotel-wide, or one store), so the report can be re-run for a past date.
 */
class ValuationService
{
    public function __construct(private VarianceService $variance, private TenantContext $ctx) {}

    public function asOf(string $date, ?int $storeId = null, ?string $category = null, bool $layers = false): array
    {
        $hotelId = $this->ctx->requireHotelId();
        $items = StockItem::when($category, fn ($q, $v) => $q->where('category', $v))->orderBy('name')->get();
        $qty = $this->quantities($hotelId, $date, $storeId);
        $moves = DB::table('stock_movements')->where('hotel_id', $hotelId)->where('date', '<=', $date)->orderBy('date')->orderBy('id')->get()->groupBy('stock_item_id');

        $rows = [];
        foreach ($items as $item) {
            $q = round($qty[$item->id] ?? 0.0, 4);
            $m = $moves->get($item->id, collect());
            if (abs($q) < 0.00005 && $m->isEmpty()) {
                continue;
            }
            [$wac, $remaining] = $this->replay($item, $m);
            $fifoUnit = $this->fifoUnitCost($remaining, $item, $wac);
            $row = [
                'stock_item_id' => $item->id, 'code' => $item->code, 'name' => $item->name, 'category' => $item->category, 'unit' => $item->unit, 'quantity' => $q,
                'wac_unit_cost' => round($wac, 4), 'wac_value' => (int) round($q * $wac),
                'fifo_unit_cost' => round($fifoUnit, 4), 'fifo_value' => (int) round($q * $fifoUnit),
            ];
            $row['difference'] = $row['fifo_value'] - $row['wac_value'];
            if ($layers) {
                $row['layers'] = array_map(fn ($l) => ['date' => $l[0], 'quantity' => round($l[1], 4), 'unit_cost' => round($l[2], 4)], $remaining);
            }
            $rows[] = $row;
        }
        $col = collect($rows);

        return [
            'as_of' => $date, 'store_id' => $storeId, 'rows' => $rows,
            'totals' => ['wac_value' => (int) $col->sum('wac_value'), 'fifo_value' => (int) $col->sum('fifo_value'), 'difference' => (int) $col->sum('difference')],
        ];
    }

    /** @return array<int,float> */
    private function quantities(int $hotelId, string $date, ?int $storeId): array
    {
        if ($storeId) {
            return $this->variance->balancesAt($storeId, $date);
        }
        $out = [];
        foreach (DB::table('stores')->where('hotel_id', $hotelId)->pluck('id') as $sid) {
            foreach ($this->variance->balancesAt($sid, $date) as $item => $q) {
                $out[$item] = ($out[$item] ?? 0) + $q;
            }
        }

        return $out;
    }

    /**
     * Replay one item's movements. Returns [weighted average cost, remaining FIFO layers [[date, qty, cost]]].
     * Transfers move stock inside the hotel and change neither cost nor layers.
     */
    private function replay(StockItem $item, $moves): array
    {
        $qty = 0.0;
        $avg = null;
        $layers = [];
        foreach ($moves as $m) {
            $q = (float) $m->quantity;
            $in = $m->kind === 'receipt' || ($m->kind === 'adjustment' && $q > 0);
            $out = in_array($m->kind, ['sale', 'issue', 'wastage', 'return'], true) || ($m->kind === 'adjustment' && $q < 0);
            if ($in) {
                $cost = $m->kind === 'receipt' ? (float) $m->unit_cost : ($avg ?? (float) $m->unit_cost);
                if ($m->kind === 'receipt') {
                    $avg = ($qty + $q) > 0 ? (max($qty, 0) * ($avg ?? $cost) + $q * $cost) / (max($qty, 0) + $q) : $cost;
                }
                $qty += $q;
                $layers[] = [$m->date, $q, $cost];
            } elseif ($out) {
                $take = abs($q);
                $qty -= $take;
                foreach ($layers as $i => &$l) {
                    if ($take <= 0.00005) {
                        break;
                    }
                    $u = min($take, $l[1]);
                    $l[1] -= $u;
                    $take -= $u;
                }
                unset($l);
                $layers = array_values(array_filter($layers, fn ($l) => $l[1] > 0.00005));
            }
        }

        return [$avg ?? (float) $item->avg_cost, $layers];
    }

    /** Average cost of the layers still on the shelf; unlayered quantity (opening balances) is valued at the average. */
    private function fifoUnitCost(array $layers, StockItem $item, float $wac): float
    {
        $q = array_sum(array_column($layers, 1));
        if ($q <= 0.00005) {
            return $wac;
        }
        $v = 0.0;
        foreach ($layers as $l) {
            $v += $l[1] * $l[2];
        }

        return $v / $q;
    }
}
