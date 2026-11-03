<?php

namespace Tests\Feature\Reservations;

use App\Modules\Property\Domain\Room;
use App\Modules\Reservations\Application\ReservationService;
use App\Modules\Reservations\Domain\Reservation;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** R-B tape chart operations: move, extend/shorten, split, swap, with server-side conflict checks. */
class ExperienceTapeTest extends TestCase
{
    private $type;

    private $plan;

    private $guest;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->type, $this->plan, $this->guest] = $this->seedInventory(rooms: 3, rate: 50_000);
    }

    private function book(int $start, int $nights, ?string $room = null): Reservation
    {
        return app(ReservationService::class)->create([
            'guest_id' => $this->guest->id, 'room_type_id' => $this->type->id, 'rate_plan_id' => $this->plan->id,
            'arrival' => Carbon::today()->addDays($start)->toDateString(), 'departure' => Carbon::today()->addDays($start + $nights)->toDateString(),
            'room_id' => $room ? Room::where('number', $room)->value('id') : null,
        ]);
    }

    private function room(string $n): int
    {
        return Room::where('number', $n)->value('id');
    }

    public function test_move_to_free_room_and_refuse_clash_with_clear_message(): void
    {
        $a = $this->book(3, 2, '101');
        $b = $this->book(3, 2, '102');

        $this->postJson('/api/v1/experience/tape/move', ['reservation_id' => $a->id, 'room_id' => $this->room('103')])->assertOk()->assertJsonPath('room_id', $this->room('103'));
        $res = $this->postJson('/api/v1/experience/tape/move', ['reservation_id' => $a->id, 'room_id' => $this->room('102')])->assertStatus(409);
        $this->assertStringContainsString($b->number, $res->json('detail') ?? $res->json('message') ?? json_encode($res->json()));
        $this->assertSame($this->room('103'), $a->refresh()->room_id);
    }

    public function test_move_refused_for_out_of_order_room_and_dry_run_changes_nothing(): void
    {
        $a = $this->book(3, 2, '101');
        Room::where('number', '102')->update(['hk_status' => 'out_of_order']);

        $dry = $this->postJson('/api/v1/experience/tape/move', ['reservation_id' => $a->id, 'room_id' => $this->room('102'), 'dry_run' => true])->assertOk()->json();
        $this->assertFalse($dry['ok']);
        $this->assertStringContainsString('out of order', $dry['error']);

        $ok = $this->postJson('/api/v1/experience/tape/move', ['reservation_id' => $a->id, 'room_id' => $this->room('103'), 'dry_run' => true])->assertOk()->json();
        $this->assertTrue($ok['ok']);
        $this->assertSame($this->room('101'), $a->refresh()->room_id, 'dry run must not move the stay');
    }

    public function test_in_house_guest_must_use_front_desk_move(): void
    {
        $a = $this->book(0, 2, '101');
        $a->update(['status' => 'checked_in']);

        $res = $this->postJson('/api/v1/experience/tape/move', ['reservation_id' => $a->id, 'room_id' => $this->room('102')])->assertStatus(422);
        $this->assertStringContainsString('front desk', json_encode($res->json()));
    }

    public function test_extend_reprices_and_refuses_when_room_is_taken_next_night(): void
    {
        $a = $this->book(3, 2, '101');
        $b = $this->book(6, 1, '101');

        $res = $this->postJson('/api/v1/experience/tape/resize', ['reservation_id' => $a->id, 'departure' => Carbon::today()->addDays(7)->toDateString()])->assertStatus(409);
        $this->assertStringContainsString($b->number, json_encode($res->json()));

        $this->postJson('/api/v1/experience/tape/resize', ['reservation_id' => $a->id, 'departure' => Carbon::today()->addDays(6)->toDateString()])->assertOk();
        $this->assertSame(150_000, (int) $a->refresh()->total_amount);

        $r = $this->postJson('/api/v1/experience/tape/resize', ['reservation_id' => $a->id, 'arrival' => Carbon::today()->addDays(4)->toDateString()])->assertOk()->json();
        $this->assertSame(-50_000, $r['difference']);
        $this->assertCount(2, $a->refresh()->nights);
    }

    public function test_split_stay_across_two_rooms_keeps_booking_ref_and_totals(): void
    {
        $a = $this->book(3, 4, '101');
        $ref = $a->booking_ref;
        $splitAt = Carbon::today()->addDays(5)->toDateString();

        $out = $this->postJson('/api/v1/experience/tape/split', ['reservation_id' => $a->id, 'split_date' => $splitAt, 'room_id' => $this->room('102')])->assertOk()->json();

        $first = Reservation::find($out['first']['id']);
        $second = Reservation::find($out['second']['id']);
        $this->assertSame($splitAt, $first->departure->toDateString());
        $this->assertSame($splitAt, $second->arrival->toDateString());
        $this->assertSame($ref, $second->booking_ref);
        $this->assertSame($a->id, $second->split_from_id);
        $this->assertSame(100_000, (int) $first->total_amount);
        $this->assertSame(100_000, (int) $second->total_amount);
        $this->assertSame($this->room('102'), $second->room_id);
        $this->assertSame(2, $first->nights()->count());

        $this->postJson('/api/v1/experience/tape/split', ['reservation_id' => $first->id, 'split_date' => Carbon::today()->addDays(3)->toDateString(), 'room_id' => $this->room('103')])
            ->assertStatus(422);
    }

    public function test_split_refuses_busy_target_room(): void
    {
        $a = $this->book(3, 4, '101');
        $b = $this->book(5, 1, '102');

        $res = $this->postJson('/api/v1/experience/tape/split', ['reservation_id' => $a->id, 'split_date' => Carbon::today()->addDays(5)->toDateString(), 'room_id' => $this->room('102')])->assertStatus(409);
        $this->assertStringContainsString($b->number, json_encode($res->json()));
        $this->assertSame(4, $a->refresh()->nightCount());
    }

    public function test_swap_two_rooms_and_refuse_when_a_third_stay_blocks(): void
    {
        $a = $this->book(3, 2, '101');
        $b = $this->book(3, 2, '102');

        $this->postJson('/api/v1/experience/tape/swap', ['reservation_id' => $a->id, 'with_reservation_id' => $b->id])->assertOk();
        $this->assertSame($this->room('102'), $a->refresh()->room_id);
        $this->assertSame($this->room('101'), $b->refresh()->room_id);

        // b is long and a third stay sits in room 101 on a night b would take over... a stays short, c blocks a's new room? build it:
        $c = $this->book(9, 1, '103');
        $d = $this->book(7, 3, '101'); // 7..10 in 101
        $e = $this->book(7, 1, '103'); // third stay in 103
        $res = $this->postJson('/api/v1/experience/tape/swap', ['reservation_id' => $d->id, 'with_reservation_id' => $c->id])->assertStatus(409);
        $this->assertStringContainsString($e->number, json_encode($res->json()));
        $this->assertSame($this->room('101'), $d->refresh()->room_id);
    }

    public function test_swap_needs_permission(): void
    {
        $a = $this->book(3, 2, '101');
        $b = $this->book(3, 2, '102');
        $this->actAs($this->makeUser($this->group, 'pos-cashier', allHotels: true), $this->hotel);

        $this->postJson('/api/v1/experience/tape/swap', ['reservation_id' => $a->id, 'with_reservation_id' => $b->id])->assertForbidden();
    }
}
