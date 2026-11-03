<?php

namespace Tests\Feature\Platform;

use App\Modules\Guests\Domain\Guest;
use App\Modules\Property\Domain\RoomType;
use App\Platform\Tenancy\TenantContext;
use Tests\TestCase;

/** Certification check: one hotel/group can never read another's data. */
class TenancyIsolationTest extends TestCase
{
    public function test_user_cannot_select_a_hotel_of_another_group(): void
    {
        [, $otherHotel] = $this->makeHotel('Other Group', 'OTH');

        $this->withHeaders(['X-Hotel-Id' => $otherHotel->id])->getJson('/api/v1/room-types')->assertForbidden();
    }

    public function test_hotel_scoped_user_cannot_select_sister_hotel(): void
    {
        [, $sister] = $this->makeHotel('ignored', 'SIS', $this->group);
        $receptionist = $this->makeUser($this->group, 'receptionist', hotels: [$this->hotel]);
        $this->actAs($receptionist, $this->hotel);

        $this->withHeaders(['X-Hotel-Id' => $sister->id])->getJson('/api/v1/reservations')->assertForbidden();
        $this->withHeaders(['X-Hotel-Id' => $this->hotel->id])->getJson('/api/v1/reservations')->assertOk();
    }

    public function test_group_dashboard_lists_only_hotels_the_user_may_access(): void
    {
        $this->makeHotel('ignored', 'SIS', $this->group);
        $this->getJson('/api/v1/reports/group-dashboard')->assertOk()->assertJsonCount(2, 'hotels');

        $owner = $this->makeUser($this->group, 'hotel-owner', hotels: [$this->hotel]);
        $this->actAs($owner, $this->hotel);
        $this->getJson('/api/v1/reports/group-dashboard')->assertOk()
            ->assertJsonCount(1, 'hotels')->assertJsonPath('hotels.0.hotel.code', 'AKW');
    }

    public function test_hotel_data_is_not_visible_from_another_hotel(): void
    {
        RoomType::create(['code' => 'STD', 'name' => 'Standard']);
        [, $sister] = $this->makeHotel('ignored', 'SIS', $this->group);
        $this->actAs($this->user, $sister);

        $this->getJson('/api/v1/room-types')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_guest_profiles_are_shared_in_group_but_not_across_groups(): void
    {
        $guest = Guest::create(['first_name' => 'Awa', 'last_name' => 'Ndiaye']);
        [, $sister] = $this->makeHotel('ignored', 'SIS', $this->group);

        $this->actAs($this->user, $sister);
        $this->getJson("/api/v1/guests/{$guest->id}")->assertOk();

        [$otherGroup, $otherHotel] = $this->makeHotel('Other Group', 'OTH');
        $outsider = $this->makeUser($otherGroup, 'general-manager', allHotels: true);
        $this->actAs($outsider, $otherHotel);
        $this->getJson("/api/v1/guests/{$guest->id}")->assertNotFound();
    }

    public function test_writes_are_stamped_with_active_tenant(): void
    {
        $this->postJson('/api/v1/room-types', ['code' => 'STE', 'name' => 'Suite'])->assertCreated();

        $row = app(TenantContext::class)->withoutScope(fn () => RoomType::where('code', 'STE')->first());
        $this->assertSame($this->hotel->id, $row->hotel_id);
        $this->assertSame($this->group->id, $row->group_id);
    }

    public function test_permissions_are_enforced(): void
    {
        $attendant = $this->makeUser($this->group, 'room-attendant', hotels: [$this->hotel]);
        $this->actAs($attendant, $this->hotel);

        $this->postJson('/api/v1/room-types', ['code' => 'X', 'name' => 'X'])->assertForbidden();
        $this->getJson('/api/v1/reservations')->assertForbidden();
    }
}
