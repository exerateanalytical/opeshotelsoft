<?php

namespace Tests\Feature\POS;

use App\Modules\FrontOffice\Domain\CashierShift;
use App\Modules\POS\Domain\MenuItem;
use App\Modules\Property\Domain\Outlet;
use Illuminate\Support\Carbon;
use Tests\Feature\Guests\RepairsRoleSeed;
use Tests\TestCase;

class PosExtrasTest extends TestCase
{
    use RepairsRoleSeed;

    private Outlet $resto;

    private MenuItem $beer;

    private MenuItem $steak;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resto = Outlet::create(['code' => 'RES', 'name' => 'Restaurant', 'kind' => 'restaurant']);
        $this->beer = MenuItem::create(['code' => 'B33', 'name' => '33 Export', 'price' => 2_000, 'charge_code' => 'BAR', 'kitchen_station' => 'bar']);
        $this->steak = MenuItem::create(['code' => 'STK', 'name' => 'Steak frites', 'price' => 8_000, 'charge_code' => 'FNB', 'kitchen_station' => 'grill']);
    }

    private function table(string $name, int $x = 0, int $y = 0): array
    {
        return $this->postJson('/api/v1/pos/tables', ['outlet_id' => $this->resto->id, 'name' => $name, 'seats' => 4, 'pos_x' => $x, 'pos_y' => $y])->assertCreated()->json();
    }

    public function test_floor_plan_open_resume_and_transfer(): void
    {
        $t1 = $this->table('T1');
        $t2 = $this->table('T2', 1);
        $check = $this->postJson("/api/v1/pos/tables/{$t1['id']}/check", ['covers' => 2])->assertOk()->assertJsonPath('table_number', 'T1')->json();
        $this->postJson("/api/v1/pos/tables/{$t1['id']}/check")->assertOk()->assertJsonPath('id', $check['id']); // resume

        $floor = $this->getJson("/api/v1/pos/outlets/{$this->resto->id}/floor")->assertOk()->json();
        $this->assertCount(2, $floor['tables']);
        $this->assertSame($check['id'], $floor['tables'][0]['checks'][0]['id']);

        $this->postJson("/api/v1/pos/checks/{$check['id']}/transfer", ['table_id' => $t2['id']])->assertOk()->assertJsonPath('table_number', 'T2');
        $other = $this->postJson("/api/v1/pos/tables/{$t1['id']}/check")->assertOk()->json();
        $this->assertNotSame($check['id'], $other['id']);
        $this->postJson("/api/v1/pos/checks/{$other['id']}/transfer", ['table_id' => $t2['id']])->assertStatus(422);

        // Cashiers may not edit the floor plan.
        $cashier = $this->makeUser($this->group, 'pos-cashier', allHotels: true);
        $this->actAs($cashier, $this->hotel)->postJson('/api/v1/pos/tables', ['outlet_id' => $this->resto->id, 'name' => 'T9'])->assertForbidden();
        $this->getJson("/api/v1/pos/outlets/{$this->resto->id}/floor")->assertOk();
    }

    public function test_happy_hour_applies_inside_window_only(): void
    {
        $this->postJson('/api/v1/pos/price-lists', [
            'outlet_id' => $this->resto->id, 'name' => 'Happy hour', 'starts_at' => '17:00', 'ends_at' => '19:00', 'percent_off' => 50,
            'items' => [['pos_menu_item_id' => $this->beer->id, 'price' => 1_200]],
        ])->assertCreated();
        $check = $this->postJson('/api/v1/pos/checks', ['outlet_id' => $this->resto->id])->json();

        Carbon::setTestNow(today()->setTime(18, 0));
        $this->postJson("/api/v1/pos/checks/{$check['id']}/items", ['menu_item_id' => $this->beer->id])->assertCreated()
            ->assertJsonPath('unit_price', 1_200)->assertJsonPath('list_price', 2_000);
        $this->postJson("/api/v1/pos/checks/{$check['id']}/items", ['menu_item_id' => $this->steak->id])->assertCreated()->assertJsonPath('unit_price', 4_000);

        Carbon::setTestNow(today()->setTime(19, 0));
        $this->postJson("/api/v1/pos/checks/{$check['id']}/items", ['menu_item_id' => $this->beer->id])->assertCreated()->assertJsonPath('unit_price', 2_000);
        Carbon::setTestNow(today()->setTime(9, 30));
        $this->postJson("/api/v1/pos/checks/{$check['id']}/items", ['menu_item_id' => $this->steak->id])->assertCreated()->assertJsonPath('unit_price', 8_000);
        Carbon::setTestNow();
    }

    public function test_window_crossing_midnight(): void
    {
        $this->postJson('/api/v1/pos/price-lists', ['name' => 'Late', 'starts_at' => '23:00', 'ends_at' => '02:00', 'percent_off' => 10])->assertCreated();
        $check = $this->postJson('/api/v1/pos/checks', ['outlet_id' => $this->resto->id])->json();
        Carbon::setTestNow(today()->setTime(1, 0));
        $this->postJson("/api/v1/pos/checks/{$check['id']}/items", ['menu_item_id' => $this->beer->id])->assertJsonPath('unit_price', 1_800);
        Carbon::setTestNow(today()->setTime(3, 0));
        $this->postJson("/api/v1/pos/checks/{$check['id']}/items", ['menu_item_id' => $this->beer->id])->assertJsonPath('unit_price', 2_000);
        Carbon::setTestNow();
    }

    public function test_kitchen_tickets_per_fire_and_station(): void
    {
        $t = $this->table('T5');
        $check = $this->postJson("/api/v1/pos/tables/{$t['id']}/check")->json();
        $this->postJson("/api/v1/pos/checks/{$check['id']}/items", ['menu_item_id' => $this->steak->id, 'notes' => 'saignant', 'modifiers' => [['name' => 'Sauce poivre', 'price' => 500]]]);
        $this->postJson("/api/v1/pos/checks/{$check['id']}/items", ['menu_item_id' => $this->beer->id]);
        $this->postJson("/api/v1/pos/checks/{$check['id']}/fire")->assertOk();

        $k = $this->getJson("/api/v1/pos/checks/{$check['id']}/tickets")->assertOk()->json();
        $this->assertSame('T5', $k['check']['table']);
        $this->assertCount(2, $k['tickets']);
        $grill = collect($k['tickets'])->firstWhere('station', 'grill');
        $this->assertSame('saignant', $grill['items'][0]['notes']);
        $this->assertSame('Sauce poivre', $grill['items'][0]['modifiers'][0]['name']);
        $this->getJson("/api/v1/pos/checks/{$check['id']}/tickets?fired_at=".urlencode($grill['fired_at']))->assertOk()->assertJsonCount(2, 'tickets');
    }

    public function test_comp_needs_reason_and_supervisor_and_books_balance(): void
    {
        CashierShift::create(['user_id' => $this->user->id, 'opening_float' => 0, 'status' => 'open', 'opened_at' => now(), 'business_date' => $this->hotel->business_date]);
        $check = $this->postJson('/api/v1/pos/checks', ['outlet_id' => $this->resto->id])->json();
        $steak = $this->postJson("/api/v1/pos/checks/{$check['id']}/items", ['menu_item_id' => $this->steak->id])->json();
        $this->postJson("/api/v1/pos/checks/{$check['id']}/items", ['menu_item_id' => $this->beer->id]);

        $cashier = $this->makeUser($this->group, 'pos-cashier', allHotels: true);
        $this->actAs($cashier, $this->hotel);
        $this->postJson("/api/v1/pos/checks/{$check['id']}/comp", ['line_id' => $steak['id']])->assertStatus(422); // reason required
        $this->postJson("/api/v1/pos/checks/{$check['id']}/comp", ['line_id' => $steak['id'], 'reason' => 'Overcooked'])->assertOk()
            ->assertJsonPath('total', 10_000)->assertJsonPath('lines.0.comp_status', 'pending');
        $this->postJson("/api/v1/pos/checks/{$check['id']}/comp/decide", ['approve' => true])->assertForbidden();

        $this->actAs($this->user, $this->hotel);
        $this->postJson("/api/v1/pos/checks/{$check['id']}/settle", ['payments' => [['method' => 'CASH', 'amount' => 10_000]]])->assertStatus(422); // pending comp
        $this->postJson("/api/v1/pos/checks/{$check['id']}/comp/decide", ['approve' => true])->assertOk()
            ->assertJsonPath('comp_total', 8_000)->assertJsonPath('total', 2_000);
        $this->postJson("/api/v1/pos/checks/{$check['id']}/settle", ['payments' => [['method' => 'CASH', 'amount' => 2_000]]])->assertOk();

        $bal = $this->assertBooksBalance();
        $this->assertSame(8_000, (int) $bal['658100']);
        $this->assertSame(2_000, (int) $bal['571100']);
    }

    public function test_fully_comped_check_settles_with_no_payment(): void
    {
        $check = $this->postJson('/api/v1/pos/checks', ['outlet_id' => $this->resto->id])->json();
        $this->postJson("/api/v1/pos/checks/{$check['id']}/items", ['menu_item_id' => $this->beer->id]);
        // A supervisor comping the whole check approves at once.
        $this->postJson("/api/v1/pos/checks/{$check['id']}/comp", ['reason' => 'VIP welcome'])->assertOk()->assertJsonPath('total', 0);
        $this->postJson("/api/v1/pos/checks/{$check['id']}/settle", ['payments' => []])->assertOk()->assertJsonPath('settlement', 'comp');
        $bal = $this->assertBooksBalance();
        $this->assertSame(2_000, (int) $bal['658100']);
    }
}
