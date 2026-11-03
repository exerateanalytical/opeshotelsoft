<?php

namespace Tests\Feature\Operations;

use App\Modules\FrontOffice\Application\FrontDeskService;
use App\Modules\Property\Domain\Room;
use App\Modules\Reservations\Application\ReservationService;
use App\Modules\Reservations\Domain\RatePlan;
use Tests\TestCase;

class HousekeepingMaintenanceLoyaltyTest extends TestCase
{
    public function test_checkout_creates_cleaning_task_and_inspection_releases_room(): void
    {
        [$type, $plan, $guest] = $this->seedInventory(rooms: 1);
        $guest->update(['id_number' => 'P1']);
        $room = Room::first();
        $r = app(ReservationService::class)->create([
            'guest_id' => $guest->id, 'room_type_id' => $type->id, 'rate_plan_id' => $plan->id,
            'arrival' => now()->toDateString(), 'departure' => now()->addDay()->toDateString(), 'room_id' => $room->id,
        ]);
        app(FrontDeskService::class)->checkIn($r);
        app(FrontDeskService::class)->checkOut($r->fresh());

        $tasks = $this->getJson('/api/v1/housekeeping/tasks?date='.now()->toDateString())->assertOk()->json();
        $this->assertCount(1, $tasks);
        $this->assertSame('departure', $tasks[0]['kind']);

        $attendant = $this->makeUser($this->group, 'room-attendant', hotels: [$this->hotel]);
        $this->postJson("/api/v1/housekeeping/tasks/{$tasks[0]['id']}/assign", ['user_id' => $attendant->id])->assertOk();
        $this->actAs($attendant, $this->hotel);
        $this->postJson("/api/v1/housekeeping/tasks/{$tasks[0]['id']}/start")->assertOk();
        $this->postJson("/api/v1/housekeeping/tasks/{$tasks[0]['id']}/finish")->assertOk();
        $this->assertSame('vacant_clean', $room->fresh()->hk_status);

        $this->actAs($this->user, $this->hotel);
        $this->postJson("/api/v1/housekeeping/tasks/{$tasks[0]['id']}/inspect", ['passed' => true])->assertOk();
        $this->assertSame('inspected', $room->fresh()->hk_status);
    }

    public function test_out_of_order_work_order_removes_room_from_sale(): void
    {
        [$type] = $this->seedInventory(rooms: 2);
        $room = Room::first();
        $today = now()->toDateString();

        $wo = $this->postJson('/api/v1/maintenance/work-orders', [
            'title' => 'Fuite salle de bain', 'room_id' => $room->id, 'category' => 'plumbing',
            'priority' => 'high', 'room_out_of_order' => true, 'out_of_order_until' => now()->addDays(2)->toDateString(),
        ])->assertCreated()->json();

        $inv = $this->getJson("/api/v1/inventory?from=$today&to=$today")->json();
        $this->assertSame(1, $inv[0]['days'][0]['available']);
        $this->assertSame('out_of_order', $room->fresh()->hk_status);

        $this->postJson("/api/v1/maintenance/work-orders/{$wo['id']}/complete", ['resolution' => 'Joint remplacé', 'minutes' => 45])->assertOk();
        $inv = $this->getJson("/api/v1/inventory?from=$today&to=$today")->json();
        $this->assertSame(2, $inv[0]['days'][0]['available']);
    }

    public function test_member_earns_on_stay_redeems_points_and_tiers_up(): void
    {
        $this->putJson('/api/v1/loyalty/program', ['spend_per_point' => 1000, 'point_value' => 10])->assertOk();
        [$type, $plan, $guest] = $this->seedInventory(rooms: 1, rate: 59_625);
        $guest->update(['id_number' => 'P1']);
        $member = $this->postJson('/api/v1/loyalty/members', ['guest_id' => $guest->id, 'card' => 'physical'])->assertCreated()->json();
        $this->assertSame('SILVER', $member['tier']['code']);
        $card = $member['cards'][0]['card_number'];
        $this->postJson('/api/v1/loyalty/cards/lookup', ['card' => $card])->assertOk()->assertJsonPath('number', $member['number']);

        $r = app(ReservationService::class)->create([
            'guest_id' => $guest->id, 'room_type_id' => $type->id, 'rate_plan_id' => $plan->id,
            'arrival' => now()->toDateString(), 'departure' => now()->addDay()->toDateString(), 'room_id' => Room::first()->id,
        ]);
        app(FrontDeskService::class)->checkIn($r);
        $this->postJson('/api/v1/night-audit')->assertOk();
        $folio = $this->getJson("/api/v1/reservations/{$r->id}/folio")->json();
        $this->postJson('/api/v1/cashier-shifts')->assertCreated();
        $this->postJson("/api/v1/folios/{$folio['id']}/payments", ['method' => 'CASH', 'amount' => $folio['balance']])->assertCreated();
        $this->postJson("/api/v1/reservations/{$r->id}/check-out")->assertOk();

        // 50 000 net room revenue → 50 points.
        $m = $this->getJson("/api/v1/loyalty/members/{$member['id']}")->json();
        $this->assertSame(50, $m['points_balance']);

        $b = $this->assertBooksBalance();
        $this->assertSame(-500, $b['419800'], 'points liability 50 × 10 XAF');
    }

    public function test_members_only_rate_refuses_non_members(): void
    {
        [$type, , $guest] = $this->seedInventory();
        $member = RatePlan::create(['code' => 'MEM', 'name' => 'Member rate', 'members_only' => true]);

        $this->postJson('/api/v1/reservations', [
            'guest_id' => $guest->id, 'room_type_id' => $type->id, 'rate_plan_id' => $member->id,
            'arrival' => now()->toDateString(), 'departure' => now()->addDay()->toDateString(),
        ])->assertStatus(422)->assertJsonPath('type', 'https://docs.opeshotelsoft.test/problems/members-only');
    }
}
