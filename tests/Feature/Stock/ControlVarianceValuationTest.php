<?php

namespace Tests\Feature\Stock;

use App\Modules\Stock\Application\StockService;

class ControlVarianceValuationTest extends ControlTestCase
{
    public function test_usage_variance_splits_theoretical_wastage_and_count_shortfall(): void
    {
        $beef = $this->item('BEEF', 'Beef', 'kg');
        $this->receive($beef, $this->kitchen, 20, 4_000);
        $menu = $this->dish('STEAK', 5_000);
        $this->postJson('/api/v1/stock-control/recipes', [
            'code' => 'STEAKR', 'name' => 'Steak', 'pos_menu_item_id' => $menu->id, 'lines' => [['stock_item_id' => $beef->id, 'quantity' => 0.25, 'unit' => 'kg']],
        ])->assertCreated();
        $this->sell($menu, 4, 20_000); // 1.0 kg by recipe
        $this->postJson('/api/v1/stock/issues', [
            'kind' => 'wastage', 'reason' => 'spoiled', 'store_id' => $this->kitchen->id, 'lines' => [['stock_item_id' => $beef->id, 'quantity' => 0.5]],
        ])->assertCreated();
        $count = $this->postJson('/api/v1/stock/counts', ['store_id' => $this->kitchen->id])->assertCreated()->json();
        $this->putJson("/api/v1/stock/counts/{$count['id']}", ['lines' => [['stock_item_id' => $beef->id, 'counted' => 17]]])->assertOk();
        $this->postJson("/api/v1/stock/counts/{$count['id']}/post")->assertOk();

        $today = now()->toDateString();
        $r = $this->getJson("/api/v1/stock-control/variance?outlet_id={$this->restaurant->id}&from=$today&to=$today")->assertOk();
        $row = collect($r->json('rows'))->firstWhere('code', 'BEEF');
        $this->assertEqualsWithDelta(1.0, $row['theoretical'], 0.0001);
        $this->assertEqualsWithDelta(3.0, $row['actual'], 0.0001);       // 20 received - 17 counted
        $this->assertEqualsWithDelta(2.0, $row['variance_qty'], 0.0001);
        $this->assertEqualsWithDelta(1.5, $row['unexplained_qty'], 0.0001);
        $this->assertSame(8_000, $row['variance_value']);
        $this->assertSame(6_000, $row['unexplained_value']);
        $reasons = collect($row['reasons'])->pluck('reason')->all();
        $this->assertContains('spoiled', $reasons);
        $this->assertContains('count_shortfall', $reasons);
        $this->assertSame(8_000, $r->json('totals.variance_value'));

        $review = $this->postJson('/api/v1/stock-control/variance/reviews', ['outlet_id' => $this->restaurant->id, 'from' => $today, 'to' => $today])->assertCreated()->json();
        $line = $review['lines'][0]['id'];
        $this->putJson("/api/v1/stock-control/variance/reviews/{$review['id']}/lines/$line", ['reason_code' => 'portioning', 'note' => 'Heavy hand'])
            ->assertOk()->assertJsonPath('lines.0.reason_code', 'portioning');
        $this->putJson("/api/v1/stock-control/variance/reviews/{$review['id']}/lines/$line", ['reason_code' => 'nonsense'])->assertStatus(422);
        $this->postJson("/api/v1/stock-control/variance/reviews/{$review['id']}/finalize")->assertOk()->assertJsonPath('status', 'final');
        $this->putJson("/api/v1/stock-control/variance/reviews/{$review['id']}/lines/$line", ['reason_code' => 'theft'])->assertStatus(422);
        $this->assertBooksBalance();
    }

    public function test_variance_needs_an_outlet_store_and_is_tenant_scoped(): void
    {
        $other = \App\Modules\Property\Domain\Outlet::create(['code' => 'BAR', 'name' => 'Bar', 'kind' => 'bar']);
        $today = now()->toDateString();
        $this->getJson("/api/v1/stock-control/variance?outlet_id={$other->id}&from=$today&to=$today")->assertStatus(422)
            ->assertJsonPath('type', 'https://docs.opeshotelsoft.test/problems/no-store');
        $this->getJson("/api/v1/stock-control/variance?outlet_id={$this->restaurant->id}&from=$today&to=2000-01-01")->assertStatus(422);
    }

    public function test_valuation_as_of_a_past_date_in_wac_and_fifo(): void
    {
        $flour = $this->item('FLR', 'Flour', 'kg');
        $svc = app(StockService::class);
        $d = fn (int $ago) => now()->subDays($ago)->toDateString();
        $svc->receive($flour, $this->main, 10, 100, ['date' => $d(10)]);
        $svc->receive($flour, $this->main, 10, 200, ['date' => $d(5)]);
        $svc->consume('issue', $flour, $this->main, 10, ['date' => $d(2)]); // at the average, 150

        $past = $this->getJson('/api/v1/stock-control/valuation?date='.$d(3))->assertOk();
        $row = collect($past->json('rows'))->firstWhere('code', 'FLR');
        $this->assertEquals(20, $row['quantity']);
        $this->assertSame(3_000, $row['wac_value']);
        $this->assertSame(3_000, $row['fifo_value']);

        $now = collect($this->getJson('/api/v1/stock-control/valuation?layers=1')->assertOk()->json('rows'))->firstWhere('code', 'FLR');
        $this->assertEquals(10, $now['quantity']);
        $this->assertSame(1_500, $now['wac_value'], 'average cost 150');
        $this->assertSame(2_000, $now['fifo_value'], 'the 100 layer was used first; 10 kg left at 200');
        $this->assertSame(500, $now['difference']);
        $this->assertEquals(200, $now['layers'][0]['unit_cost']);

        $this->getJson('/api/v1/stock-control/valuation?store_id='.$this->main->id.'&date='.$d(3))->assertOk()->assertJsonPath('totals.wac_value', 3_000);
        $this->getJson('/api/v1/stock-control/valuation?date='.now()->addDays(3)->toDateString())->assertStatus(422);
        $this->assertBooksBalance();
    }

    public function test_valuation_needs_its_own_permission(): void
    {
        $this->actAs($this->makeUser($this->group, 'fnb-manager', hotels: [$this->hotel]), $this->hotel);
        $this->getJson('/api/v1/stock-control/valuation')->assertForbidden();
        $this->getJson('/api/v1/stock-control/recipes')->assertOk();
        $this->actAs($this->makeUser($this->group, 'accountant', hotels: [$this->hotel]), $this->hotel);
        $this->getJson('/api/v1/stock-control/valuation')->assertOk();
    }
}
