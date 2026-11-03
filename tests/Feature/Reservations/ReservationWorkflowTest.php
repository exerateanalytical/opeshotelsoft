<?php

namespace Tests\Feature\Reservations;

use App\Modules\Reservations\Application\ReservationService;
use App\Modules\Reservations\Domain\Reservation;
use App\Platform\Domain\DomainException;
use App\Platform\Events\StoredEvent;
use Tests\TestCase;

class ReservationWorkflowTest extends TestCase
{
    public function test_book_prices_nights_takes_inventory_and_emits_event(): void
    {
        [$type, $plan, $guest] = $this->seedInventory(rooms: 2, rate: 60_000);
        $arrival = now()->addDays(5)->toDateString();
        $departure = now()->addDays(8)->toDateString();

        $res = $this->postJson('/api/v1/reservations', [
            'guest_id' => $guest->id, 'room_type_id' => $type->id, 'rate_plan_id' => $plan->id,
            'arrival' => $arrival, 'departure' => $departure, 'adults' => 2,
        ])->assertCreated()->json();

        $this->assertSame(180_000, $res['total_amount']);
        $this->assertCount(3, $res['nights']);
        $this->assertSame(1, StoredEvent::where('type', 'reservation.created')->count());

        $inv = $this->getJson("/api/v1/inventory?from=$arrival&to=$arrival")->json();
        $this->assertSame(1, $inv[0]['days'][0]['available']);
    }

    public function test_last_room_cannot_be_sold_twice(): void
    {
        [$type, $plan, $guest] = $this->seedInventory(rooms: 1);
        $payload = [
            'guest_id' => $guest->id, 'room_type_id' => $type->id, 'rate_plan_id' => $plan->id,
            'arrival' => now()->addDay()->toDateString(), 'departure' => now()->addDays(2)->toDateString(),
        ];

        $this->postJson('/api/v1/reservations', $payload)->assertCreated();
        $this->postJson('/api/v1/reservations', $payload)->assertStatus(409);
    }

    public function test_idempotency_key_prevents_double_booking_on_retry(): void
    {
        [$type, $plan, $guest] = $this->seedInventory(rooms: 5);
        $payload = [
            'guest_id' => $guest->id, 'room_type_id' => $type->id, 'rate_plan_id' => $plan->id,
            'arrival' => now()->addDay()->toDateString(), 'departure' => now()->addDays(2)->toDateString(),
        ];

        $a = $this->withHeaders(['Idempotency-Key' => 'abc-1'])->postJson('/api/v1/reservations', $payload)->assertCreated()->json('id');
        $b = $this->withHeaders(['Idempotency-Key' => 'abc-1'])->postJson('/api/v1/reservations', $payload)->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'true')->json('id');

