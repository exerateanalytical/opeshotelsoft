<?php

namespace Tests\Feature\POS;

use App\Modules\POS\Domain\Check;
use App\Modules\POS\Domain\MenuItem;
use App\Modules\Property\Domain\Outlet;
use App\Modules\Stock\Application\StockService;
use App\Modules\Stock\Domain\StockItem;
use App\Modules\Stock\Domain\Store;
use Database\Seeders\DemoPosStockSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The demo POS/stock seed makes the restaurant and the bar sellable end to end; a short store is refused before settlement. */
class DemoPosStockFlowTest extends TestCase
{
    private Outlet $rest;

    private Outlet $bar;

    private Store $kit;

    private Store $bars;

    protected function setUp(): void
    {
        parent::setUp();
        // The demo hotel shape (DemoSeeder / Fast Configure): outlets each with their own store, a menu without stock in those stores.
        $this->rest = Outlet::create(['code' => 'REST', 'name' => 'Restaurant Le Wouri', 'kind' => 'restaurant']);
        $this->bar = Outlet::create(['code' => 'BAR', 'name' => 'Lobby Bar', 'kind' => 'bar']);
        Store::create(['code' => 'MAIN', 'name' => 'Magasin central', 'kind' => 'main']);
        $this->kit = Store::create(['code' => 'KIT', 'name' => 'Cuisine', 'kind' => 'kitchen', 'department' => 'fnb', 'outlet_id' => $this->rest->id]);
        $this->bars = Store::create(['code' => 'BARS', 'name' => 'Stock bar', 'kind' => 'bar', 'department' => 'beverage', 'outlet_id' => $this->bar->id]);
        foreach ([['B65', 'Bière pression 65cl', 2000, 'BAR', 'bar'], ['SODA', 'Soda', 1000, 'BAR', 'bar'], ['WHISKY', 'Whisky 4cl', 4000, 'BAR', 'bar'],
            ['POULETDG', 'Poulet DG', 7500, 'FNB', 'kitchen'], ['NDOLE', 'Ndolè crevettes', 8500, 'FNB', 'kitchen'], ['OMELETTE', 'Omelette', 3000, 'FNB', 'kitchen']] as [$c, $n, $p, $cc, $st]) {
            MenuItem::create(['code' => $c, 'name' => $n, 'price' => $p, 'charge_code' => $cc, 'kitchen_station' => $st]);
        }
        $approver = $this->makeUser($this->group, 'general-manager', allHotels: true);
        (new DemoPosStockSeeder)->seedHotel($this->hotel, $this->user, $approver);
        $this->actAs($this->user, $this->hotel);
    }

    public function test_seed_covers_every_menu_component_and_fills_the_gaps(): void
    {
        $this->assertSame(0, MenuItem::whereDoesntHave('components')->whereNotIn('id', DB::table('recipes')->whereNotNull('pos_menu_item_id')->pluck('pos_menu_item_id'))->count());
        $this->assertGreaterThanOrEqual(8, DB::table('pos_tables')->where('outlet_id', $this->rest->id)->count());
        $this->assertGreaterThanOrEqual(4, DB::table('pos_tables')->where('outlet_id', $this->bar->id)->count());
        $this->assertSame(1, DB::table('stocktakes')->where('status', 'posted')->count());
        $this->assertTrue(DB::table('stock_lots')->whereNotNull('expiry_date')->exists());
        $this->assertTrue(DB::table('par_levels')->exists() && DB::table('supplier_prices')->exists() && DB::table('pos_price_lists')->exists());

        // Re-running is harmless: nothing more is received.
        $receipts = DB::table('stock_movements')->where('kind', 'receipt')->count();
        (new DemoPosStockSeeder)->seedHotel($this->hotel, $this->user, $this->makeUser($this->group, 'general-manager', allHotels: true));
        $this->assertSame($receipts, DB::table('stock_movements')->where('kind', 'receipt')->count());
        $this->assertBooksBalance();
    }

    public function test_restaurant_full_flow_with_split_tender(): void
    {
        $this->flow($this->rest, $this->kit, ['POULETDG' => 2, 'SODA' => 3, 'B65' => 1], ['kitchen', 'bar']);
    }

    public function test_bar_full_flow_with_split_tender(): void
    {
        $this->flow($this->bar, $this->bars, ['B65' => 4, 'WHISKY' => 2, 'OMELETTE' => 1], ['bar', 'kitchen']);
    }

