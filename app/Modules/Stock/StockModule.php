<?php

namespace App\Modules\Stock;

use App\Modules\POS\Domain\ItemComponent;
use App\Modules\Stock\Application\StockService;
use App\Modules\Stock\Domain\StockItem;
use App\Modules\Stock\Domain\Store;
use App\Platform\Events\EventBus;
use App\Platform\Events\StoredEvent;

class StockModule
{
    public static function subscribe(EventBus $bus): void
    {
        // POS sales deduct their bill of materials from the outlet's store.
        $bus->subscribe('pos.check_settled', 'stock.pos_deduction', function (StoredEvent $e) {
            $p = $e->payload;
            $store = Store::where('outlet_id', $p['outlet_id'])->where('active', true)->first();
            if (! $store) {
                return;
            }
            $service = app(StockService::class);
            foreach ($p['items'] as $line) {
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
    }
}
