<?php

namespace Tests\Feature\FrontOffice;

use App\Modules\Guests\Domain\Guest;
use Tests\TestCase;

/** GAPE: front-of-house / revenue screens wired to existing endpoints (MISSING-UI table 2 rows 5-9, 28, 32, 33; table 3 rows 1, 5, 6, 38, 39). */
class GapeFrontOfHouseTest extends TestCase
{
    private function as(string $role): void
    {
        $this->actAs($this->makeUser($this->group, $role, allHotels: true), $this->hotel);
    }

    public function test_fee_rules_page_needs_frontdesk_configure(): void
    {
        $this->withoutVite();
        $fom = $this->makeUser($this->group, 'front-office-manager', allHotels: true);
        $this->actingAs($fom, 'web')->get('/front-desk/checkout-fee-rules')->assertOk();

        $desk = $this->makeUser($this->group, 'receptionist', allHotels: true);
        $this->actingAs($desk, 'web')->get('/front-desk/checkout-fee-rules')->assertForbidden();
    }

    public function test_fee_rules_api_save_permission(): void
    {
        $rules = ['rules' => [['kind' => 'late', 'after_hour' => 14, 'charge_code' => 'ROOM', 'fee_type' => 'percent_of_night', 'value' => 50, 'label' => 'Late', 'active' => true]]];
        $this->as('receptionist');
        $this->getJson('/api/v1/front-desk/checkout-fee-rules')->assertOk();
        $this->putJson('/api/v1/front-desk/checkout-fee-rules', $rules)->assertForbidden();

        $this->as('front-office-manager');
        $this->putJson('/api/v1/front-desk/checkout-fee-rules', $rules)->assertOk()->assertJsonPath('0.after_hour', 14);
    }

    public function test_reservations_export_is_registered_and_permission_checked(): void
    {
        [$type, $plan, $guest] = $this->seedInventory();
        $this->postJson('/api/v1/reservations', [
            'guest_id' => $guest->id, 'room_type_id' => $type->id, 'rate_plan_id' => $plan->id,
            'arrival' => now()->toDateString(), 'departure' => now()->addDay()->toDateString(),
        ])->assertSuccessful();

        $csv = $this->get('/api/v1/exports/reservations?format=csv')->assertOk()->getContent();
        $this->assertStringContainsString('number', $csv);
        $this->assertStringContainsString($guest->last_name, $csv);

        $this->as('storekeeper');
        $this->get('/api/v1/exports/reservations?format=csv')->assertForbidden();
    }

    public function test_walk_and_upsell_lists_permission(): void
    {
        $this->as('receptionist');
        $this->getJson('/api/v1/revenue/upsell-offers')->assertOk();
        $this->as('storekeeper');
        $this->getJson('/api/v1/revenue/walks')->assertForbidden();
        $this->getJson('/api/v1/revenue/upsell-offers')->assertForbidden();
    }

    public function test_escalate_permission(): void
    {
        $this->as('receptionist');
        $this->postJson('/api/v1/service-requests/escalate')->assertOk()->assertJsonStructure(['late', 'overdue']);
        $this->as('storekeeper');
        $this->postJson('/api/v1/service-requests/escalate')->assertForbidden();
    }

    public function test_contact_preferences_permission(): void
    {
        $guest = Guest::create(['first_name' => 'Ada', 'last_name' => 'Mbarga']);
        $this->as('receptionist');
        $this->putJson("/api/v1/notifications/preferences/{$guest->id}", ['sms' => false])->assertOk()->assertJsonPath('sms', false);
        $this->as('storekeeper');
        $this->putJson("/api/v1/notifications/preferences/{$guest->id}", ['sms' => true])->assertForbidden();
    }
}