    private function flow(Outlet $outlet, Store $store, array $order, array $stations): void
    {
        $this->postJson('/api/v1/cashier-shifts')->assertCreated();
        $floor = $this->getJson("/api/v1/pos/outlets/{$outlet->id}/floor")->assertOk()->json();
        $this->assertNotEmpty($floor['tables']);
        $check = $this->postJson("/api/v1/pos/tables/{$floor['tables'][0]['id']}/check")->assertOk()->json();

        $stock = app(StockService::class);
        $avail = app(\App\Modules\Stock\Application\PosStockAvailability::class);
        $lines = [];
        foreach ($order as $code => $qty) {
            $id = MenuItem::where('code', $code)->value('id');
            $this->postJson("/api/v1/pos/checks/{$check['id']}/items", ['menu_item_id' => $id, 'quantity' => $qty])->assertCreated();
            $lines[] = ['pos_menu_item_id' => $id, 'quantity' => $qty];
        }
        $need = $avail->requirements($lines);
        $before = collect($need)->mapWithKeys(fn ($q, $id) => [$id => $stock->onHand($store->id, $id)]);

        $fired = $this->postJson("/api/v1/pos/checks/{$check['id']}/fire")->assertOk()->json('stations');
        $this->assertEqualsCanonicalizing($stations, $fired);
        $this->getJson("/api/v1/pos/checks/{$check['id']}/tickets")->assertOk()->assertJsonCount(count($stations), 'tickets');

        $total = Check::find($check['id'])->total;
        $cash = intdiv($total, 3);
        $this->postJson("/api/v1/pos/checks/{$check['id']}/settle", ['payments' => [
            ['method' => 'CASH', 'amount' => $cash, 'tendered' => $cash + 500],
            ['method' => 'CARD', 'amount' => $total - $cash, 'reference' => 'AUTH123'],
        ]])->assertOk()->assertJsonPath('status', 'settled')->assertJsonCount(2, 'payments');

        foreach ($need as $itemId => $q) {
            $this->assertEqualsWithDelta($before[$itemId] - $q, $stock->onHand($store->id, $itemId), 0.001, 'stock of item '.StockItem::find($itemId)->code);
        }
        $this->assertSame(count($need), DB::table('stock_movements')->where('source_type', 'pos_check')->where('source_id', (string) $check['id'])->distinct()->count('stock_item_id'));

        $b = $this->assertBooksBalance();
        $this->assertSame($cash, $b['571100'] ?? null, 'cash drawer');
        $this->assertGreaterThan(0, ($b['603110'] ?? 0) + ($b['603120'] ?? 0), 'cost of sales');

        $report = $this->getJson('/api/v1/pos/reports/day?date='.$this->hotel->fresh()->business_date->toDateString()."&outlet_id={$outlet->id}")->assertOk()->json();
        $this->assertSame(1, $report['checks']);
        $this->assertSame($total, $report['net']);
    }

    public function test_short_store_is_refused_with_an_actionable_message_and_the_check_stays_open(): void
    {
        $caviar = StockItem::create(['code' => 'CAVIAR', 'name' => 'Caviar', 'category' => 'food', 'unit' => 'g', 'inventory_account' => '311100', 'cost_account' => '603110']);
        $dish = MenuItem::create(['code' => 'BLINI', 'name' => 'Blinis caviar', 'price' => 25000, 'charge_code' => 'FNB', 'kitchen_station' => 'kitchen']);
        $dish->components()->create(['stock_item_id' => $caviar->id, 'quantity' => 30]);

        $this->postJson('/api/v1/cashier-shifts')->assertCreated();
        $check = $this->postJson('/api/v1/pos/checks', ['outlet_id' => $this->rest->id])->assertCreated()->json();
        $this->postJson("/api/v1/pos/checks/{$check['id']}/items", ['menu_item_id' => $dish->id])->assertCreated();
        $total = Check::find($check['id'])->total;

        $r = $this->postJson("/api/v1/pos/checks/{$check['id']}/settle", ['payments' => [['method' => 'CASH', 'amount' => $total]]])->assertStatus(422);
        $detail = $r->json('detail') ?? $r->json('message');
        $this->assertStringContainsString('Cuisine', $detail);
        $this->assertStringContainsString('Caviar', $detail);
        $this->assertStringContainsString('Receive stock', $detail);

        $this->assertSame('open', Check::find($check['id'])->status);
        $this->assertSame(0, DB::table('pos_payments')->where('pos_check_id', $check['id'])->count());
        $this->assertFalse(DB::table('domain_events')->where('type', 'pos.check_settled')->where('aggregate_id', (string) $check['id'])->exists());
    }
}
