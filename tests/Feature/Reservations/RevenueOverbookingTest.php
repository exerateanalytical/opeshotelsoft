<?php

namespace Tests\Feature\Reservations;

use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Reservations\Revenue\Domain\WalkRecord;
use Illuminate\Support\Facades\DB;

class RevenueOverbookingTest extends RevenueBaseTest
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRevenue(stdRooms: 2, dlxRooms: 2);
    }

    public function test_without_a_policy_no_overbooking_is_possible(): void
    {
        $this->postJson('/api/v1/revenue/overbooking/apply', ['room_type_id' => $this->std->id, 'from' => $this->day(5), 'to' => $this->day(6), 'limit' => 3])
            ->assertOk()->assertJsonPath('applied', 0)->assertJsonPath('requested', 3);
        $this->book($this->std, 5, 1);
        $this->book($this->std, 5, 1);
        $this->postJson('/api/v1/reservations', [
            'guest_id' => $this->guest->id, 'room_type_id' => $this->std->id, 'rate_plan_id' => $this->bar->id, 'arrival' => $this->day(5), 'departure' => $this->day(6),
        ])->assertStatus(409);
    }

    public function test_limit_is_clamped_to_the_policy_cap_and_sells_extra_rooms(): void
    {
        // 2 rooms: min(max_rooms 3, floor(2 x 50%) = 1) = 1
        $this->putJson("/api/v1/revenue/overbooking/policies/{$this->std->id}", ['max_rooms' => 3, 'max_percent' => 50, 'notes' => 'peak only'])
            ->assertSuccessful()->assertJsonPath('cap', 1);

        $this->postJson('/api/v1/revenue/overbooking/apply', ['room_type_id' => $this->std->id, 'from' => $this->day(5), 'to' => $this->day(6), 'limit' => 9])
            ->assertOk()->assertJsonPath('applied', 1);
        $this->assertSame(1, (int) DB::table('room_type_inventory')->where('room_type_id', $this->std->id)->where('date', $this->day(5))->value('overbooking_limit'));

        $this->book($this->std, 5, 1);
        $this->book($this->std, 5, 1);
        $this->book($this->std, 5, 1); // the overbooked one
        $this->postJson('/api/v1/reservations', [
            'guest_id' => $this->guest->id, 'room_type_id' => $this->std->id, 'rate_plan_id' => $this->bar->id, 'arrival' => $this->day(5), 'departure' => $this->day(6),
        ])->assertStatus(409);

        $exposure = $this->getJson("/api/v1/revenue/overbooking/exposure?from={$this->day(4)}&to={$this->day(7)}")->assertOk()->json('data');
        $this->assertCount(1, $exposure);
        $this->assertSame(['date' => $this->day(5), 'over' => 1], ['date' => $exposure[0]['date'], 'over' => $exposure[0]['over']]);
    }

    public function test_walk_follows_the_documented_policy(): void
    {
        $this->putJson('/api/v1/revenue/settings', ['walk_policy' => 'Pay the first night at the partner and the taxi.', 'walk_compensation' => 25_000, 'walk_partner_hotel' => 'Hotel Sawa'])->assertSuccessful();
        $this->putJson("/api/v1/revenue/overbooking/policies/{$this->std->id}", ['max_rooms' => 1, 'max_percent' => 0])->assertSuccessful();
        $this->postJson('/api/v1/revenue/overbooking/apply', ['room_type_id' => $this->std->id, 'from' => $this->day(5), 'to' => $this->day(5), 'limit' => 1])->assertOk();
        $this->book($this->std, 5, 1);
        $this->book($this->std, 5, 1);
        $late = $this->book($this->std, 5, 1);

        $walk = $this->postJson("/api/v1/revenue/reservations/{$late->id}/walk", ['note' => 'no room at 22:00'])->assertCreated()
            ->assertJsonPath('partner_hotel', 'Hotel Sawa')->assertJsonPath('compensation', 25_000)->json();
        $this->assertSame($late->id, $walk['reservation_id']);

        $late->refresh();
        $this->assertSame('cancelled', $late->status);
        $this->assertSame(0, (int) $late->cancellation_fee, 'the guest is never charged for being walked');
        $this->assertSame(2, (int) DB::table('room_type_inventory')->where('room_type_id', $this->std->id)->where('date', $this->day(5))->value('sold'));
        $this->assertSame(1, WalkRecord::count());
        $this->postJson("/api/v1/revenue/reservations/{$late->id}/walk")->assertStatus(422);
        $this->getJson('/api/v1/revenue/walks')->assertOk()->assertJsonPath('data.0.reservation_id', $late->id);
        $this->assertSame(2, Reservation::where('status', 'confirmed')->count());
    }

    public function test_policy_changes_need_manage_permission(): void
    {
        $this->asRole('receptionist');
        $this->putJson("/api/v1/revenue/overbooking/policies/{$this->std->id}", ['max_rooms' => 1, 'max_percent' => 0])->assertForbidden();
        $this->postJson('/api/v1/revenue/overbooking/apply', ['room_type_id' => $this->std->id, 'from' => $this->day(5), 'to' => $this->day(5), 'limit' => 1])->assertForbidden();
    }
}
