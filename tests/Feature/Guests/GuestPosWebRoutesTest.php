<?php

namespace Tests\Feature\Guests;

use Tests\TestCase;

class GuestPosWebRoutesTest extends TestCase
{
    use RepairsRoleSeed;

    public function test_pages_are_guarded_by_permission(): void
    {
        $this->withoutVite();
        $clerk = $this->makeUser($this->group, 'receptionist', allHotels: true);
        $this->actingAs($clerk, 'web')->get('/guests/5')->assertOk();
        $this->actingAs($clerk, 'web')->get('/guests/companies/5')->assertOk();
        $this->actingAs($clerk, 'web')->get('/guests/privacy-requests')->assertOk();
        $this->actingAs($clerk, 'web')->get('/pos/checks/5/kot')->assertForbidden();

        $cashier = $this->makeUser($this->group, 'pos-cashier', allHotels: true);
        $this->actingAs($cashier, 'web')->get('/pos/checks/5/kot')->assertOk();
        $this->actingAs($cashier, 'web')->get('/guests/privacy-requests')->assertForbidden();
    }
}
