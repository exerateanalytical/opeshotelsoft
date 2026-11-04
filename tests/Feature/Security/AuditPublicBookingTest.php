<?php

namespace Tests\Feature\Security;

use App\Modules\Guests\Domain\Guest;
use App\Modules\Loyalty\Application\LoyaltyService;
use Tests\TestCase;

/** SEC1 audit regression: public:booking:guest-profile-takeover-by-email. */
class AuditPublicBookingTest extends TestCase
{
    public function test_public_booking_does_not_attach_to_a_stranger_profile_by_email_or_member_number(): void
    {
        [$type, $plan, $victim] = $this->seedInventory(rooms: 3, rate: 40_000);
        $victim->update(['phone' => '+237690000001', 'id_number' => 'PASS-123']);
        $this->putJson('/api/v1/loyalty/program', ['spend_per_point' => 1000, 'point_value' => 10])->assertOk();
        $membership = app(LoyaltyService::class)->enrol($victim);
        $slug = $this->group->slug;
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['X-Hotel-Id' => null]);
        $arrival = now()->addDays(7)->toDateString();
        $departure = now()->addDays(8)->toDateString();
        $body = fn (array $guest, array $extra = []) => [
            'hotel_code' => $this->hotel->code, 'room_type_id' => $type->id, 'rate_plan_id' => $plan->id,
            'arrival' => $arrival, 'departure' => $departure, 'adults' => 1, 'accept_terms' => true, 'guest' => $guest,
        ] + $extra;

        // Victim's email with another last name: a brand-new profile, nothing of the victim is exposed.
        $b = $this->postJson("/api/v1/booking/$slug/reservations", $body(['first_name' => 'Eve', 'last_name' => 'Attacker', 'email' => $victim->email, 'phone' => '+237699999999']))
            ->assertCreated()->json();
        $this->assertSame(2, Guest::count());
        $look = $this->postJson("/api/v1/booking/$slug/reservations/lookup", ['confirmation' => $b['confirmation'], 'last_name' => 'Attacker'])->assertOk()->json();
        $this->assertNull($look['loyalty']);
        $this->assertSame('Attacker', $look['guest']['last_name']);
        $this->postJson("/api/v1/booking/$slug/reservations/lookup", ['confirmation' => $b['confirmation'], 'last_name' => $victim->last_name])->assertNotFound();

        // Victim's member number with another last name: same rule.
        $this->postJson("/api/v1/booking/$slug/reservations", $body(['first_name' => 'Eve', 'last_name' => 'Attacker', 'email' => 'eve@example.cm', 'phone' => '+237699999998'], ['member_number' => $membership->number]))
            ->assertCreated();
        $this->assertSame(3, Guest::count());

        // The real guest (email + last name) is still recognised and keeps the loyalty link.
        $b = $this->postJson("/api/v1/booking/$slug/reservations", $body(['first_name' => 'Jean', 'last_name' => 'MBALLA', 'email' => $victim->email, 'phone' => '+237690000001']))
            ->assertCreated()->json();
        $this->assertSame(3, Guest::count());
        $this->postJson("/api/v1/booking/$slug/reservations/lookup", ['confirmation' => $b['confirmation'], 'last_name' => 'Mballa'])
            ->assertOk()->assertJsonPath('loyalty.number', $membership->number);
    }
}
