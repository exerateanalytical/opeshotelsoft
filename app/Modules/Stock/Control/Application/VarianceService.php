<?php

namespace App\Modules\Stock\Control\Application;

use App\Modules\Stock\Control\Domain\UsageVarianceReview;
use App\Modules\Stock\Domain\StockItem;
use App\Modules\Stock\Domain\Store;
use App\Platform\Domain\DomainException;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Theoretical vs actual usage for an outlet's store over a period.
 *  theoretical = settled POS lines x recipe (or flat components when a dish has no recipe)
 *  actual      = opening + received (receipts, transfers in) - transferred out - returned - closing,
 *                with opening/closing rebuilt from the movement ledger (counts post as adjustments, so a count shortfall is actual usage).
 * variance = actual - theoretical (positive = used more than the recipes say); explained = recorded wastage + issues;
 * the rest is unexplained and is valued at current average cost.
 */
class VarianceService
{
    public const REASONS = ['portioning', 'spillage', 'theft', 'count_error', 'unrecorded_wastage', 'recipe_outdated', 'supplier_shortweight', 'transfer_unrecorded', 'other'];

    public function __construct(private RecipeService $recipes, private TenantContext $ctx) {}

    public function storeFor(int $outletId): Store
    {
        return Store::where('outlet_id', $outletId)->where('active', true)->first() ?? throw new DomainException('This outlet has no stock store.', 'no-store');
    }

    /** Quantity on hand per item for a store at the end of $date, rebuilt from movements after it. @return array<int,float> */
    public function balancesAt(int $storeId, string $date): array
    {
        $bal = DB::table('stock_balances')->where('store_id', $storeId)->pluck('quantity', 'stock_item_id')->map(fn ($q) => (float) $q)->all();
        foreach (DB::table('stock_movements')->where('date', '>', $date)->where(fn ($q) => $q->where('from_store_id', $storeId)->orWhere('to_store_id', $storeId))->get() as $m) {
            $bal[$m->stock_item_id] = ($bal[$m->stock_item_id] ?? 0) - $this->effect($m, $storeId);
        }

        return $bal;
    }

    /** Signed effect of a movement on one store's quantity. */
    public function effect(object $m, int $storeId): float
    {
        $q = (float) $m->quantity;
        if ($m->kind === 'adjustment') {
            return $q; // signed already
        }

        return ($m->to_store_id == $storeId ? $q : 0.0) - ($m->from_store_id == $storeId ? $q : 0.0);
    }

    /** @return array<int,float> stock item id => theoretical quantity */
    public function theoretical(int $outletId, string $from, string $to): array
    {
        $sold = DB::table('pos_check_lines as l')->join('pos_checks as c', 'c.id', '=', 'l.pos_check_id')
            ->where('c.outlet_id', $outletId)->where('c.status', 'settled')->whereBetween('c.business_date', [$from, $to])->where('l.voided', false)
            ->groupBy('l.pos_menu_item_id')->selectRaw('l.pos_menu_item_id as id, sum(l.quantity) as q')->get();
        $out = [];
        foreach ($sold as $row) {
            $recipe = $this->recipes->recipeFor($row->id);
            $needs = $recipe
                ? $this->recipes->explode($recipe, (float) $row->q / max(0.0001, (float) $recipe->yield_qty))
                : DB::table('pos_item_components')->where('pos_menu_item_id', $row->id)->get()->mapWithKeys(fn ($c) => [$c->stock_item_id => (float) $c->quantity * (float) $row->q])->all();
            foreach ($needs as $itemId => $q) {
                $out[$itemId] = ($out[$itemId] ?? 0) + $q;
            }
        }

        return $out;
    }

