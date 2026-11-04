<?php

namespace App\Modules\Stock;

use App\Modules\POS\Application\SettlementGuards;
use App\Modules\POS\Domain\Check;
use App\Modules\POS\Domain\ItemComponent;
use App\Modules\Stock\Application\PosStockAvailability;
use App\Modules\Stock\Application\StockService;
use App\Modules\Stock\Control\Application\RecipeService;
use App\Modules\Stock\Control\ControlModule;
use App\Modules\Stock\Domain\StockItem;
use App\Modules\Stock\Domain\Store;
use App\Modules\Stock\Operations\OperationsModule;
use App\Platform\Events\EventBus;
use App\Platform\Events\StoredEvent;

class StockModule
{
    public static function subscribe(EventBus $bus): void
    {
        // Refuse to close a check whose bill of materials the outlet's store cannot cover (strict stock policy),
        // inside the settle transaction: the deduction below runs after commit and could not undo the settlement.
        SettlementGuards::register('stock.availability', fn (Check $check) => app(PosStockAvailability::class)->assertAvailable($check));

        // POS sales deduct their bill of materials from the outlet's store.
        $bus->subscribe('pos.check_settled', 'stock.pos_deduction', function (StoredEvent $e) {
            $p = $e->payload;
            $store = Store::where('outlet_id', $p['outlet_id'])->where('active', true)->first();
            if (! $store) {
                return;
            }
            $service = app(StockService::class);
            $recipes = app(RecipeService::class);
            foreach ($p['items'] as $line) {
                // I-A: a dish with a recipe consumes through it (yield %, sub-recipes); otherwise the flat bill of materials applies.
                if ($recipes->consumeSale($line['pos_menu_item_id'], (float) $line['quantity'], $store, [
                    'reference' => "POS {$p['number']}", 'source_type' => 'pos_check', 'source_id' => $p['id'], 'date' => $p['business_date'],
                ])) {
                    continue;
                }
                foreach (ItemComponent::where('pos_menu_item_id', $line['pos_menu_item_id'])->get() as $c) {
                    $item = StockItem::find($c->stock_item_id);
                    if ($item) {
                        $service->consume('sale', $item, $store, $c->quantity * (float) $line['quantity'], [
                            'reference' => "POS {$p['number']}", 'source_type' => 'pos_check', 'source_id' => $p['id'], 'date' => $p['business_date'],
                        ]);
                    }
                }
            }
        });

        ControlModule::subscribe($bus);
        OperationsModule::subscribe($bus);
    }
}
