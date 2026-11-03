<?php

namespace Tests\Feature\Reservations;

use App\Modules\Guests\Domain\Company;
use App\Modules\Guests\Domain\Guest;
use App\Modules\Property\Domain\Room;
use App\Modules\Reservations\Domain\RoomBlock;
use App\Modules\Reservations\Domain\WaitlistEntry;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReservationExtrasTest extends TestCase
{
    private function book(array $over = []): array
    {
        return $this->postJson('/api/v1/reservations', $over + [
            'arrival' => now()->addDays(2)->toDateString(), 'departure' => now()->addDays(4)->toDateString(),
        ])->assertCreated()->json();
    }

    public function test_season_modifier_and_fixed_rate_change_price_only_inside_season(): void
    {
        [$type, $plan, $guest] = $this->seedInventory(rooms: 3, rate: 50_000);
        $d = fn (int $n) => now()->addDays($n)->toDateString();
        $this->postJson("/api/v1/rate-plans/{$plan->id}/seasons", [
            'name' => 'High', 'start_date' => $d(3), 'end_date' => $d(3), 'modifier_percent' => 20,
        ])->assertCreated();
        $this->postJson("/api/v1/rate-plans/{$plan->id}/seasons", [
            'name' => 'Festival', 'start_date' => $d(4), 'end_date' => $d(4), 'rate' => 90_000, 'room_type_id' => $type->id,
        ])->assertCreated();
        $this->assertCount(2, $this->getJson("/api/v1/rate-plans/{$plan->id}/seasons")->assertOk()->json('data'));

        $r = $this->book(['guest_id' => $guest->id, 'room_type_id' => $type->id, 'rate_plan_id' => $plan->id,
            'arrival' => $d(2), 'departure' => $d(5)]);
        $this->assertSame([50_000, 60_000, 90_000], array_column($r['nights'], 'amount'));
        $this->assertSame(200_000, $r['total_amount']);

        // Outside the season the base rate applies.
        $r2 = $this->book(['guest_id' => $guest->id, 'room_type_id' => $type->id, 'rate_plan_id' => $plan->id,
            'arrival' => $d(10), 'departure' => $d(11)]);
        $this->assertSame(50_000, $r2['total_amount']);
    }

    public function test_season_needs_rate_or_modifier_and_permission(): void
    {
        [, $plan] = $this->seedInventory();
        $this->postJson("/api/v1/rate-plans/{$plan->id}/seasons", ['name' => 'X', 'start_date' => now()->toDateString(), 'end_date' => now()->toDateString()])
            ->assertStatus(422);
        $this->actAs($this->makeUser($this->group, 'storekeeper', allHotels: true), $this->hotel);
        $this->getJson("/api/v1/rate-plans/{$plan->id}/seasons")->assertForbidden();
    }

    public function test_tape_chart_feed_returns_rooms_bars_and_out_of_order(): void
    {
        [$type, $plan, $guest] = $this->seedInventory(rooms: 3);
        $r = $this->book(['guest_id' => $guest->id, 'room_type_id' => $type->id, 'rate_plan_id' => $plan->id]);
        $room = Room::where('number', '102')->first();
        $this->postJson("/api/v1/reservations/{$r['id']}/assign-room", ['room_id' => $room->id])->assertOk();
        Room::where('number', '103')->update(['hk_status' => 'out_of_order']);

        $from = now()->toDateString();
        $to = now()->addDays(14)->toDateString();
        $feed = $this->getJson("/api/v1/reservations/tape-chart?from=$from&to=$to")->assertOk()->json();
        $this->assertCount(3, $feed['rooms']);
        $this->assertSame('DLX', $feed['rooms'][0]['room_type']['code']);
        $this->assertSame($room->id, collect($feed['bars'])->firstWhere('id', $r['id'])['room_id']);
        $this->assertSame(Room::where('number', '103')->value('id'), $feed['out_of_order'][0]['room_id']);

        // Drag to another row = assign-room again.
        $other = Room::where('number', '101')->first();
        $this->postJson("/api/v1/reservations/{$r['id']}/assign-room", ['room_id' => $other->id])->assertOk();
        $feed = $this->getJson("/api/v1/reservations/tape-chart?from=$from&to=$to")->json();
        $this->assertSame($other->id, collect($feed['bars'])->firstWhere('id', $r['id'])['room_id']);

        $this->actAs($this->makeUser($this->group, 'storekeeper', allHotels: true), $this->hotel);
        $this->getJson("/api/v1/reservations/tape-chart?from=$from&to=$to")->assertForbidden();
    }

    public function test_waitlist_add_offer_when_available_and_convert(): void
    {
        [$type, $plan, $guest] = $this->seedInventory(rooms: 1);
        $first = $this->book(['guest_id' => $guest->id, 'room_type_id' => $type->id, 'rate_plan_id' => $plan->id]);
        $other = Guest::create(['first_name' => 'Awa', 'last_name' => 'Ngo']);

        $entry = $this->postJson('/api/v1/waitlist', [
            'guest_id' => $other->id, 'room_type_id' => $type->id, 'rate_plan_id' => $plan->id,
            'arrival' => now()->addDays(2)->toDateString(), 'departure' => now()->addDays(4)->toDateString(),
        ])->assertCreated()->json();
        $list = $this->getJson('/api/v1/waitlist')->assertOk()->json('data');
        $this->assertFalse($list[0]['available']);
        $this->postJson("/api/v1/waitlist/{$entry['id']}/offer")->assertStatus(409);

        $this->postJson("/api/v1/reservations/{$first['id']}/cancel", ['waive_fee' => true])->assertOk();
        $this->assertTrue($this->getJson('/api/v1/waitlist')->json('data.0.available'));
        $this->postJson("/api/v1/waitlist/{$entry['id']}/offer")->assertOk()->assertJsonPath('status', 'offered');

        $res = $this->postJson("/api/v1/waitlist/{$entry['id']}/convert")->assertCreated()->json();
        $this->assertSame($other->id, $res['reservation']['guest_id']);
        $this->assertSame('converted', WaitlistEntry::find($entry['id'])->status);
        $this->assertSame($res['reservation']['id'], WaitlistEntry::find($entry['id'])->reservation_id);
    }

    public function test_travel_agent_with_iata_and_commission_report(): void
    {
        [$type, $plan, $guest] = $this->seedInventory(rooms: 3, rate: 50_000);
        $agent = $this->postJson('/api/v1/travel-agents', ['name' => 'Kribi Voyages', 'iata_number' => '12345678', 'commission_percent' => 10])
            ->assertCreated()->assertJsonPath('kind', 'travel_agent')->json();
        Company::create(['name' => 'Corp', 'kind' => 'corporate']);
        $this->assertCount(1, $this->getJson('/api/v1/travel-agents')->json('data'));

        $this->book(['guest_id' => $guest->id, 'room_type_id' => $type->id, 'rate_plan_id' => $plan->id,
            'agent_id' => $agent['id'], 'channel' => 'travel_agent']);
        $this->book(['guest_id' => $guest->id, 'room_type_id' => $type->id, 'rate_plan_id' => $plan->id]);

        $rep = $this->getJson('/api/v1/travel-agents/commissions?from='.now()->toDateString().'&to='.now()->addDays(30)->toDateString())->assertOk()->json();
        $this->assertCount(1, $rep['data']);
        $this->assertSame('12345678', $rep['data'][0]['iata_number']);
        $this->assertSame(100_000, $rep['data'][0]['revenue']);
        $this->assertSame(10_000, $rep['total_commission']);
    }

    public function test_blocks_index_honours_status_filter(): void
    {
        foreach (['tentative', 'definite', 'released'] as $i => $s) {
            DB::table('room_blocks')->insert(['group_id' => $this->group->id, 'hotel_id' => $this->hotel->id, 'code' => "B$i", 'name' => "Block $i",
                'arrival' => now()->addDays(5), 'departure' => now()->addDays(6), 'status' => $s, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->assertCount(3, $this->getJson('/api/v1/blocks')->json('data'));
        $this->assertSame(['definite'], array_column($this->getJson('/api/v1/blocks?status=definite')->json('data'), 'status'));
        $this->assertCount(2, $this->getJson('/api/v1/blocks?status=tentative,definite')->json('data'));
        $this->assertSame(3, RoomBlock::count());
    }

    public function test_cross_property_booking_with_hotel_header(): void
    {
        [, $sister] = $this->makeHotel('ignored', 'BON', $this->group);
        $this->provision($sister);
        $this->actAs($this->user, $sister);
        [$type, $plan, $guest] = $this->seedInventory(rooms: 1);
        $r = $this->withHeaders(['X-Hotel-Id' => $sister->id])->postJson('/api/v1/reservations', [
            'guest_id' => $guest->id, 'room_type_id' => $type->id, 'rate_plan_id' => $plan->id,
            'arrival' => now()->addDay()->toDateString(), 'departure' => now()->addDays(2)->toDateString(),
        ])->assertCreated()->json();
        $this->assertSame($sister->id, $r['hotel_id']);
    }
}
