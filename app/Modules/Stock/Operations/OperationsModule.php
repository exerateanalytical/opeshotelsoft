<?php

namespace App\Modules\Stock\Operations;

use App\Modules\Stock\Operations\Application\MinibarService;
use App\Platform\Events\EventBus;
use App\Platform\Events\StoredEvent;

/** Event wiring for inventory operations (I-B); registered from StockModule::subscribe. */
class OperationsModule
{
    public static function subscribe(EventBus $bus): void
    {
        // A departure generates the minibar restock task for the room.
        $bus->subscribe('frontoffice.guest_checked_out', 'stock.minibar_restock_task', function (StoredEvent $e) {
            if ($e->hotel_id && ! empty($e->payload['room_id'])) {
                app(MinibarService::class)->onCheckout($e->payload);
            }
        });
    }
}