    public function compute(int $outletId, string $from, string $to): array
    {
        if ($from > $to) {
            throw new DomainException('The period start is after its end.', 'period-invalid');
        }
        $store = $this->storeFor($outletId);
        $opening = $this->balancesAt($store->id, date('Y-m-d', strtotime($from.' -1 day')));
        $closing = $this->balancesAt($store->id, $to);
        $theoretical = $this->theoretical($outletId, $from, $to);

        $flow = [];
        $reasons = [];
        $moves = DB::table('stock_movements')->whereBetween('date', [$from, $to])->where(fn ($q) => $q->where('from_store_id', $store->id)->orWhere('to_store_id', $store->id))->get();
        foreach ($moves as $m) {
            $f = &$flow[$m->stock_item_id];
            $f ??= ['in' => 0.0, 'out' => 0.0, 'wastage' => 0.0, 'issue' => 0.0, 'sale' => 0.0, 'adjustment' => 0.0];
            $q = (float) $m->quantity;
            match (true) {
                $m->kind === 'receipt' => $f['in'] += $q,
                $m->kind === 'transfer' && $m->to_store_id == $store->id => $f['in'] += $q,
                $m->kind === 'transfer' => $f['out'] += $q,
                $m->kind === 'return' => $f['out'] += $q,
                $m->kind === 'wastage' => $f['wastage'] += $q,
                $m->kind === 'issue' => $f['issue'] += $q,
                $m->kind === 'sale' => $f['sale'] += $q,
                $m->kind === 'adjustment' => $f['adjustment'] += $q,
                default => null,
            };
            if ($m->kind === 'wastage' && $m->reason) {
                $reasons[$m->stock_item_id][$m->reason] = ($reasons[$m->stock_item_id][$m->reason] ?? 0) + $q;
            }
            unset($f);
        }

        $ids = collect(array_keys($theoretical))->merge(array_keys($flow))->merge(array_keys($opening))->unique();
        $items = StockItem::whereIn('id', $ids)->get()->keyBy('id');
        $rows = [];
        foreach ($ids as $id) {
            $item = $items->get($id);
            if (! $item) {
                continue;
            }
            $f = $flow[$id] ?? ['in' => 0.0, 'out' => 0.0, 'wastage' => 0.0, 'issue' => 0.0, 'sale' => 0.0, 'adjustment' => 0.0];
            $o = $opening[$id] ?? 0.0;
            $c = $closing[$id] ?? 0.0;
            $actual = $o + $f['in'] - $f['out'] - $c;
            $theo = $theoretical[$id] ?? 0.0;
            $variance = $actual - $theo;
            $explained = $f['wastage'] + $f['issue'];
            $unexplained = $variance - $explained;
            if (abs($theo) < 0.00005 && abs($actual) < 0.00005) {
                continue;
            }
            $why = collect($reasons[$id] ?? [])->map(fn ($q, $r) => ['reason' => $r, 'quantity' => round($q, 4)])->values()->all();
            if ($f['adjustment'] < -0.00005) {
                $why[] = ['reason' => 'count_shortfall', 'quantity' => round(-$f['adjustment'], 4)];
            } elseif ($f['adjustment'] > 0.00005) {
                $why[] = ['reason' => 'count_surplus', 'quantity' => round($f['adjustment'], 4)];
            }
            if (abs($f['sale'] - $theo) > 0.0005) {
                $why[] = ['reason' => 'recipe_drift', 'quantity' => round($f['sale'] - $theo, 4)];
            }
            $cost = (float) $item->avg_cost;
            $rows[] = [
                'stock_item_id' => $id, 'code' => $item->code, 'name' => $item->name, 'unit' => $item->unit,
                'opening' => round($o, 4), 'received' => round($f['in'], 4), 'transferred_out' => round($f['out'], 4), 'closing' => round($c, 4),
                'actual' => round($actual, 4), 'theoretical' => round($theo, 4), 'variance_qty' => round($variance, 4),
                'wastage' => round($f['wastage'], 4), 'issued' => round($f['issue'], 4), 'unexplained_qty' => round($unexplained, 4),
                'variance_pct' => $theo > 0 ? round($variance / $theo * 100, 2) : null,
                'unit_cost' => $cost, 'theoretical_value' => (int) round($theo * $cost), 'actual_value' => (int) round($actual * $cost),
                'variance_value' => (int) round($variance * $cost), 'unexplained_value' => (int) round($unexplained * $cost), 'reasons' => $why,
            ];
        }
        usort($rows, fn ($a, $b) => abs($b['variance_value']) <=> abs($a['variance_value']));
        $col = collect($rows);

        return [
            'outlet_id' => $outletId, 'store_id' => $store->id, 'store' => $store->name, 'from' => $from, 'to' => $to, 'rows' => $rows,
            'totals' => [
                'theoretical_value' => (int) $col->sum('theoretical_value'), 'actual_value' => (int) $col->sum('actual_value'),
                'variance_value' => (int) $col->sum('variance_value'), 'unexplained_value' => (int) $col->sum('unexplained_value'),
                'variance_pct' => $col->sum('theoretical_value') > 0 ? round($col->sum('variance_value') / $col->sum('theoretical_value') * 100, 2) : null,
            ],
        ];
    }

    /** Freeze a computed variance so reasons can be attached line by line. */
    public function saveReview(int $outletId, string $from, string $to): UsageVarianceReview
    {
        $r = $this->compute($outletId, $from, $to);

        return DB::transaction(function () use ($r, $outletId, $from, $to) {
            $review = UsageVarianceReview::create([
                'outlet_id' => $outletId, 'store_id' => $r['store_id'], 'period_from' => $from, 'period_to' => $to,
                'variance_value' => $r['totals']['variance_value'], 'created_by' => $this->ctx->actor()?->id,
            ]);
            foreach ($r['rows'] as $row) {
                if ($row['variance_qty'] == 0.0) {
                    continue;
                }
                $review->lines()->create([
                    'stock_item_id' => $row['stock_item_id'], 'theoretical' => $row['theoretical'], 'actual' => $row['actual'], 'variance_qty' => $row['variance_qty'],
                    'unexplained_qty' => $row['unexplained_qty'], 'variance_value' => $row['variance_value'], 'reason_code' => $row['reasons'][0]['reason'] ?? null,
                ]);
            }

            return $review->load('lines');
        });
    }

    public function annotate(UsageVarianceReview $review, int $lineId, ?string $reason, ?string $note): void
    {
        if ($review->status === 'final') {
            throw new DomainException('This review is final.', 'review-final');
        }
        if ($reason !== null && ! in_array($reason, array_merge(self::REASONS, ['count_shortfall', 'count_surplus', 'recipe_drift']), true)) {
            throw new DomainException('Unknown variance reason.', 'reason-invalid');
        }
        $review->lines()->findOrFail($lineId)->update(['reason_code' => $reason, 'note' => $note]);
    }
}
