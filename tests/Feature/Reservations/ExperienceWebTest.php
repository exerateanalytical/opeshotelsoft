<?php

namespace Tests\Feature\Reservations;

use Tests\TestCase;

/** R-B screens render for staff with the right permission, are refused without, and the public review page is open. */
class ExperienceWebTest extends TestCase
{
    public function test_staff_pages_render_and_are_guarded(): void
    {
        foreach (['arrivals', 'group-booking', 'rooming-import', 'channel-health'] as $p) {
            $this->get("/reservations/$p")->assertOk();
        }
        $this->actAs($this->makeUser($this->group, 'pos-cashier', allHotels: true), $this->hotel);
        $this->get('/reservations/arrivals')->assertForbidden();
    }

    public function test_public_review_page_is_reachable_without_login(): void
    {
        auth()->forgetGuards();
        $this->get("/book/{$this->group->slug}/review?c=R-1&lang=en")->assertOk();
    }

    public function test_permissions_seeded_for_front_desk_roles(): void
    {
        $recep = $this->makeUser($this->group, 'receptionist', allHotels: true);
        $owner = $this->makeUser($this->group, 'hotel-owner', allHotels: true);
        $this->assertTrue($recep->can('experience.manage'));
        $this->assertTrue($owner->can('experience.view'));
        $this->assertFalse($owner->can('experience.manage'));
    }
}
