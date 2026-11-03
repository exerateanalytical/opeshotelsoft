<?php

namespace App\Modules\Stock\Control\Application;

use App\Modules\POS\Domain\MenuItem;
use App\Modules\Stock\Application\StockService;
use App\Modules\Stock\Control\Domain\Recipe;
use App\Modules\Stock\Domain\StockItem;
use App\Modules\Stock\Domain\Store;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Domain\DomainException;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Recipes (bill of materials with yield %), sub-recipes, plate cost and margin, and sale consumption.
 * A dish yields yield_qty portions; a sub-recipe yields yield_qty of its yield_unit (e.g. 2 l of sauce).
 */
class RecipeService
{
    public function __construct(private UomService $uom, private StockService $stock, private EventBus $bus, private TenantContext $ctx) {}

    /** @param array{code:string,name:string,kind?:string,pos_menu_item_id?:?int,yield_qty?:float,yield_unit?:string,sell_vat_rate?:float,target_margin_pct?:float,notes?:?string,active?:bool,lines:array} $data */
    public function save(array $data, ?Recipe $recipe = null): Recipe
    {
        return DB::transaction(function () use ($data, $recipe) {
            $kind = $data['kind'] ?? $recipe?->kind ?? 'dish';
            $menuId = $kind === 'dish' ? ($data['pos_menu_item_id'] ?? $recipe?->pos_menu_item_id) : null;
            if ($menuId) {
                MenuItem::findOrFail($menuId);
                if (Recipe::where('pos_menu_item_id', $menuId)->where('active', true)->when($recipe, fn ($q) => $q->where('id', '!=', $recipe->id))->exists()) {
                    throw new DomainException('This menu item already has an active recipe.', 'recipe-duplicate');
                }
            }
            $fields = collect($data)->only(['code', 'name', 'yield_qty', 'yield_unit', 'sell_vat_rate', 'target_margin_pct', 'notes', 'active'])->all() + ['kind' => $kind, 'pos_menu_item_id' => $menuId];
            if (($fields['yield_qty'] ?? 1) <= 0) {
                throw new DomainException('Yield must be positive.', 'recipe-invalid');
            }
            if ($recipe) {
                $recipe->update($fields);
                $recipe->lines()->delete();
            } else {
                $recipe = Recipe::create($fields);
            }
            foreach (array_values($data['lines'] ?? []) as $i => $l) {
                $hasItem = ! empty($l['stock_item_id']);
                $hasSub = ! empty($l['sub_recipe_id']);
                if ($hasItem === $hasSub || ($l['quantity'] ?? 0) <= 0) {
                    throw new DomainException('Each line needs one ingredient or one sub-recipe and a positive quantity.', 'recipe-invalid');
                }
                if ($hasItem) {
                    $item = StockItem::findOrFail($l['stock_item_id']);
                    $this->uom->factor($item, $l['unit'] ?? null); // throws if no conversion exists
                }
                if ($hasSub) {
                    $sub = Recipe::findOrFail($l['sub_recipe_id']);
                    if ($sub->kind !== 'sub_recipe') {
                        throw new DomainException('Only sub-recipes can be used as ingredients.', 'recipe-invalid');
                    }
                }
                $recipe->lines()->create([
                    'stock_item_id' => $l['stock_item_id'] ?? null, 'sub_recipe_id' => $l['sub_recipe_id'] ?? null, 'quantity' => $l['quantity'],
                    'unit' => $l['unit'] ?? ($hasSub ? null : null), 'yield_pct' => $l['yield_pct'] ?? 100, 'sort' => $i,
                ]);
            }
            $this->assertNoCycle($recipe->fresh());
            $this->recost($recipe->fresh(), 'edit');

            return $recipe->fresh('lines');
        });
    }

    /** Stock quantities (in stock units) needed to make $batches of the recipe. @return array<int,float> item id => quantity */
    public function explode(Recipe $recipe, float $batches = 1.0, array $stack = []): array
    {
        if (in_array($recipe->id, $stack, true)) {
            throw new DomainException("Recipe {$recipe->code} contains itself.", 'recipe-cycle');
        }
        $stack[] = $recipe->id;
        $out = [];
        foreach ($recipe->lines()->get() as $l) {
            $usable = max(0.01, (float) $l->yield_pct) / 100;
            if ($l->stock_item_id) {
                $item = StockItem::findOrFail($l->stock_item_id);
                $q = $this->uom->toStockUnit($item, (float) $l->quantity, $l->unit) / $usable * $batches;
                $out[$item->id] = ($out[$item->id] ?? 0) + $q;
            } else {
                $sub = Recipe::findOrFail($l->sub_recipe_id);
                foreach ($this->explode($sub, (float) $l->quantity / $usable / max(0.0001, (float) $sub->yield_qty) * $batches, $stack) as $id => $q) {
                    $out[$id] = ($out[$id] ?? 0) + $q;
                }
            }
        }

        return $out;
    }

    /** Cost of one yield unit (one portion for a dish) at current weighted-average costs. */
    public function unitCost(Recipe $recipe): float
    {
        $total = 0.0;
        foreach ($this->explode($recipe) as $itemId => $qty) {
            $total += $qty * (float) StockItem::findOrFail($itemId)->avg_cost;
        }

        return $total / max(0.0001, (float) $recipe->yield_qty);
    }

