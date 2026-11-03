<?php

namespace Tests\Feature\Operations;

use App\Modules\FrontOffice\Application\FrontDeskService;
use App\Modules\POS\Domain\Check;
use App\Modules\POS\Domain\MenuItem;
use App\Modules\Property\Domain\Outlet;
use App\Modules\Property\Domain\Room;
use App\Modules\Reservations\Application\ReservationService;
use App\Modules\Stock\Domain\GoodsReceipt;
use App\Modules\Stock\Domain\StockItem;
use App\Modules\Stock\Domain\Store;
use App\Modules\Stock\Domain\Supplier;
use Tests\TestCase;

class PosAndStockTest extends TestCase
{
    private Outlet $bar;

    private Store $main;

    private Store $barStore;

    private StockItem $beer;

    private MenuItem $beerItem;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bar = Outlet::create(['code' => 'BAR', 'name' => 'Lobby Bar', 'kind' => 'bar']);
        $this->main = Store::create(['code' => 'MAIN', 'name' => 'Magasin central', 'kind' => 'main']);
        $this->barStore = Store::create(['code' => 'BARS', 'name' => 'Stock bar', 'kind' => 'bar', 'department' => 'beverage', 'outlet_id' => $this->bar->id]);
        $this->beer = StockItem::create([
            'code' => 'BEER33', 'name' => '33 Export 65cl', 'category' => 'beverage', 'unit' => 'btl', 'purchase_unit' => 'case',
            'purchase_factor' => 12, 'inventory_account' => '311200', 'cost_account' => '603120', 'reorder_level' => 5,
        ]);
        $this->beerItem = MenuItem::create(['code' => 'B33', 'name' => '33 Export', 'price' => 2_385, 'charge_code' => 'BAR', 'kitchen_station' => 'bar']);
        $this->beerItem->components()->create(['stock_item_id' => $this->beer->id, 'quantity' => 1]);
    }

    private function receiveBeer(int $cases = 2, int $casePrice = 7_200): void
    {
        $supplier = Supplier::create(['code' => 'SABC', 'name' => 'SABC Brasseries']);
        $po = $this->postJson('/api/v1/purchasing/orders', [
            'supplier_id' => $supplier->id, 'store_id' => $this->main->id,
            'lines' => [['stock_item_id' => $this->beer->id, 'quantity' => $cases, 'unit_price' => $casePrice]],
        ])->assertCreated()->json();
        $this->postJson("/api/v1/purchasing/orders/{$po['id']}/submit")->assertOk();

        // The requester cannot approve their own order.
        $this->postJson("/api/v1/purchasing/orders/{$po['id']}/approve")->assertStatus(422);
        $approver = $this->makeUser($this->group, 'finance-director', allHotels: true);
        $this->actAs($approver, $this->hotel)->postJson("/api/v1/purchasing/orders/{$po['id']}/approve")->assertOk();
        $this->actAs($this->user, $this->hotel);

        $this->postJson("/api/v1/purchasing/orders/{$po['id']}/receive", [
            'lines' => [['purchase_order_line_id' => $po['lines'][0]['id'], 'quantity' => $cases]],
        ])->assertCreated();
        $this->postJson('/api/v1/stock/transfers', [
            'from_store_id' => $this->main->id, 'to_store_id' => $this->barStore->id,
            'lines' => [['stock_item_id' => $this->beer->id, 'quantity' => 12 * $cases]],
        ])->assertCreated();
    }

    public function test_purchase_receive_and_pos_sale_flow_through_stock_and_ledger(): void
    {
        $this->receiveBeer(cases: 2, casePrice: 7_200); // 24 btl at 600

        $this->postJson('/api/v1/cashier-shifts')->assertCreated();
        $check = $this->postJson('/api/v1/pos/checks', ['outlet_id' => $this->bar->id, 'table_number' => '4'])->assertCreated()->json();
        $this->postJson("/api/v1/pos/checks/{$check['id']}/items", ['menu_item_id' => $this->beerItem->id, 'quantity' => 3])->assertCreated();
        $this->postJson("/api/v1/pos/checks/{$check['id']}/fire")->assertOk()->assertJsonPath('stations', ['bar']);
        $this->postJson("/api/v1/pos/checks/{$check['id']}/settle", ['payments' => [['method' => 'CASH', 'amount' => 7_155, 'tendered' => 10_000]]])
            ->assertOk()->assertJsonPath('status', 'settled');

        $bal = collect($this->getJson("/api/v1/stock/balances?store_id={$this->barStore->id}")->json())->firstWhere('code', 'BEER33');
        $this->assertEquals(21, $bal['quantity']);

        $b = $this->assertBooksBalance();
        $this->assertSame(7_155, $b['571100'], 'cash');
        $this->assertSame(-6_000, $b['706300'], 'net bar revenue');
        $this->assertSame(-1_155, $b['443100'], 'VAT');
        $this->assertSame(1_800, $b['603120'], 'cost of 3 beers');
        $this->assertSame(12_600, $b['311200'], '21 beers in stock');
        $this->assertSame(-14_400, $b['408100'], 'goods received not invoiced');
    }

    public function test_charge_to_room_posts_to_guest_folio(): void
    {
        [$type, $plan, $guest] = $this->seedInventory();
        $guest->update(['id_number' => 'X1']);
        $r = app(ReservationService::class)->create([
            'guest_id' => $guest->id, 'room_type_id' => $type->id, 'rate_plan_id' => $plan->id,
            'arrival' => now()->toDateString(), 'departure' => now()->addDay()->toDateString(),
            'room_id' => Room::first()->id,
        ]);
        app(FrontDeskService::class)->checkIn($r);
        $this->receiveBeer(1);

        $check = $this->postJson('/api/v1/pos/checks', ['outlet_id' => $this->bar->id])->json();
        $this->postJson("/api/v1/pos/checks/{$check['id']}/items", ['menu_item_id' => $this->beerItem->id, 'quantity' => 2])->assertCreated();
        $this->postJson("/api/v1/pos/checks/{$check['id']}/charge-to-room", ['reservation_id' => $r->id])->assertOk();

        $folio = $this->getJson("/api/v1/reservations/{$r->id}/folio")->json();
        $this->assertSame(4_770, $folio['balance']);
        $b = $this->assertBooksBalance();
        $this->assertSame(4_770, $b['411100']);
    }

    public function test_stock_cannot_go_negative_and_count_posts_variance(): void
    {
        $this->receiveBeer(1); // 12 in bar

        $this->postJson('/api/v1/stock/issues', ['store_id' => $this->barStore->id, 'lines' => [['stock_item_id' => $this->beer->id, 'quantity' => 50]]])
            ->assertStatus(422)->assertJsonPath('type', 'https://docs.opeshotelsoft.test/problems/insufficient-stock');

        $count = $this->postJson('/api/v1/stock/counts', ['store_id' => $this->barStore->id])->assertCreated()->json();
        $this->putJson("/api/v1/stock/counts/{$count['id']}", ['lines' => [['stock_item_id' => $this->beer->id, 'counted' => 10]]])->assertOk();
        $this->postJson("/api/v1/stock/counts/{$count['id']}/post")->assertOk();

        $b = $this->assertBooksBalance();
        $this->assertSame(1_200, $b['603900'], 'two missing bottles at 600');
    }

    public function test_supplier_invoice_three_way_match_and_payment(): void
    {
        $this->receiveBeer(2); // GRN 14 400 net
        $supplier = Supplier::first();
        $grnId = GoodsReceipt::first()->id;

        $this->postJson('/api/v1/payables/invoices', [
            'supplier_id' => $supplier->id, 'reference' => 'F-999', 'date' => now()->toDateString(),
            'net_total' => 20_000, 'tax_total' => 3_850, 'goods_receipt_ids' => [$grnId],
        ])->assertStatus(422)->assertJsonPath('type', 'https://docs.opeshotelsoft.test/problems/match-failed');

        $inv = $this->postJson('/api/v1/payables/invoices', [
            'supplier_id' => $supplier->id, 'reference' => 'F-1001', 'date' => now()->toDateString(),
            'net_total' => 14_400, 'tax_total' => 2_772, 'goods_receipt_ids' => [$grnId],
        ])->assertCreated()->json();
        $this->postJson("/api/v1/payables/invoices/{$inv['id']}/approve")->assertOk();
        $this->postJson("/api/v1/payables/invoices/{$inv['id']}/pay", ['amount' => 17_172, 'account' => '521100'])->assertOk()->assertJsonPath('status', 'paid');

        $b = $this->assertBooksBalance();
        $this->assertSame(0, $b['408100'] ?? 0, 'accrual cleared');
        $this->assertSame(0, $b['401100'] ?? 0, 'supplier paid');
        $this->assertSame(2_772, $b['445200'], 'deductible VAT');
        $this->assertSame(-17_172, $b['521100'], 'bank');
    }

    public function test_offline_pos_sync_is_idempotent(): void
    {
        $this->receiveBeer(1);
        $this->postJson('/api/v1/cashier-shifts')->assertCreated();
        $batch = ['checks' => [[
            'client_uuid' => '7a1f4c2e-9b3d-4e5f-8a6b-1c2d3e4f5a6b', 'outlet_id' => $this->bar->id,
            'items' => [['menu_item_id' => $this->beerItem->id, 'quantity' => 1]],
            'payments' => [['method' => 'CASH', 'amount' => 2_385]],
        ]]];

        $this->postJson('/api/v1/pos/sync', $batch)->assertOk()->assertJsonPath('results.0.status', 'synced');
        $this->postJson('/api/v1/pos/sync', $batch)->assertOk()->assertJsonPath('results.0.status', 'already_synced');
        $this->assertSame(1, Check::count());
    }

    public function test_void_requires_supervisor(): void
    {
        $cashier = $this->makeUser($this->group, 'pos-cashier', hotels: [$this->hotel]);
        $this->actAs($cashier, $this->hotel);
        $check = $this->postJson('/api/v1/pos/checks', ['outlet_id' => $this->bar->id])->json();
        $line = $this->postJson("/api/v1/pos/checks/{$check['id']}/items", ['menu_item_id' => $this->beerItem->id])->json();

        $this->postJson("/api/v1/pos/checks/{$check['id']}/lines/{$line['id']}/void", ['reason' => 'x'])->assertForbidden();
    }

    public function test_cashier_can_edit_and_remove_unfired_lines_and_books_balance(): void
    {
        $this->receiveBeer(1); // 12 btl
        $cashier = $this->makeUser($this->group, 'pos-cashier', hotels: [$this->hotel]);
        $this->actAs($cashier, $this->hotel);
        $this->postJson('/api/v1/cashier-shifts')->assertCreated();
        $check = $this->postJson('/api/v1/pos/checks', ['outlet_id' => $this->bar->id])->json();
        $url = "/api/v1/pos/checks/{$check['id']}";
        $keep = $this->postJson("$url/items", ['menu_item_id' => $this->beerItem->id, 'notes' => 'very cold'])->assertCreated()->json();
        $drop = $this->postJson("$url/items", ['menu_item_id' => $this->beerItem->id, 'quantity' => 5])->assertCreated()->json();

        $this->patchJson("$url/lines/{$keep['id']}", ['quantity' => 2, 'notes' => 'no glass'])->assertOk()->assertJsonPath('amount', 4_770);
        $this->postJson("$url/lines/{$drop['id']}/remove")->assertNoContent();
        $this->assertSame(4_770, $this->getJson($url)->json('total'));

        $this->postJson("$url/fire")->assertOk();
        $ticket = \App\Platform\Events\StoredEvent::where('type', 'pos.order_fired')->latest('id')->first();
        $this->assertSame('no glass', $ticket->payload['items'][0]['notes']);
        $this->assertCount(1, $ticket->payload['items']);

        $this->patchJson("$url/lines/{$keep['id']}", ['quantity' => 1])->assertStatus(422)->assertJsonPath('type', 'https://docs.opeshotelsoft.test/problems/line-fired');
        $this->postJson("$url/lines/{$keep['id']}/remove")->assertStatus(422)->assertJsonPath('type', 'https://docs.opeshotelsoft.test/problems/line-fired');

        $this->postJson("$url/settle", ['payments' => [['method' => 'CASH', 'amount' => 4_770]]])->assertOk();
        $this->actAs($this->user, $this->hotel);
        $bal = collect($this->getJson("/api/v1/stock/balances?store_id={$this->barStore->id}")->json())->firstWhere('code', 'BEER33');
        $this->assertEquals(10, $bal['quantity']);
        $b = $this->assertBooksBalance();
        $this->assertSame(1_200, $b['603120'], 'cost of 2 beers');
    }

    public function test_offline_sync_settles_an_existing_check_once(): void
    {
        $this->receiveBeer(1);
        $this->postJson('/api/v1/cashier-shifts')->assertCreated();
        $check = $this->postJson('/api/v1/pos/checks', ['outlet_id' => $this->bar->id])->json();
        $this->postJson("/api/v1/pos/checks/{$check['id']}/items", ['menu_item_id' => $this->beerItem->id])->assertCreated();
        $batch = ['checks' => [[
            'client_uuid' => '8b2f4c2e-9b3d-4e5f-8a6b-1c2d3e4f5a6b', 'check_id' => $check['id'], 'outlet_id' => $this->bar->id,
            'items' => [['menu_item_id' => $this->beerItem->id, 'quantity' => 1]],
            'payments' => [['method' => 'CASH', 'amount' => 2_385]],
        ]]];

        $this->postJson('/api/v1/pos/sync', $batch)->assertOk()->assertJsonPath('results.0.status', 'synced');
        $this->postJson('/api/v1/pos/sync', $batch)->assertOk()->assertJsonPath('results.0.status', 'already_synced');
        $this->assertSame(1, Check::count());
        $this->assertSame('settled', Check::first()->status);
        $this->assertBooksBalance();
    }
}
