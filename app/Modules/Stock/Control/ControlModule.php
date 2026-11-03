<?php

namespace App\Modules\Stock\Control;

use App\Modules\Stock\Control\Application\LotService;
use App\Modules\Stock\Control\Application\RecipeService;
use App\Modules\Stock\Control\Console\ExpiryAlertsCommand;
use App\Platform\Events\EventBus;
use App\Platform\Events\StoredEvent;
use Illuminate\Console\Application as Artisan;

/** Event wiring for inventory control; registered from StockModule::subscribe. */
class ControlModule
{
    public static function subscribe(EventBus $bus): void
    {
        // Lots follow the stock ledger: receipt creates a lot, consumption is FEFO, transfers carry lots.
        $bus->subscribe('stock.movement_posted', 'stock.lots', function (StoredEvent $e) {
            app(LotService::class)->onMovement((int) $e->payload['id']);
        });

        // A new average cost re-costs every recipe that uses the ingredient and flags margins under target.
        $bus->subscribe('stock.movement_posted', 'stock.recipe_costing', function (StoredEvent $e) {
            if (($e->payload['kind'] ?? null) === 'receipt') {
                app(RecipeService::class)->recostForItem((int) $e->payload['item_id']);
            }
        });

        Artisan::starting(fn ($artisan) => $artisan->resolveCommands([ExpiryAlertsCommand::class]));
    }
}
