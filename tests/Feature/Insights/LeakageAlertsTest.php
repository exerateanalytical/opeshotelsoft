<?php

namespace Tests\Feature\Insights;

use App\Modules\Insights\Domain\Alert;
use App\Modules\POS\Domain\MenuItem;
use App\Modules\Property\Domain\Outlet;
use App\Modules\Property\Domain\Room;
use App\Modules\Property\Domain\RoomType;
use App\Modules\Reservations\Application\ReservationService;
use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Stock\Application\StockService;
use App\Modules\Stock\Domain\StockItem;
use App\Modules\Stock\Domain\Store;
use App\Modules\Insights\Application\Evaluator;
use App\Platform\Events\StoredEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LeakageAlertsTest extends TestCase
{
    private Outlet $bar;

    private MenuItem $beer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bar = Outlet::create(['code' => 'BAR', 'name' => 'Lobby Bar', 'kind' => 'bar']);
        $this->beer = MenuItem::create(['code' => 'BEER', 'name' => '33 Export', 'price' => 23_850, 'charge_code' => 'BAR', 'kitchen_station' => 'bar']);
        $this->postJson('/api/v1/cashier-shifts')->assertCreated();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function check(int $qty = 1): array
    {
        $c = $this->postJson('/api/v1/pos/checks', ['outlet_id' => $this->bar->id])->assertCreated()->json();
        $this->postJson("/api/v1/pos/checks/{$c['id']}/items", ['menu_item_id' => $this->beer->id, 'quantity' => $qty])->assertCreated();

        return $this->getJson("/api/v1/pos/checks/{$c['id']}")->json();
    }

    private function alerts(string $rule): \Illuminate\Support\Collection
    {
        return Alert::where('rule', $rule)->get();
    }

    private function checkedIn(?RoomType $type = null): Reservation
    {
        [$t, $plan, $guest] = $this->seedInventory(rooms: 2, rate: 50_000);
        $guest->update(['id_type' => 'passport', 'id_number' => 'CM1', 'nationality' => 'CM']);
        $type ??= $t;
        $r = app(ReservationService::class)->create([
            'guest_id' => $guest->id, 'room_type_id' => $type->id, 'rate_plan_id' => $plan->id,
            'arrival' => now()->toDateString(), 'departure' => now()->addDays(2)->toDateString(),
        ]);
        $room = Room::where('room_type_id', $type->id)->orderBy('number')->first();
        $this->postJson("/api/v1/reservations/{$r->id}/check-in", ['room_id' => $room->id])->assertOk();

        return $r->fresh();
    }

    public function test_void_after_sending_to_bar_raises_alert_but_removal_before_does_not(): void
    {
        $c = $this->check(2);
        $this->postJson("/api/v1/pos/checks/{$c['id']}/lines/{$c['lines'][0]['id']}/remove")->assertSuccessful();
        $this->assertCount(0, $this->alerts('void_after_fire'));

        $c = $this->check();
        $this->postJson("/api/v1/pos/checks/{$c['id']}/fire")->assertSuccessful();
        $this->postJson("/api/v1/pos/checks/{$c['id']}/lines/{$c['lines'][0]['id']}/void", ['reason' => 'Client changed mind'])->assertSuccessful();

        $a = $this->alerts('void_after_fire')->sole();
        $this->assertSame('high', $a->severity);
        $this->assertSame(23_850, $a->amount);
        $this->assertSame('pos_check', $a->evidence[0]['type']);
        $this->assertSame($c['id'], $a->evidence[0]['id']);
    }

    public function test_discount_above_threshold_and_comps(): void
    {
        $c = $this->check(2); // 47 700
        $this->postJson("/api/v1/pos/checks/{$c['id']}/discount", ['amount' => 2_000, 'reason' => 'Regular'])->assertSuccessful();
        $this->postJson("/api/v1/pos/checks/{$c['id']}/settle", ['payments' => [['method' => 'CASH', 'amount' => 45_700]]])->assertSuccessful();
        $this->assertCount(0, $this->alerts('discount_above'), '4 % and 2 000 FCFA stay under the thresholds');

        $c = $this->check(2);
        $this->postJson("/api/v1/pos/checks/{$c['id']}/discount", ['amount' => 12_000, 'reason' => 'Friend of manager'])->assertSuccessful();
        $this->postJson("/api/v1/pos/checks/{$c['id']}/settle", ['payments' => [['method' => 'CASH', 'amount' => 35_700]]])->assertSuccessful();
        $a = $this->alerts('discount_above')->sole();
        $this->assertSame(12_000, $a->amount);
        $this->assertStringContainsString('Friend of manager', $a->detail);

        $c = $this->check();
        $this->postJson("/api/v1/pos/checks/{$c['id']}/comp", ['reason' => 'VIP', 'line_id' => $c['lines'][0]['id']])->assertSuccessful();
        $this->assertSame(23_850, $this->alerts('comp_above')->sole()->amount);
    }

    public function test_cashier_variance_alert(): void
    {
        $shift = $this->getJson('/api/v1/cashier-shifts/current')->assertOk()->json();
        $this->postJson("/api/v1/cashier-shifts/{$shift['id']}/close", ['counted' => ['CASH' => 0]])->assertOk();
        $this->assertCount(0, $this->alerts('cashier_variance'));

        $this->postJson('/api/v1/cashier-shifts')->assertCreated();
        $shift = $this->getJson('/api/v1/cashier-shifts/current')->json();
        $this->postJson("/api/v1/cashier-shifts/{$shift['id']}/close", ['counted' => ['CASH' => 5_000]])->assertOk();
        $a = $this->alerts('cashier_variance')->sole();
        $this->assertSame(5_000, $a->amount);
        $this->assertSame('cashier_shift', $a->evidence[0]['type']);
    }

    public function test_room_move_to_dearer_type_without_rerate(): void
    {
        $r = $this->checkedIn();
        $suite = RoomType::create(['code' => 'STE', 'name' => 'Suite', 'max_adults' => 2, 'base_rate' => 90_000]);
        $room = Room::create(['room_type_id' => $suite->id, 'number' => '301']);
        $this->postJson("/api/v1/reservations/{$r->id}/move-room", ['room_id' => $room->id, 'reason' => 'Upgrade'])->assertOk();

        $a = $this->alerts('room_move_no_charge')->sole();
        $this->assertSame(40_000, $a->amount);
        $this->assertSame("/reservations/{$r->id}", $a->evidence[0]['url']);
    }

    public function test_scan_finds_occupied_without_posting_and_rate_below_floor_once(): void
    {
        $r = $this->checkedIn();
        DB::table('reservation_nights')->where('reservation_id', $r->id)->update(['amount' => 30_000]); // 40 % under grid
        $date = now()->toDateString();

        $this->artisan('insights:scan', ['--date' => $date, '--no-digest' => true])->assertSuccessful();
        $this->artisan('insights:scan', ['--date' => $date, '--no-digest' => true])->assertSuccessful();

        $this->assertCount(1, $this->alerts('occupied_no_posting'));
        $floor = $this->alerts('rate_below_floor')->sole();
        $this->assertSame(20_000, $floor->amount);

        // After the night audit posts the room, a new scan finds nothing more for that date.
        Alert::query()->update(['status' => 'resolved', 'resolution_note' => 'x']);
        $this->postJson('/api/v1/night-audit')->assertOk();
        $this->postJson('/api/v1/insights/scan', ['date' => $date])->assertOk()->assertJsonPath('findings', 1); // only the rate finding
    }

    public function test_late_checkout_not_charged(): void
    {
        $r = $this->checkedIn();
        Carbon::setTestNow(now()->setTimezone('Africa/Douala')->setTime(16, 30));
        $this->postJson("/api/v1/reservations/{$r->id}/check-out")->assertOk();

        $a = $this->alerts('late_checkout_uncharged')->sole();
        $this->assertStringContainsString('16:30', $a->title);
    }

    public function test_reversal_and_after_hours_folio_posting(): void
    {
        Carbon::setTestNow(now()->setTimezone('Africa/Douala')->setTime(2, 15));
        // Fed straight to the rule (a real reversal needs a posted folio; other subscribers are not under test here).
        $e = new StoredEvent(['type' => 'folio.line_posted', 'hotel_id' => $this->hotel->id, 'group_id' => $this->group->id, 'occurred_at' => now(), 'payload' => [
            'line_id' => 9001, 'folio_id' => 1, 'folio_number' => 'F-1', 'type' => 'reversal', 'code' => 'BAR',
            'department' => 'beverage', 'amount' => -15_000, 'description' => 'Bar', 'business_date' => now()->toDateString(),
        ]]);
        $e->id = 1;
        app(Evaluator::class)->onFolioLine($e);

        $this->assertSame(15_000, $this->alerts('reopened_check')->sole()->amount);
        $this->assertStringContainsString('02:15', $this->alerts('after_hours_posting')->sole()->title);
    }

    public function test_bar_stock_consumed_beyond_sales(): void
    {
        $store = Store::create(['code' => 'BARS', 'name' => 'Bar store', 'kind' => 'bar', 'department' => 'beverage', 'outlet_id' => $this->bar->id]);
        $item = StockItem::create(['code' => 'WHISKY', 'name' => 'Whisky', 'category' => 'beverage', 'unit' => 'btl', 'inventory_account' => '311200', 'cost_account' => '603120', 'vat_rate' => 0]);
        $stock = app(StockService::class);
        $stock->receive($item, $store, 10, 10_000);
        $stock->consume('sale', $item, $store, 2);
        $stock->adjust($item, $store, -1, 'Count shortfall');

        $this->artisan('insights:scan', ['--date' => now()->toDateString(), '--no-digest' => true])->assertSuccessful();
        $a = $this->alerts('bar_stock_gap')->sole();
        $this->assertSame(10_000, $a->amount);
        $this->assertSame('high', $a->severity); // 50 % of sales
    }

    public function test_rules_can_be_switched_off_and_thresholds_changed_per_hotel(): void
    {
        $this->putJson('/api/v1/insights/settings', ['rules' => ['void_after_fire' => ['enabled' => false], 'cashier_variance' => ['min_amount' => 10_000]]])
            ->assertOk()->assertJsonPath('rules.void_after_fire.enabled', false)->assertJsonPath('rules.cashier_variance.min_amount', 10_000);
        $this->putJson('/api/v1/insights/settings', ['rules' => ['nope' => ['enabled' => false]]])->assertStatus(422);

        $c = $this->check();
        $this->postJson("/api/v1/pos/checks/{$c['id']}/fire")->assertSuccessful();
        $this->postJson("/api/v1/pos/checks/{$c['id']}/lines/{$c['lines'][0]['id']}/void", ['reason' => 'x'])->assertSuccessful();
        $shift = $this->getJson('/api/v1/cashier-shifts/current')->json();
        $this->postJson("/api/v1/cashier-shifts/{$shift['id']}/close", ['counted' => ['CASH' => 5_000]])->assertOk();

        $this->assertSame(0, Alert::count());
    }

    public function test_acknowledge_then_resolve_with_note_and_permissions(): void
    {
        $shift = $this->getJson('/api/v1/cashier-shifts/current')->json();
        $this->postJson("/api/v1/cashier-shifts/{$shift['id']}/close", ['counted' => ['CASH' => 5_000]])->assertOk();
        $a = Alert::sole();

        $receptionist = $this->makeUser($this->group, 'receptionist', hotels: [$this->hotel]);
        $this->actAs($receptionist, $this->hotel)->getJson('/api/v1/insights/alerts')->assertForbidden();

        $owner = $this->makeUser($this->group, 'hotel-owner', hotels: [$this->hotel]);
        $this->actAs($owner, $this->hotel);
        $this->getJson('/api/v1/insights/alerts?status=open')->assertOk()->assertJsonPath('total', 1);
        $this->postJson("/api/v1/insights/alerts/{$a->id}/acknowledge", ['note' => 'Looking'])->assertOk()->assertJsonPath('status', 'acknowledged');
        $this->postJson("/api/v1/insights/alerts/{$a->id}/acknowledge")->assertStatus(422);
        $this->postJson("/api/v1/insights/alerts/{$a->id}/resolve")->assertStatus(422);
        $this->postJson("/api/v1/insights/alerts/{$a->id}/resolve", ['note' => 'Cashier refunded the client'])->assertOk()
            ->assertJsonPath('status', 'resolved')->assertJsonPath('resolved_by', $owner->id);
        $this->deleteJson("/api/v1/insights/alerts/{$a->id}")->assertStatus(405);

        $auditor = $this->makeUser($this->group, 'auditor', hotels: [$this->hotel]);
        $this->actAs($auditor, $this->hotel)->getJson("/api/v1/insights/alerts/{$a->id}")->assertOk();
        $this->postJson("/api/v1/insights/alerts/{$a->id}/acknowledge")->assertForbidden();
    }
}