        $this->assertSame($a, $b);
        $this->assertSame(1, Reservation::count());
    }

    public function test_cancel_releases_inventory_and_applies_penalty_inside_window(): void
    {
        [$type, $plan, $guest] = $this->seedInventory(rooms: 1, rate: 40_000);
        $plan->update(['cancellation_hours' => 48, 'cancellation_penalty' => 'first_night']);
        $r = app(ReservationService::class)->create([
            'guest_id' => $guest->id, 'room_type_id' => $type->id, 'rate_plan_id' => $plan->id,
            'arrival' => now()->toDateString(), 'departure' => now()->addDays(2)->toDateString(),
        ]);

        $this->postJson("/api/v1/reservations/{$r->id}/cancel", ['reason' => 'change of plans'])
            ->assertOk()->assertJsonPath('status', 'cancelled')->assertJsonPath('cancellation_fee', 40_000);

        $this->postJson('/api/v1/reservations', [
            'guest_id' => $guest->id, 'room_type_id' => $type->id, 'rate_plan_id' => $plan->id,
            'arrival' => now()->toDateString(), 'departure' => now()->addDay()->toDateString(),
        ])->assertCreated();
    }

    public function test_restrictions_are_enforced(): void
    {
        [$type, $plan, $guest] = $this->seedInventory();
        $arrival = now()->addDays(10)->toDateString();
        $this->putJson('/api/v1/rates', [
            'rate_plan_id' => $plan->id, 'room_type_ids' => [$type->id], 'from' => $arrival, 'to' => $arrival, 'min_stay' => 3,
        ])->assertOk();

        $this->postJson('/api/v1/reservations', [
            'guest_id' => $guest->id, 'room_type_id' => $type->id, 'rate_plan_id' => $plan->id,
            'arrival' => $arrival, 'departure' => now()->addDays(11)->toDateString(),
        ])->assertStatus(422)->assertJsonPath('type', 'https://docs.opeshotelsoft.test/problems/restricted');
    }

    public function test_modify_dates_reprices_and_moves_inventory(): void
    {
        [$type, $plan, $guest] = $this->seedInventory(rooms: 1, rate: 30_000);
        $r = app(ReservationService::class)->create([
            'guest_id' => $guest->id, 'room_type_id' => $type->id, 'rate_plan_id' => $plan->id,
            'arrival' => now()->addDays(3)->toDateString(), 'departure' => now()->addDays(4)->toDateString(),
        ]);

        $this->patchJson("/api/v1/reservations/{$r->id}", ['departure' => now()->addDays(6)->toDateString()])
            ->assertOk()->assertJsonPath('total_amount', 90_000);
    }

    public function test_group_block_pickup_and_release(): void
    {
        [$type, $plan, $guest] = $this->seedInventory(rooms: 5);
        $arrival = now()->addDays(20)->toDateString();
        $departure = now()->addDays(22)->toDateString();

        $block = $this->postJson('/api/v1/blocks', [
            'code' => 'CONF26', 'name' => 'Conference', 'arrival' => $arrival, 'departure' => $departure, 'rate_plan_id' => $plan->id,
            'allocations' => [['room_type_id' => $type->id, 'rooms' => 3]],
        ])->assertCreated()->json();

        $inv = $this->getJson("/api/v1/inventory?from=$arrival&to=$arrival")->json();
        $this->assertSame(2, $inv[0]['days'][0]['available']);

        $this->postJson("/api/v1/blocks/{$block['id']}/rooming-list", [
            'entries' => [['guest_id' => $guest->id, 'room_type_id' => $type->id]],
        ])->assertCreated();

        $this->postJson("/api/v1/blocks/{$block['id']}/release")->assertOk();
        $inv = $this->getJson("/api/v1/inventory?from=$arrival&to=$arrival")->json();
        $this->assertSame(4, $inv[0]['days'][0]['available']);
    }

    public function test_blacklisted_guest_cannot_book(): void
    {
        [$type, $plan, $guest] = $this->seedInventory();
        $guest->update(['blacklisted' => true, 'blacklist_reason' => 'unpaid bill']);

        $this->expectException(DomainException::class);
        app(ReservationService::class)->create([
            'guest_id' => $guest->id, 'room_type_id' => $type->id, 'rate_plan_id' => $plan->id,
            'arrival' => now()->toDateString(), 'departure' => now()->addDay()->toDateString(),
        ]);
    }

    public function test_create_form_books_with_newly_created_guest(): void
    {
        [$type, $plan] = $this->seedInventory(rooms: 2, rate: 60_000);
        $arrival = now()->addDays(3)->toDateString();
        $departure = now()->addDays(5)->toDateString();

        $offers = $this->getJson("/api/v1/availability?arrival=$arrival&departure=$departure&adults=2&children=0")
            ->assertOk()->json('data.0.offers');
        $this->assertSame(120_000, $offers[0]['total']);
        $this->assertCount(2, $offers[0]['nights']);

        $guest = $this->postJson('/api/v1/guests', ['first_name' => 'Awa', 'last_name' => 'Nkolo', 'email' => 'awa@test.cm', 'phone' => null])
            ->assertCreated()->json();
        $this->getJson("/api/v1/guests/{$guest['id']}/duplicates")->assertOk();

        $res = $this->postJson('/api/v1/reservations', [
            'guest_id' => $guest['id'], 'room_type_id' => $offers[0]['room_type']['id'], 'rate_plan_id' => $offers[0]['rate_plan']['id'],
            'arrival' => $arrival, 'departure' => $departure, 'adults' => 2, 'children' => 0, 'rooms' => 1,
            'company_id' => null, 'channel' => 'phone', 'guarantee' => null, 'eta' => '18:30', 'special_requests' => 'High floor',
        ])->assertCreated()->json();

        $this->assertSame(120_000, $res['total_amount']);
        $this->assertSame($guest['id'], $res['guest_id']);
    }

    public function test_create_form_walk_in_payload(): void
    {
        [$type, $plan, $guest] = $this->seedInventory(rooms: 2, rate: 50_000);
        $rooms = $this->getJson("/api/v1/rooms?filter[room_type_id]={$type->id}&filter[occupied]=0&per_page=200")->assertOk()->json('data');
        $this->assertNotEmpty($rooms);

        $res = $this->postJson('/api/v1/walk-ins', [
            'guest_id' => $guest->id, 'room_type_id' => $type->id, 'rate_plan_id' => $plan->id,
            'room_id' => $rooms[0]['id'], 'nights' => 2, 'adults' => 1, 'children' => 0,
            'guest' => ['id_type' => 'national_id', 'id_number' => 'CM123456', 'nationality' => 'CM'],
        ])->assertCreated()->json();

        $this->assertSame('checked_in', $res['status']);
        $this->getJson("/api/v1/reservations/{$res['id']}")->assertOk();
    }
}
