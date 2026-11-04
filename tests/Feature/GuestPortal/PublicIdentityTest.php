<?php

namespace Tests\Feature\GuestPortal;

use App\Modules\GuestPortal\Application\GuestLinks;
use App\Modules\Guests\Domain\Guest;
use App\Modules\Property\Domain\Room;
use App\Modules\Property\Domain\RoomType;
use App\Modules\Reservations\Application\ReservationService;
use App\Modules\Reservations\Domain\RatePlan;
use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Tenancy\Domain\Hotel;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Two hotels of one group with the SAME reservation number and the SAME guest last name: every public entry point stays unambiguous. */
class PublicIdentityTest extends TestCase
{
    private Hotel $hotelB;

    private Reservation $a;

    private Reservation $b;

    private string $slug;

    protected function setUp(): void
    {
        parent::setUp();
        [, $this->hotelB] = $this->makeHotel('x', 'BBB', $this->group);
        $this->provision($this->hotelB);
        $this->a = $this->book($this->hotel, 'Mballa', 'a@test.cm');
        $this->b = $this->book($this->hotelB, 'Mballa', 'b@test.cm');
        // Numbers are sequenced per hotel; force the collision regardless of sequence state.
        DB::table('reservations')->where('id', $this->b->id)->update(['number' => $this->a->number]);
        $this->b = Reservation::withoutGlobalScopes()->find($this->b->id);
        $this->slug = $this->group->slug;
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['X-Hotel-Id' => null]);
    }

    private function book(Hotel $hotel, string $last, string $email, ?\App\Models\User $as = null): Reservation
    {
        $this->actAs($as ?? $this->user, $hotel);
        $type = RoomType::create(['code' => 'DLX', 'name' => 'Deluxe', 'max_adults' => 2, 'max_children' => 1, 'base_rate' => 50_000]);
        Room::create(['room_type_id' => $type->id, 'number' => '101']);
        $plan = RatePlan::create(['code' => 'BAR', 'name' => 'BAR']);
        $guest = Guest::create(['first_name' => 'Jean', 'last_name' => $last, 'email' => $email]);

        return app(ReservationService::class)->create([
            'guest_id' => $guest->id, 'room_type_id' => $type->id, 'rate_plan_id' => $plan->id,
            'arrival' => now()->addDays(3)->toDateString(), 'departure' => now()->addDays(5)->toDateString(),
        ]);
    }

    private function tok(Reservation $r): string
    {
        return GuestLinks::manageToken($r);
    }

    private function statusOf(Reservation $r): string
    {
        return Reservation::withoutGlobalScopes()->find($r->id)->status;
    }

    public function test_public_codes_are_unique_per_group_and_backed_by_an_index(): void
    {
        $this->assertSame($this->a->number, $this->b->number);
        $this->assertNotNull($this->a->public_code);
        $this->assertNotSame($this->a->public_code, $this->b->public_code);
        $this->assertSame(8, strlen($this->a->public_code));
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('reservations')->where('id', $this->b->id)->update(['public_code' => $this->a->public_code]);
    }

    public function test_lookup_by_public_code_returns_only_the_intended_booking(): void
    {
        foreach ([$this->a, $this->b] as $r) {
            $this->postJson("/api/v1/booking/{$this->slug}/reservations/lookup", ['confirmation' => $r->public_code, 'last_name' => 'mballa'])
                ->assertOk()->assertJsonPath('public_code', $r->public_code)->assertJsonPath('hotel.code', Hotel::withoutGlobalScopes()->find($r->hotel_id)->code);
        }
    }

    public function test_ambiguous_number_is_refused_with_422_and_leaks_nothing(): void
    {
        $res = $this->postJson("/api/v1/booking/{$this->slug}/reservations/lookup", ['confirmation' => $this->a->number, 'last_name' => 'Mballa'])->assertStatus(422);
        $this->assertSame('ambiguous_booking', $res->json('code'));
        $this->assertStringContainsString('booking reference', $res->json('message'));
        $this->assertArrayNotHasKey('hotel', $res->json());
        $this->assertStringNotContainsString($this->a->public_code, $res->getContent());
    }

    public function test_number_with_hotel_code_resolves_exactly_that_booking(): void
    {
        $this->postJson("/api/v1/booking/{$this->slug}/reservations/lookup", ['confirmation' => $this->a->number, 'last_name' => 'Mballa', 'hotel' => 'BBB'])
            ->assertOk()->assertJsonPath('public_code', $this->b->public_code);
        $this->postJson("/api/v1/booking/{$this->slug}/reservations/lookup", ['confirmation' => $this->a->number, 'last_name' => 'Mballa', 'hotel' => $this->hotel->code])
            ->assertOk()->assertJsonPath('public_code', $this->a->public_code);
        $this->postJson("/api/v1/booking/{$this->slug}/reservations/lookup", ['confirmation' => $this->a->number, 'last_name' => 'Mballa', 'hotel' => 'ZZZ'])->assertNotFound();
    }

    public function test_legacy_number_with_single_match_still_works(): void
    {
        DB::table('reservations')->where('id', $this->b->id)->update(['number' => 'R2026-777777']);
        $this->postJson("/api/v1/booking/{$this->slug}/reservations/lookup", ['confirmation' => $this->a->number, 'last_name' => 'Mballa'])
            ->assertOk()->assertJsonPath('public_code', $this->a->public_code);
        $this->postJson("/api/v1/booking/{$this->slug}/reservations/lookup", ['confirmation' => 'R2026-777777', 'last_name' => 'Mballa'])
            ->assertOk()->assertJsonPath('public_code', $this->b->public_code);
        // Last name still required to prove ownership.
        $this->postJson("/api/v1/booking/{$this->slug}/reservations/lookup", ['confirmation' => 'R2026-777777', 'last_name' => 'Other'])->assertNotFound();
    }

    public function test_cancel_touches_only_the_booking_the_token_belongs_to(): void
    {
        // Same number for both: the token picks the booking.
        $this->postJson("/api/v1/booking/{$this->slug}/reservations/cancel", ['confirmation' => $this->a->number, 'token' => $this->tok($this->b)])->assertOk();
        $this->assertSame('cancelled', $this->statusOf($this->b));
        $this->assertNotSame('cancelled', $this->statusOf($this->a));

        $this->postJson("/api/v1/booking/{$this->slug}/reservations/cancel", ['confirmation' => $this->a->public_code, 'token' => $this->tok($this->a)])->assertOk();
        $this->assertSame('cancelled', $this->statusOf($this->a));
    }

    public function test_token_of_another_booking_or_hotel_is_rejected(): void
    {
        // Reference of A with the token of B.
        $this->postJson("/api/v1/booking/{$this->slug}/reservations/cancel", ['confirmation' => $this->a->public_code, 'token' => $this->tok($this->b)])->assertForbidden();
        // Token of A presented for hotel B.
        $this->postJson("/api/v1/booking/{$this->slug}/reservations/cancel", ['confirmation' => $this->a->number, 'token' => $this->tok($this->a), 'hotel' => 'BBB'])->assertForbidden();
        $this->assertNotSame('cancelled', $this->statusOf($this->a));
        $this->assertNotSame('cancelled', $this->statusOf($this->b));
        // The token is bound to the hotel: a token computed without it does not open the booking.
        $legacy = hash_hmac('sha256', $this->a->id.'|'.$this->a->number.'|'.$this->a->guest_id, (string) config('app.key'));
        $this->postJson("/api/v1/booking/{$this->slug}/reservations/cancel", ['confirmation' => $this->a->public_code, 'token' => $legacy])->assertForbidden();
    }

    public function test_online_checkin_only_for_the_intended_booking(): void
    {
        $body = ['id_type' => 'passport', 'id_number' => 'P123', 'eta' => '15:00'];
        $this->postJson("/api/v1/booking/{$this->slug}/reservations/check-in", $body + ['confirmation' => $this->a->number, 'token' => $this->tok($this->b)])->assertCreated();
        $this->assertSame(1, DB::table('guest_portal_checkins')->where('reservation_id', $this->b->id)->count());
        $this->assertSame(0, DB::table('guest_portal_checkins')->where('reservation_id', $this->a->id)->count());
        $this->postJson("/api/v1/booking/{$this->slug}/reservations/check-in", $body + ['confirmation' => $this->a->public_code, 'token' => 'forged'])->assertForbidden();
    }

    public function test_deposit_and_manage_endpoints_use_the_token_bound_booking(): void
    {
        $url = "/api/v1/booking/{$this->slug}/reservations/{$this->a->number}/deposit";
        $this->postJson($url, ['token' => $this->tok($this->b), 'provider' => 'orange_money', 'payer_phone' => '+237690000001', 'amount' => 20_000])->assertCreated();
        $this->assertSame(1, DB::table('payment_intents')->where('reservation_id', $this->b->id)->count());
        $this->assertSame(0, DB::table('payment_intents')->where('reservation_id', $this->a->id)->count());
        $this->postJson($url, ['token' => 'forged', 'provider' => 'orange_money', 'payer_phone' => '+237690000001'])->assertForbidden();

        $this->postJson("/api/v1/booking/{$this->slug}/manage/options", ['confirmation' => $this->a->number, 'token' => $this->tok($this->a)])->assertOk();
        $this->postJson("/api/v1/booking/{$this->slug}/manage/options", ['confirmation' => $this->a->public_code, 'token' => $this->tok($this->b)])->assertForbidden();
    }

    public function test_review_by_last_name_refuses_ambiguity(): void
    {
        $this->postJson("/api/v1/booking/{$this->slug}/manage/review", ['confirmation' => $this->a->number, 'last_name' => 'Mballa', 'rating' => 5])->assertStatus(422);
    }

    public function test_cross_group_isolation(): void
    {
        [$g2, $h2] = $this->makeHotel('Other group', 'OTH');
        $this->provision($h2);
        $r2 = $this->book($h2, 'Mballa', 'o@test.cm', $this->makeUser($g2, 'general-manager', allHotels: true));
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['X-Hotel-Id' => null]);
        $this->postJson("/api/v1/booking/{$this->slug}/reservations/lookup", ['confirmation' => $r2->public_code, 'last_name' => 'Mballa'])->assertNotFound();
        $this->postJson("/api/v1/booking/{$g2->slug}/reservations/lookup", ['confirmation' => $this->a->public_code, 'last_name' => 'Mballa'])->assertNotFound();
        $this->postJson("/api/v1/booking/{$this->slug}/reservations/cancel", ['confirmation' => $r2->public_code, 'token' => $this->tok($r2)])->assertNotFound();
        $this->assertNotSame('cancelled', $this->statusOf($r2));
    }

    public function test_links_carry_the_reference_and_order_link_is_bound_to_the_hotel(): void
    {
        $links = app(GuestLinks::class);
        $this->assertStringContainsString('c='.$this->a->public_code, $links->manage($this->a));
        $order = $links->order($this->a);
        $this->assertStringContainsString('hotel='.$this->a->hotel_id, $order);
        $tampered = preg_replace('/hotel=\d+/', 'hotel='.$this->b->hotel_id, $order);
        $this->get($tampered)->assertForbidden();
    }
}
