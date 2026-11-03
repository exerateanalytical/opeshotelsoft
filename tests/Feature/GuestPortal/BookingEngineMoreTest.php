<?php

namespace Tests\Feature\GuestPortal;

use App\Modules\Reservations\Domain\RatePlan;
use App\Modules\Reservations\Domain\Reservation;
use App\Platform\Tenancy\TenantContext;
use Tests\TestCase;

class BookingEngineMoreTest extends TestCase
{
    private string $slug;

    private function guestPayload(array $inv, array $extra = []): array
    {
        return $extra + [
            'hotel_code' => $this->hotel->code, 'room_type_id' => $inv[0]->id, 'rate_plan_id' => $inv[1]->id,
            'arrival' => now()->addDays(3)->toDateString(), 'departure' => now()->addDays(4)->toDateString(), 'adults' => 1, 'accept_terms' => true,
            'guest' => ['first_name' => 'Awa', 'last_name' => 'Ngono', 'email' => 'awa@example.cm', 'phone' => '+237670000000'],
        ];
    }

    private function anonymous(): void
    {
        $this->slug = $this->group->slug;
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['X-Hotel-Id' => null]);
    }

    public function test_hotels_lists_active_room_types_and_unknown_group_404s(): void
    {
        $this->seedInventory(rooms: 1);
        $this->anonymous();
        $h = $this->getJson("/api/v1/booking/{$this->slug}/hotels")->assertOk()->json();
        $this->assertSame($this->hotel->code, $h['hotels'][0]['code']);
        $this->assertSame('DLX', $h['hotels'][0]['room_types'][0]['code']);
        $this->assertArrayNotHasKey('settings', $h['hotels'][0]);
        $this->getJson('/api/v1/booking/no-such-group/hotels')->assertNotFound();
    }

    public function test_availability_validates_dates(): void
    {
        $this->seedInventory(rooms: 1);
        $this->anonymous();
        $base = "/api/v1/booking/{$this->slug}/availability";
        $this->getJson("$base?arrival=".now()->subDay()->toDateString().'&departure='.now()->addDay()->toDateString())->assertStatus(422)->assertJsonValidationErrors('arrival');
        $d = now()->addDays(2)->toDateString();
        $this->getJson("$base?arrival=$d&departure=$d")->assertStatus(422)->assertJsonValidationErrors('departure');
        $this->getJson("$base?arrival=$d&departure=".now()->addDays(3)->toDateString().'&adults=20')->assertStatus(422)->assertJsonValidationErrors('adults');
    }

    public function test_private_and_members_only_plans_are_not_sold_publicly(): void
    {
        $inv = $this->seedInventory(rooms: 1, rate: 40_000);
        $corp = RatePlan::create(['code' => 'CORP', 'name' => 'Corporate', 'public' => false]);
        RatePlan::create(['code' => 'MEM', 'name' => 'Members', 'members_only' => true]);
        $this->anonymous();

        $res = $this->getJson("/api/v1/booking/{$this->slug}/availability?arrival=".now()->addDays(3)->toDateString().'&departure='.now()->addDays(4)->toDateString())->assertOk()->json();
        $this->assertSame(['BAR'], array_map(fn ($o) => $o['rate_plan']['code'], $res['results'][0]['offers']));
        $this->assertNull($res['member']);

        $this->postJson("/api/v1/booking/{$this->slug}/reservations", $this->guestPayload($inv, ['rate_plan_id' => $corp->id]))->assertNotFound();
    }

    public function test_booking_requires_terms_and_sold_out_type_has_no_offers(): void
    {
        $inv = $this->seedInventory(rooms: 1, rate: 40_000);
        $this->anonymous();
        $payload = $this->guestPayload($inv);
        $this->postJson("/api/v1/booking/{$this->slug}/reservations", ['accept_terms' => false] + $payload)->assertStatus(422)->assertJsonValidationErrors('accept_terms');

        $b = $this->postJson("/api/v1/booking/{$this->slug}/reservations", $payload)->assertCreated()->json();
        $this->assertSame('confirmed', $b['status']);
        $this->assertSame(40_000, $b['total']);
        $channel = app(TenantContext::class)->withoutScope(fn () => Reservation::where('number', $b['confirmation'])->value('channel'));
        $this->assertSame('website', $channel);

        $res = $this->getJson("/api/v1/booking/{$this->slug}/availability?arrival={$payload['arrival']}&departure={$payload['departure']}")->assertOk()->json();
        $this->assertSame([], $res['results'][0]['offers']);
    }

    public function test_online_checkin_needs_token_and_closes_after_cancel(): void
    {
        $inv = $this->seedInventory(rooms: 1);
        $this->anonymous();
        $b = $this->postJson("/api/v1/booking/{$this->slug}/reservations", $this->guestPayload($inv))->assertCreated()->json();
        $ci = ['confirmation' => $b['confirmation'], 'id_type' => 'passport', 'id_number' => 'AB123', 'eta' => '15:30'];

        $this->postJson("/api/v1/booking/{$this->slug}/reservations/check-in", $ci + ['token' => 'forged'])->assertForbidden();
        $this->postJson("/api/v1/booking/{$this->slug}/reservations/check-in", ['eta' => '3pm', 'token' => $b['manage_token']] + $ci)->assertStatus(422);
        $this->postJson("/api/v1/booking/{$this->slug}/reservations/check-in", $ci + ['token' => $b['manage_token']])->assertCreated()->assertJsonPath('status', 'received');
        $this->postJson("/api/v1/booking/{$this->slug}/reservations/lookup", ['confirmation' => $b['confirmation'], 'last_name' => 'ngono'])
            ->assertOk()->assertJsonPath('checkin.id_type', 'passport')->assertJsonPath('can_cancel', true);

        $this->postJson("/api/v1/booking/{$this->slug}/reservations/cancel", ['confirmation' => $b['confirmation'], 'token' => $b['manage_token']])->assertOk();
        $this->postJson("/api/v1/booking/{$this->slug}/reservations/check-in", $ci + ['token' => $b['manage_token']])->assertStatus(422);
    }
}
