<?php

namespace Tests\Feature\Stock;

use App\Modules\POS\Domain\MenuItem;
use App\Modules\Property\Domain\Outlet;
use App\Modules\Stock\Application\StockService;
use App\Modules\Stock\Domain\StockItem;
use App\Modules\Stock\Domain\Store;
use App\Modules\Stock\Domain\Supplier;
use Tests\TestCase;

/** Shared fixtures for the I-A inventory control tests: a restaurant outlet with its store, a main store, and a few ingredients. */
abstract class ControlTestCase extends TestCase
{
    protected Outlet $restaurant;

    protected Store $main;

    protected Store $kitchen;

    protected function setUp(): void
    {
        parent::setUp();
        $this->restaurant = Outlet::create(['code' => 'RES', 'name' => 'Restaurant', 'kind' => 'restaurant']);
        $this->main = Store::create(['code' => 'MAIN', 'name' => 'Magasin central', 'kind' => 'main']);
        $this->kitchen = Store::create(['code' => 'KIT', 'name' => 'Cuisine', 'kind' => 'kitchen', 'department' => 'food', 'outlet_id' => $this->restaurant->id]);
    }

    protected function item(string $code, string $name, string $unit = 'kg', array $extra = []): StockItem
    {
        return StockItem::create($extra + [
            'code' => $code, 'name' => $name, 'category' => 'food', 'unit' => $unit, 'inventory_account' => '311100', 'cost_account' => '603110',
        ]);
    }

    protected function receive(StockItem $item, Store $store, float $qty, float $cost, array $meta = [])
    {
        return app(StockService::class)->receive($item, $store, $qty, $cost, $meta);
    }

    protected function dish(string $code, int $price): MenuItem
    {
        return MenuItem::create(['code' => $code, 'name' => $code, 'price' => $price, 'charge_code' => 'FNB', 'kitchen_station' => 'kitchen']);
    }

    protected function supplier(string $code, ?string $name = null): Supplier
    {
        return Supplier::create(['code' => $code, 'name' => $name ?? $code]);
    }

    protected function sell(MenuItem $menu, int $qty, int $amount): array
    {
        $this->postJson('/api/v1/cashier-shifts');
        $check = $this->postJson('/api/v1/pos/checks', ['outlet_id' => $this->restaurant->id])->assertCreated()->json();
        $this->postJson("/api/v1/pos/checks/{$check['id']}/items", ['menu_item_id' => $menu->id, 'quantity' => $qty])->assertCreated();
        $this->postJson("/api/v1/pos/checks/{$check['id']}/settle", ['payments' => [['method' => 'CASH', 'amount' => $amount]]])->assertOk();

        return $check;
    }

    protected function onHand(Store $store, StockItem $item): float
    {
        return app(StockService::class)->onHand($store->id, $item->id);
    }
}
