<?php

namespace App\Modules\Stock\Application;

use App\Modules\POS\Domain\Check;
use App\Modules\POS\Domain\ItemComponent;
use App\Modules\Stock\Control\Application\RecipeService;
use App\Modules\Stock\Domain\StockItem;
use App\Modules\Stock\Domain\Store;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Domain\DomainException;

/**
 * Before a POS check is settled: does the outlet's store hold what the sale will consume
 * (recipe, else flat bill of materials)? Same rules as the stock.pos_deduction subscriber.
 */
class PosStockAvailability
{
    public function __construct(private StockService $stock, private RecipeService $recipes) {}

    /** @param array<int, array{pos_menu_item_id:int, quantity:float|string}> $lines @return array<int,float> stock item id => quantity */
    public function requirements(array $lines): array
    {
        $need = [];
        foreach ($lines as $line) {
            $qty = (float) $line['quantity'];
            $recipe = $this->recipes->recipeFor((int) $line['pos_menu_item_id']);
            if ($recipe) {
                foreach ($this->recipes->explode($recipe, $qty / max(0.0001, (float) $recipe->yield_qty)) as $id => $q) {
                    $need[$id] = ($need[$id] ?? 0) + $q;
                }
                continue;
            }
            foreach (ItemComponent::where('pos_menu_item_id', $line['pos_menu_item_id'])->get() as $c) {
                $need[$c->stock_item_id] = ($need[$c->stock_item_id] ?? 0) + (float) $c->quantity * $qty;
            }
        }

        return $need;
    }

    public function assertAvailable(Check $check): void
    {
        $hotel = Hotel::find($check->hotel_id);
        if ($hotel && ($hotel->settings['allow_negative_stock'] ?? false)) {
            return;
        }
        $store = Store::where('outlet_id', $check->outlet_id)->where('active', true)->first();
        if (! $store) {
            return; // outlet not linked to a store: no deduction happens
        }
        $lines = $check->lines()->where('voided', false)->get(['pos_menu_item_id', 'quantity'])->toArray();
        $short = [];
        foreach ($this->requirements($lines) as $itemId => $q) {
            $onHand = $this->stock->onHand($store->id, $itemId);
            if ($q > 0 && $onHand + 0.00001 < $q) {
                $item = StockItem::find($itemId);
                $short[] = sprintf('%s (needs %s %s, on hand %s)', $item?->name ?? "#$itemId", self::num($q), $item?->unit, self::num($onHand));
            }
        }
        if ($short) {
            $outlet = $check->outlet?->name ?? 'this outlet';
            throw new DomainException(
                "Cannot settle: store \"{$store->name}\" ({$store->code}) of {$outlet} does not hold enough ".implode('; ', $short)
                .". Receive stock into \"{$store->name}\" or transfer it from another store (Stock > Transfers), then settle again. The check stays open.",
                'insufficient-stock'
            );
        }
    }

    private static function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.') ?: '0';
    }
}