    /** @return array{plate_cost:float,price:int,net_price:float,margin_pct:?float,food_cost_pct:?float,target_margin_pct:float,below_target:bool,lines:array} */
    public function analysis(Recipe $recipe): array
    {
        $cost = $this->unitCost($recipe);
        $price = $recipe->pos_menu_item_id ? (int) (MenuItem::find($recipe->pos_menu_item_id)?->price ?? 0) : 0;
        $net = $price / (1 + (float) $recipe->sell_vat_rate / 100);
        $margin = $net > 0 ? round(($net - $cost) / $net * 100, 2) : null;
        $lines = [];
        foreach ($recipe->lines()->get() as $l) {
            $usable = max(0.01, (float) $l->yield_pct) / 100;
            if ($l->stock_item_id) {
                $item = StockItem::findOrFail($l->stock_item_id);
                $c = $this->uom->toStockUnit($item, (float) $l->quantity, $l->unit) / $usable * (float) $item->avg_cost / max(0.0001, (float) $recipe->yield_qty);
                $lines[] = ['id' => $l->id, 'kind' => 'item', 'name' => $item->name, 'quantity' => (float) $l->quantity, 'unit' => $l->unit ?: $item->unit, 'yield_pct' => (float) $l->yield_pct, 'cost' => round($c, 4)];
            } else {
                $sub = Recipe::findOrFail($l->sub_recipe_id);
                $c = (float) $l->quantity / $usable * $this->unitCost($sub) / max(0.0001, (float) $recipe->yield_qty);
                $lines[] = ['id' => $l->id, 'kind' => 'sub_recipe', 'name' => $sub->name, 'quantity' => (float) $l->quantity, 'unit' => $sub->yield_unit, 'yield_pct' => (float) $l->yield_pct, 'cost' => round($c, 4)];
            }
        }

        return [
            'plate_cost' => round($cost, 4), 'price' => $price, 'net_price' => round($net, 2), 'margin_pct' => $margin,
            'food_cost_pct' => $net > 0 ? round($cost / $net * 100, 2) : null, 'target_margin_pct' => (float) $recipe->target_margin_pct,
            'below_target' => $margin !== null && $margin < (float) $recipe->target_margin_pct, 'lines' => $lines,
        ];
    }

    /** Store the current plate cost; log history and raise events when it moved. */
    public function recost(Recipe $recipe, string $cause = 'recost'): Recipe
    {
        $a = $this->analysis($recipe);
        $changed = abs($a['plate_cost'] - (float) $recipe->plate_cost) > 0.00005;
        $recipe->forceFill(['plate_cost' => $a['plate_cost'], 'costed_at' => now()])->save();
        if ($changed || ! DB::table('recipe_cost_history')->where('recipe_id', $recipe->id)->exists()) {
            $hotel = Hotel::findOrFail($this->ctx->requireHotelId());
            DB::table('recipe_cost_history')->insert([
                'hotel_id' => $hotel->id, 'recipe_id' => $recipe->id, 'date' => $hotel->business_date, 'plate_cost' => $a['plate_cost'],
                'net_price' => (int) round($a['net_price']), 'margin_pct' => $a['margin_pct'], 'cause' => $cause, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->bus->record('stock.recipe_costed', ['recipe_id' => $recipe->id, 'code' => $recipe->code, 'plate_cost' => $a['plate_cost'], 'margin_pct' => $a['margin_pct'], 'cause' => $cause], 'recipe', $recipe->id);
            if ($a['below_target'] && $recipe->kind === 'dish') {
                $this->bus->record('stock.recipe_margin_low', ['recipe_id' => $recipe->id, 'code' => $recipe->code, 'name' => $recipe->name, 'margin_pct' => $a['margin_pct'], 'target_margin_pct' => $a['target_margin_pct']], 'recipe', $recipe->id);
            }
        }

        return $recipe;
    }

    /** Called when an ingredient's average cost moved: re-cost every recipe that uses it, directly or through sub-recipes. */
    public function recostForItem(int $itemId): int
    {
        $n = 0;
        foreach (Recipe::where('active', true)->get() as $r) {
            if (array_key_exists($itemId, $this->explode($r))) {
                $this->recost($r, 'ingredient_cost');
                $n++;
            }
        }

        return $n;
    }

    public function recipeFor(int $menuItemId): ?Recipe
    {
        return Recipe::where('pos_menu_item_id', $menuItemId)->where('active', true)->first();
    }

    /**
     * Consume the ingredients of $qty portions sold. Returns false when the menu item has no recipe,
     * so the caller can fall back to the flat bill of materials (pos_item_components).
     */
    public function consumeSale(int $menuItemId, float $qty, Store $store, array $meta): bool
    {
        $recipe = $this->recipeFor($menuItemId);
        if (! $recipe) {
            return false;
        }
        foreach ($this->explode($recipe, $qty / max(0.0001, (float) $recipe->yield_qty)) as $itemId => $q) {
            $item = StockItem::find($itemId);
            if ($item && $q > 0) {
                $this->stock->consume('sale', $item, $store, $q, $meta);
            }
        }

        return true;
    }

    private function assertNoCycle(Recipe $recipe): void
    {
        $this->explode($recipe); // throws recipe-cycle
    }
}
