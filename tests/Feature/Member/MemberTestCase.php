<?php

namespace Tests\Feature\Member;

use App\Modules\FrontOffice\Application\FrontDeskService;
use App\Modules\Guests\Domain\Guest;
use App\Modules\Loyalty\Application\LoyaltyService;
use App\Modules\MemberPortal\Domain\MemberAccount;
use App\Modules\Notifications\Domain\OutboxMessage;
use App\Modules\Property\Domain\Room;
use App\Modules\Property\Domain\RoomType;
use App\Modules\Reservations\Application\ReservationService;
use App\Modules\Reservations\Domain\RatePlan;
use App\Modules\Reservations\Domain\Reservation;
use App\Platform\Tenancy\TenantContext;
use Tests\TestCase;

abstract class MemberTestCase extends TestCase
{
    public const PASSWORD = 'Secret-2026x';

    protected function setUp(): void
    {
        parent::setUp();
        $this->putJson('/api/v1/loyalty/program', ['spend_per_point' => 1000, 'point_value' => 10])->assertOk();
    }

    /** Guest with a loyalty membership and a verified portal account in the test group. */
    protected function member(string $email, string $first = 'Amina', string $last = 'Ngono', ?string $phone = null, bool $enrol = true): array
    {
        $guest = Guest::create(['first_name' => $first, 'last_name' => $last, 'email' => $email, 'phone' => $phone]);
        $m = $enrol ? app(LoyaltyService::class)->enrol($guest, 'digital') : null;
        $account = MemberAccount::create([
            'guest_id' => $guest->id, 'first_name' => $first, 'last_name' => $last, 'email' => $email, 'verified_contact' => 'email',
            'verified_at' => now(), 'password' => self::PASSWORD,
        ]);

        return [$account, $guest, $m];
    }

    /** An Inertia page visit (JSON props). */
    protected function inertia(string $url)
    {
        $version = (string) app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request());

        return $this->get($url, ['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Requested-With' => 'XMLHttpRequest']);
    }

    /** Drop staff authentication and tenant context: the next requests come from a visitor. */
    protected function asVisitor(): static
    {
        $this->app['auth']->forgetGuards();
        app(TenantContext::class)->set(null);
        $this->flushHeaders();
        $this->withHeaders(['Accept' => 'application/json']);

        return $this;
    }

    protected function loginAs(MemberAccount $a): static
    {
        $this->asVisitor();
        $this->postJson('/account/login', ['identifier' => $a->email, 'password' => self::PASSWORD])->assertOk()->assertJsonPath('redirect', '/account');

        return $this;
    }

    protected function backToStaff(): static
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        return $this->actAs($this->user, $this->hotel);
    }

    /** Latest one-time code sent to a recipient (read from the notification outbox). */
    protected function lastCode(string $recipient): string
    {
        $m = app(TenantContext::class)->withoutScope(fn () => OutboxMessage::where('recipient', $recipient)->latest('id')->first());
        $this->assertNotNull($m, "no code sent to $recipient");
        preg_match('/\b(\d{6})\b/', (string) $m->body, $x);

        return $x[1];
    }

    protected function codesSent(): int
    {
        return app(TenantContext::class)->withoutScope(fn () => OutboxMessage::where('event', 'guest_portal.otp')->count());
    }

    /** A staff-created reservation for the guest; checked in when asked. */
    protected function stay(Guest $g, bool $checkIn = false, int $startInDays = 0, int $nights = 2): Reservation
    {
        if (! RoomType::exists()) {
            $this->seedInventory(rooms: 3, rate: 50_000);
        }
        $r = app(ReservationService::class)->create([
            'guest_id' => $g->id, 'room_type_id' => RoomType::first()->id, 'rate_plan_id' => RatePlan::where('code', 'BAR')->first()->id,
            'arrival' => now()->addDays($startInDays)->toDateString(), 'departure' => now()->addDays($startInDays + $nights)->toDateString(),
            'room_id' => $checkIn ? Room::first()->id : null,
        ]);
        if ($checkIn) {
            $g->update(['id_number' => 'X1']);
            app(FrontDeskService::class)->checkIn($r);
        }

        return $r->fresh();
    }
}
