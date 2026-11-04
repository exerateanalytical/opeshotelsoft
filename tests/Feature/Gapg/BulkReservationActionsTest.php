<?php

namespace Tests\Feature\Gapg;

use App\Modules\Guests\Domain\Guest;
use App\Modules\Notifications\Domain\NotificationPreference;
use App\Modules\Notifications\Domain\OutboxMessage;
use App\Modules\Property\Domain\Room;
use App\Modules\Reservations\Application\ReservationService;
use App\Modules\Reservations\Domain\Reservation;
use Illuminate\Support\Facades\Bus;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/** GAP-G: bulk assign room (available-rooms picker) and bulk guest messaging from the reservations list. */
class BulkReservationActionsTest extends TestCase
{
    private function book(Guest $g, int $typeId, int $planId, ?int $roomId = null, int $offset = 1): Reservation
    {
        return app(ReservationService::class)->create([
            'guest_id' => $g->id, 'room_type_id' => $typeId, 'rate_plan_id' => $planId, 'room_id' => $roomId,
            'arrival' => now()->addDays($offset)->toDateString(), 'departure' => now()->addDays($offset + 2)->toDateString(),
        ]);
    }

    public function test_available_rooms_excludes_clashing_and_out_of_order_rooms(): void
    {
        [$type, $plan, $guest] = $this->seedInventory(3);
        $rooms = Room::orderBy('number')->get();
        $this->book($guest, $type->id, $plan->id, $rooms[0]->id);
        $rooms[1]->update(['hk_status' => 'out_of_order']);
        $r = $this->book($guest, $type->id, $plan->id);

        $ids = collect($this->getJson("/api/v1/reservations/$r->id/available-rooms")->assertOk()->json())->pluck('id')->all();
        $this->assertSame([$rooms[2]->id], $ids);

        $this->postJson("/api/v1/reservations/$r->id/assign-room", ['room_id' => $rooms[2]->id])->assertOk();
        // per-row failure: a clashing room is refused
        $r2 = $this->book($guest, $type->id, $plan->id);
        $this->postJson("/api/v1/reservations/$r2->id/assign-room", ['room_id' => $rooms[2]->id])->assertStatus(409);
        $this->postJson("/api/v1/reservations/$r2->id/assign-room", [])->assertUnprocessable();
    }

    public function test_available_rooms_and_assign_are_permission_checked(): void
    {
        [$type, $plan, $guest] = $this->seedInventory(1);
        $r = $this->book($guest, $type->id, $plan->id);
        $this->actAs($this->makeUser($this->group, 'room-attendant', allHotels: true), $this->hotel);
        $this->getJson("/api/v1/reservations/$r->id/available-rooms")->assertForbidden();
        $this->postJson("/api/v1/reservations/$r->id/assign-room", ['room_id' => Room::first()->id])->assertForbidden();
    }

    public function test_bulk_message_picks_channel_and_skips_unreachable_guests(): void
    {
        Bus::fake();
        [$type, $plan, $emailGuest] = $this->seedInventory(4);
        $waGuest = Guest::create(['first_name' => 'Awa', 'last_name' => 'Ndiaye', 'phone' => '+237 690 11 22 33']);
        NotificationPreference::create(['guest_id' => $waGuest->id, 'whatsapp' => true]);
        $noContact = Guest::create(['first_name' => 'No', 'last_name' => 'Contact']);
        $optedOut = Guest::create(['first_name' => 'Opt', 'last_name' => 'Out', 'email' => 'o@test.cm']);
        NotificationPreference::create(['guest_id' => $optedOut->id, 'email' => false, 'sms' => false]);

        $ids = [
            $this->book($emailGuest, $type->id, $plan->id)->id, $this->book($waGuest, $type->id, $plan->id)->id,
            $this->book($noContact, $type->id, $plan->id)->id, $this->book($optedOut, $type->id, $plan->id)->id, 999999,
        ];
        $res = $this->postJson('/api/v1/messaging/bulk', ['reservation_ids' => $ids, 'body' => 'Bonjour {{ guest_name }}, ref {{ number }}'])
            ->assertOk()->json();

        $by = collect($res['results'])->keyBy('reservation_id');
        $this->assertSame('email', $by[$ids[0]]['channel'], json_encode($res));
        $this->assertSame('queued', $by[$ids[0]]['status']);
        $this->assertSame('whatsapp', $by[$ids[1]]['channel']);
        $this->assertSame('no_contact', $by[$ids[2]]['reason']);
        $this->assertSame('no_consent', $by[$ids[3]]['reason']);
        $this->assertSame('not_found', $by[999999]['reason']);
        $this->assertSame(2, $res['summary']['queued']);
        $this->assertStringContainsString('Bonjour Jean Mballa', OutboxMessage::where('guest_id', $emailGuest->id)->where('event', 'messaging.bulk')->first()->body);
        $this->assertTrue(Activity::where('event', 'messaging.bulk')->exists());
    }

    public function test_marketing_bulk_needs_consent(): void
    {
        Bus::fake();
        [$type, $plan, $guest] = $this->seedInventory(1);
        $r = $this->book($guest, $type->id, $plan->id);
        $res = $this->postJson('/api/v1/messaging/bulk', ['reservation_ids' => [$r->id], 'body' => 'Promo', 'category' => 'marketing'])->assertOk();
        $res->assertJsonPath('results.0.reason', 'no_consent');
    }

    public function test_bulk_message_validation(): void
    {
        $this->postJson('/api/v1/messaging/bulk', ['reservation_ids' => [], 'body' => 'x'])->assertUnprocessable();
        $this->postJson('/api/v1/messaging/bulk', ['reservation_ids' => [1]])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->postJson('/api/v1/messaging/bulk', ['reservation_ids' => range(1, 101), 'body' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('reservation_ids');
        $this->postJson('/api/v1/messaging/bulk', ['reservation_ids' => [1], 'body' => 'x', 'category' => 'spam'])->assertUnprocessable();
    }

    public function test_bulk_message_permissions(): void
    {
        Bus::fake();
        $this->actAs($this->makeUser($this->group, 'receptionist', allHotels: true), $this->hotel);
        $this->postJson('/api/v1/messaging/bulk', ['reservation_ids' => [999999], 'body' => 'x'])->assertOk();

        $this->actAs($this->makeUser($this->group, 'room-attendant', allHotels: true), $this->hotel);
        $this->postJson('/api/v1/messaging/bulk', ['reservation_ids' => [1], 'body' => 'x'])->assertForbidden();
    }
}
