<?php

namespace App\Modules\Stock\Operations\Application;

use App\Modules\POS\Domain\ItemComponent;
use App\Modules\Stock\Control\Application\RecipeService;
use App\Modules\Stock\Domain\StockItem;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Menu engineering (Kasavana-Smith). From settled POS sales and I-A recipe costs: cost %, unit margin and popularity
 * per dish. Popular = quantity share at least 70% of an even share; profitable = unit margin at least the sales-weighted
 * average margin. Quadrants: star, plowhorse (popular, thin margin), puzzle (profitable, unpopular), dog.
 */
class MenuEngineeringService
{
    public const POPULARITY_FACTOR = 0.7;
    public const DEFAULT_VAT = 19.25;
    public const TARGET_FOOD_COST_PCT = 35.0;

    public function __construct(private RecipeService $recipes, private TenantContext $ctx) {}

    public function report(?string $from = null, ?string $to = null, ?int $outletId = null): array
    {
        $hotel = Hotel::findOrFail($this->ctx->requireHotelId());
        $to ??= $hotel->business_date->toDateString();
        $from ??= $hotel->business_date->copy()->subDays(30)->toDateString();

        $sold = DB::table('pos_check_lines as l')->join('pos_checks as c', 'c.id', '=', 'l.pos_check_id')->join('pos_menu_items as m', 'm.id', '=', 'l.pos_menu_item_id')
            ->where('c.hotel_id', $hotel->id)->where('c.status', 'settled')->where('l.voided', false)->whereBetween('c.business_date', [$from, $to])
            ->when($outletId, fn ($q, $v) => $q->where('c.outlet_id', $v))
            ->groupBy('l.pos_menu_item_id', 'm.name', 'm.code')->selectRaw('l.pos_menu_item_id as id, m.name, m.code, sum(l.quantity) as qty, sum(l.amount) as revenue')->get();

        $rows = [];
        foreach ($sold as $s) {
            $recipe = $this->recipes->recipeFor($s->id);
            $vat = $recipe ? (float) $recipe->sell_vat_rate : self::DEFAULT_VAT;
            $qty = (float) $s->qty;
            $netUnit = $qty > 0 ? ((float) $s->revenue / (1 + $vat / 100)) / $qty : 0.0;
            if ($recipe) {
                $cost = $this->recipes->unitCost($recipe);
                $known = true;
            } else {
                $components = ItemComponent::where('pos_menu_item_id', $s->id)->get();
                $cost = $components->sum(fn ($c) => (float) $c->quantity * (float) (StockItem::find($c->stock_item_id)?->avg_cost ?? 0));
                $known = $components->isNotEmpty();
            }
            $rows[] = [
                'menu_item_id' => (int) $s->id, 'code' => $s->code, 'name' => $s->name, 'quantity' => $qty, 'revenue' => (int) $s->revenue,
                'net_unit_price' => round($netUnit, 2), 'unit_cost' => round($cost, 2), 'cost_known' => $known,
                'cost_pct' => $netUnit > 0 ? round($cost / $netUnit * 100, 2) : null, 'unit_margin' => round($netUnit - $cost, 2), 'total_margin' => round(($netUnit - $cost) * $qty, 2),
                'target_margin_pct' => $recipe ? (float) $recipe->target_margin_pct : null,
            ];
        }

        $n = count($rows);
        $totalQty = array_sum(array_column($rows, 'quantity'));
        $avgMargin = $totalQty > 0 ? array_sum(array_column($rows, 'total_margin')) / $totalQty : 0.0;
        $popThreshold = $n > 0 ? self::POPULARITY_FACTOR * 100 / $n : 0.0;
        foreach ($rows as &$r) {
            $r['popularity_pct'] = $totalQty > 0 ? round($r['quantity'] / $totalQty * 100, 2) : 0.0;
            $popular = $r['popularity_pct'] >= $popThreshold - 0.00001;
            $profitable = $r['unit_margin'] >= $avgMargin - 0.00001;
            $r['quadrant'] = $popular ? ($profitable ? 'star' : 'plowhorse') : ($profitable ? 'puzzle' : 'dog');
            $r['suggestions'] = $this->suggestions($r);
        }
        unset($r);
        usort($rows, fn ($a, $b) => $b['total_margin'] <=> $a['total_margin']);

        return [
            'from' => $from, 'to' => $to, 'outlet_id' => $outletId,
            'summary' => ['items' => $n, 'quantity' => $totalQty, 'revenue' => array_sum(array_column($rows, 'revenue')), 'avg_unit_margin' => round($avgMargin, 2), 'popularity_threshold_pct' => round($popThreshold, 2),
                'by_quadrant' => array_count_values(array_column($rows, 'quadrant'))],
            'items' => $rows,
        ];
    }

    /** @return array<int, array{code:string,message:string}> codes double as FR/EN keys (stock3.menu.suggest.<code>) */
    private function suggestions(array $r): array
    {
        $out = [];
        $out[] = match ($r['quadrant']) {
            'star' => ['code' => 'keep_promote', 'message' => 'Keep as is and promote it: popular and profitable.'],
            'plowhorse' => ['code' => 'raise_price_or_cut_cost', 'message' => 'Popular but thin margin: raise the price slightly or reduce the portion or ingredient cost.'],
            'puzzle' => ['code' => 'promote_or_reposition', 'message' => 'Profitable but little ordered: promote it, rename it or move it to a better place on the menu.'],
            default => ['code' => 'rework_or_remove', 'message' => 'Neither popular nor profitable: rework the dish or take it off the menu.'],
        };
        if (! $r['cost_known']) {
            $out[] = ['code' => 'missing_cost', 'message' => 'No recipe or components: cost unknown. Add a recipe to get a real margin.'];
        } elseif ($r['cost_pct'] !== null && $r['cost_pct'] > ($r['target_margin_pct'] !== null ? 100 - $r['target_margin_pct'] : self::TARGET_FOOD_COST_PCT)) {
            $out[] = ['code' => 'cost_above_target', 'message' => 'Cost percentage is above target: review the recipe cost or the price.'];
        }

        return $out;
    }
}
