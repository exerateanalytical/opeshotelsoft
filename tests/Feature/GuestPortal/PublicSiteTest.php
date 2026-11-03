<?php

namespace Tests\Feature\GuestPortal;

use App\Modules\GuestPortal\Domain\OnlineCheckin;
use App\Modules\Loyalty\Application\LoyaltyService;
use App\Modules\Reservations\Domain\Reservation;
use Tests\TestCase;

class PublicSiteTest extends TestCase
{
    /**
     * Workaround: database/seeders/permissions/w01.php assigns $roles, which
     * overwrites the seeder's own $roles inside run() (require shares scope), so
     * wildcard roles go missing. Remove once the seeder isolates the require.
     */
    protected function makeUser(\App\Modules\Tenancy\Domain\Group $group, string $role, bool $allHotels = false, array $hotels = []): \App\Models\User
    {
        if ($role === 'general-manager') {
            \Spatie\Permission\Models\Role::findOrCreate($role, 'api')->syncPermissions(\Spatie\Permission\Models\Permission::where('guard_name', 'api')->get());
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        }

        return parent::makeUser($group, $role, $allHotels, $hotels);
    }

    private function book(): array
    {
        [$type, $plan] = $this->seedInventory(rooms: 2, rate: 40_000);
        $plan->update(['refundable' => true, 'cancellation_hours' => 48]);
        $this->hotel->update(['settings' => ['description' => 'Au cœur de Douala', 'photos' => ['/img/a.jpg']]]);
        $this->app['auth']->forgetGuards();

        $b = $this->postJson("/api/v1/booking/{$this->group->slug}/reservations", [
            'hotel_code' => $this->hotel->code, 'room_type_id' => $type->id, 'rate_plan_id' => $plan->id,
            'arrival' => now()->addDays(5)->toDateString(), 'departure' => now()->addDays(7)->toDateString(), 'accept_terms' => true,
            'guest' => ['first_name' => 'Awa', 'last_name' => 'Ngono', 'email' => 'awa@example.cm', 'phone' => '+237690000001'],
        ])->assertCreated()->json();

        return $b;
    }

    public function test_public_pages_render_without_login_and_404_for_unknown_group(): void
    {
        $this->app['auth']->forgetGuards();
        $this->withoutVite();
        foreach (['', '/search', '/details', '/confirmation', '/manage'] as $path) {
            $this->get("/book/{$this->group->slug}$path?lang=en")->assertOk();
        }
        $this->get('/book/no-such-group')->assertNotFound();
    }

    public function test_hotel_list_exposes_description_and_photos(): void
    {
        $this->book();
        $this->getJson("/api/v1/booking/{$this->group->slug}/hotels")->assertOk()
            ->assertJsonPath('hotels.0.description', 'Au cœur de Douala')
            ->assertJsonPath('hotels.0.photos.0', '/img/a.jpg')
            ->assertJsonMissingPath('hotels.0.settings');
    }

    public function test_lookup_shows_policy_and_online_check_in_is_stored(): void
    {
        $b = $this->book();
        $slug = $this->group->slug;
        $look = $this->postJson("/api/v1/booking/$slug/reservations/lookup", ['confirmation' => $b['confirmation'], 'last_name' => 'ngono'])
            ->assertOk()->assertJsonPath('policy.refundable', true)->assertJsonPath('policy.cancellation_hours', 48)
            ->assertJsonPath('can_cancel', true)->assertJsonPath('loyalty', null)->json();

        $payload = ['confirmation' => $b['confirmation'], 'id_type' => 'passport', 'id_number' => 'P123', 'nationality' => 'CM', 'eta' => '18:30'];
        $this->postJson("/api/v1/booking/$slug/reservations/check-in", $payload + ['token' => 'forged'])->assertForbidden();
        $this->postJson("/api/v1/booking/$slug/reservations/check-in", $payload + ['token' => $look['manage_token']])->assertCreated();

        $this->assertSame('P123', OnlineCheckin::first()->id_number);
        $this->assertStringStartsWith('18:30', (string) Reservation::withoutGlobalScopes()->where('number', $b['confirmation'])->value('eta'));
        $this->postJson("/api/v1/booking/$slug/reservations/lookup", ['confirmation' => $b['confirmation'], 'last_name' => 'Ngono'])
            ->assertJsonPath('checkin.id_type', 'passport');
    }

    /** The Manage page posts this payload to the W03 Payments deposit endpoint. */
    public function test_deposit_payload_from_manage_page_creates_payment_intent(): void
    {
        $b = $this->book();
        Reservation::withoutGlobalScopes()->where('number', $b['confirmation'])->update(['deposit_required' => 24_000]);
        $url = "/api/v1/booking/{$this->group->slug}/reservations/{$b['confirmation']}/deposit";
        $body = ['provider' => 'mtn_momo', 'payer_phone' => '+237690000001', 'return_url' => 'http://localhost/book/x/manage'];

        $this->postJson($url, $body + ['token' => 'forged'])->assertForbidden();
        $this->postJson($url, $body + ['token' => $b['manage_token']])->assertCreated()
            ->assertJsonPath('amount', 24_000)->assertJsonStructure(['reference', 'status', 'redirect_url']);
    }

    public function test_loyalty_card_shown_when_guest_is_member(): void
    {
        $this->putJson('/api/v1/loyalty/program', ['spend_per_point' => 1000, 'point_value' => 10])->assertOk();
        $b = $this->book();
        $r = Reservation::withoutGlobalScopes()->with('guest')->where('number', $b['confirmation'])->first();
        $m = app(LoyaltyService::class)->enrol($r->guest);

        $this->postJson("/api/v1/booking/{$this->group->slug}/reservations/lookup", ['confirmation' => $b['confirmation'], 'last_name' => 'Ngono'])
            ->assertOk()->assertJsonPath('loyalty.number', $m->number)->assertJsonPath('loyalty.qr_text', 'OPES-LOYALTY:'.$m->number);
    }
}
