<?php

namespace Tests\Feature\Stock;

use App\Modules\Stock\Control\Domain\StockLot;
use App\Platform\Events\StoredEvent;

class ControlLotsTest extends ControlTestCase
{
    private function lot(int $itemId, string $number, int $qty, int $days): array
    {
        return $this->postJson('/api/v1/stock-control/lots', [
            'stock_item_id' => $itemId, 'store_id' => $this->main->id, 'quantity' => $qty, 'unit_cost' => 100,
            'lot_number' => $number, 'expiry_date' => now()->addDays($days)->toDateString(),
        ])->assertCreated()->json();
    }

    public function test_issue_takes_the_earliest_expiry_first_and_transfers_carry_lots(): void
    {
        $milk = $this->item('MILK', 'Milk', 'l');
        $late = $this->lot($milk->id, 'L-LATE', 5, 10);
        $soon = $this->lot($milk->id, 'L-SOON', 5, 3); // received second, expires first

        $this->postJson('/api/v1/stock/issues', ['store_id' => $this->main->id, 'lines' => [['stock_item_id' => $milk->id, 'quantity' => 6]]])->assertCreated();
        $this->assertEquals(0, StockLot::find($soon['id'])->quantity_remaining);
        $this->assertEquals(4, StockLot::find($late['id'])->quantity_remaining);

        $this->postJson('/api/v1/stock/transfers', ['from_store_id' => $this->main->id, 'to_store_id' => $this->kitchen->id, 'lines' => [['stock_item_id' => $milk->id, 'quantity' => 3]]])->assertCreated();
        $moved = StockLot::where('store_id', $this->kitchen->id)->first();
        $this->assertSame('L-LATE', $moved->lot_number);
        $this->assertEquals(3, $moved->quantity_remaining);
        $this->assertSame(now()->addDays(10)->toDateString(), $moved->expiry_date->toDateString());
        $this->assertEquals(1, StockLot::find($late['id'])->quantity_remaining);
        $this->getJson("/api/v1/stock-control/lots/fefo?stock_item_id={$milk->id}&store_id={$this->main->id}")->assertOk()->assertJsonCount(1);
    }

    public function test_goods_receipt_of_a_tracked_item_creates_a_lot_with_shelf_life(): void
    {
        $yog = $this->item('YOG', 'Yoghurt', 'pc');
        $this->putJson("/api/v1/stock-control/item-controls/{$yog->id}", ['tracks_lots' => true, 'shelf_life_days' => 14])->assertSuccessful();
        $this->receive($yog, $this->main, 20, 300, ['reference' => 'BR-77']);

        $lot = StockLot::first();
        $this->assertSame('BR-77', $lot->lot_number);
        $this->assertSame(now()->addDays(14)->toDateString(), $lot->expiry_date->toDateString());
        $this->putJson("/api/v1/stock-control/lots/{$lot->id}", ['expiry_date' => now()->addDays(5)->toDateString()])->assertOk();
    }

    public function test_expiry_buckets_and_alert_events(): void
    {
        $milk = $this->item('MILK', 'Milk', 'l');
        $old = $this->lot($milk->id, 'L-OLD', 4, 5);
        $this->lot($milk->id, 'L-OK', 4, 60);
        $this->putJson("/api/v1/stock-control/lots/{$old['id']}", ['expiry_date' => now()->subDay()->toDateString()])->assertOk();
        $this->lot($milk->id, 'L-NEAR', 2, 4);

        $r = $this->getJson('/api/v1/stock-control/lots/expiry')->assertOk();
        $this->assertSame(['L-OLD'], collect($r->json('expired'))->pluck('lot_number')->all());
        $this->assertSame(['L-NEAR'], collect($r->json('expiring'))->pluck('lot_number')->all());
        $this->assertSame(400, $r->json('expired.0.value'));

        $this->artisan('stock:expiry-alerts')->assertSuccessful();
        $this->assertSame(2, StoredEvent::where('type', 'stock.lot_expiring')->count());
        $this->artisan('stock:expiry-alerts')->assertSuccessful();
        $this->assertSame(2, StoredEvent::where('type', 'stock.lot_expiring')->count(), 'alerted once per lot');
    }

    public function test_write_off_posts_a_balanced_wastage_entry(): void
    {
        $milk = $this->item('MILK', 'Milk', 'l');
        $lot = $this->lot($milk->id, 'L-1', 10, 2);

        $this->postJson("/api/v1/stock-control/lots/{$lot['id']}/write-off", ['quantity' => 4, 'reason' => 'expired'])->assertCreated()
            ->assertJsonPath('movement.kind', 'wastage')->assertJsonPath('movement.reason', 'expired');
        $this->assertEquals(6, StockLot::find($lot['id'])->quantity_remaining);
        $this->assertEquals(6, $this->onHand($this->main, $milk));
        $b = $this->assertBooksBalance();
        $this->assertSame(400, $b['603110'], 'expense of 4 l at 100');
        $this->assertSame(600, $b['311100'], '6 l left');

        $this->postJson("/api/v1/stock-control/lots/{$lot['id']}/write-off", ['quantity' => 50])->assertStatus(422)
            ->assertJsonPath('type', 'https://docs.opeshotelsoft.test/problems/lot-insufficient');
        $this->postJson("/api/v1/stock-control/lots/{$lot['id']}/write-off", ['reason' => 'party'])->assertStatus(422);
        $this->postJson("/api/v1/stock-control/lots/{$lot['id']}/write-off")->assertCreated();
        $this->assertEquals(0, $this->onHand($this->main, $milk));
        $this->assertBooksBalance();
    }

    public function test_write_off_is_permissioned(): void
    {
        $milk = $this->item('MILK', 'Milk', 'l');
        $lot = $this->lot($milk->id, 'L-1', 10, 2);
        $this->actAs($this->makeUser($this->group, 'fnb-manager', hotels: [$this->hotel]), $this->hotel);
        $this->postJson("/api/v1/stock-control/lots/{$lot['id']}/write-off")->assertForbidden();
        $this->actAs($this->makeUser($this->group, 'storekeeper', hotels: [$this->hotel]), $this->hotel);
        $this->postJson("/api/v1/stock-control/lots/{$lot['id']}/write-off")->assertCreated();
    }
}
