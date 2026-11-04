<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Tests\Feature\Guests\RepairsRoleSeed;
use Tests\TestCase;

/** SEC1 audit regression: tenancy:users:hotel-scoped-admin-grants-all-hotels. */
class AuditTenancyTest extends TestCase
{
    use RepairsRoleSeed;

    public function test_hotel_scoped_manager_cannot_escalate_to_group_wide_access(): void
    {
        [, $other] = $this->makeHotel('Other', 'OTH', $this->group);
        $gm = $this->makeUser($this->group, 'general-manager', hotels: [$this->hotel]);
        $colleague = $this->makeUser($this->group, 'receptionist', hotels: [$this->hotel]);
        $this->actAs($gm, $this->hotel);

        // Self or colleague: no all_hotels grant from a hotel-scoped administrator.
        $this->patchJson("/api/v1/users/{$gm->id}", ['all_hotels' => true])->assertForbidden();
        $this->patchJson("/api/v1/users/{$colleague->id}", ['all_hotels' => true])->assertForbidden();
        // No hotel outside the actor's own allow-list, no role the actor does not hold.
        $this->patchJson("/api/v1/users/{$colleague->id}", ['hotel_ids' => [$other->id]])->assertForbidden();
        $this->patchJson("/api/v1/users/{$colleague->id}", ['roles' => ['group-owner']])->assertForbidden();
        $this->postJson('/api/v1/users', ['name' => 'X', 'email' => 'x@test.cm', 'roles' => ['receptionist'], 'all_hotels' => true])->assertForbidden();

        $this->assertFalse(User::findOrFail($gm->id)->all_hotels);
        $this->assertFalse(User::findOrFail($colleague->id)->all_hotels);
        $this->assertFalse(User::findOrFail($colleague->id)->hasRole('group-owner'));

        // Within scope the manager still administers staff.
        $this->patchJson("/api/v1/users/{$colleague->id}", ['hotel_ids' => [$this->hotel->id], 'roles' => ['general-manager']])->assertOk();
        // A group-wide administrator keeps the full power.
        $this->actAs($this->user, $this->hotel);
        $this->patchJson("/api/v1/users/{$colleague->id}", ['all_hotels' => true, 'hotel_ids' => [$other->id]])->assertOk();
        $this->assertTrue(User::findOrFail($colleague->id)->all_hotels);
    }
}
