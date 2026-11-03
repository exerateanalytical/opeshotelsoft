<?php

namespace Tests\Feature\ServiceRequests;

use App\Modules\ServiceRequests\Application\ServiceRequestService;
use Tests\TestCase;

class PermissionsTest extends TestCase
{
    private function req(): int
    {
        return app(ServiceRequestService::class)->create([
            'hotel_id' => $this->hotel->id, 'room_number' => '204', 'category' => 'housekeeping', 'items' => ['towels'], 'source' => 'reception',
        ])->id;
    }

    public function test_roles_without_the_permissions_are_refused(): void
    {
        $id = $this->req();
        $accountant = $this->makeUser($this->group, 'accountant', hotels: [$this->hotel]);
        $this->actAs($accountant, $this->hotel);
        $this->getJson('/api/v1/service-requests')->assertForbidden();
        $this->getJson('/api/v1/service-requests/feed')->assertForbidden();
        $this->getJson('/api/v1/service-requests/voice-settings')->assertForbidden();
        $this->getJson('/api/v1/service-requests/settings')->assertForbidden();
        $this->postJson('/api/v1/service-requests', ['room_number' => '1', 'category' => 'other'])->assertForbidden();
        $this->postJson("/api/v1/service-requests/$id/acknowledge")->assertForbidden();
        $this->get('/service-requests')->assertForbidden();
    }

    public function test_view_only_can_read_but_not_act(): void
    {
        $id = $this->req();
        $cashier = $this->makeUser($this->group, 'pos-cashier', hotels: [$this->hotel]); // view + voice, no manage
        $this->actAs($cashier, $this->hotel);
        $this->getJson('/api/v1/service-requests')->assertOk();
        $this->getJson('/api/v1/service-requests/voice-settings')->assertOk();
        $this->postJson("/api/v1/service-requests/$id/acknowledge")->assertForbidden();
        $this->postJson("/api/v1/service-requests/$id/complete")->assertForbidden();
        $this->putJson('/api/v1/service-requests/settings', ['sla' => ['housekeeping' => 5]])->assertForbidden();
        $this->postJson('/api/v1/service-requests', ['room_number' => '1', 'category' => 'other'])->assertForbidden();
    }

    public function test_auditor_can_view_the_board_but_has_no_voice(): void
    {
        $auditor = $this->makeUser($this->group, 'auditor', hotels: [$this->hotel]);
        $this->actAs($auditor, $this->hotel);
        $this->getJson('/api/v1/service-requests')->assertOk();
        $this->getJson('/api/v1/service-requests/voice-settings')->assertForbidden();
    }

    public function test_role_grants(): void
    {
        foreach (['receptionist', 'front-office-manager', 'night-auditor', 'housekeeping-manager', 'room-attendant', 'maintenance-technician', 'fnb-manager'] as $role) {
            $u = $this->makeUser($this->group, $role, hotels: [$this->hotel]);
            $this->assertTrue($u->can('servicerequests.view') && $u->can('servicerequests.manage') && $u->can('servicerequests.voice'), $role);
        }
        $this->assertTrue($this->user->can('servicerequests.manage')); // general manager
    }

    public function test_pages_are_guarded(): void
    {
        $this->actingAs($this->user, 'web');
        $this->get('/service-requests')->assertOk();
        $this->get('/service-requests/settings')->assertOk();
        $this->actingAs($this->makeUser($this->group, 'storekeeper', hotels: [$this->hotel]), 'web');
        $this->get('/service-requests')->assertForbidden();
        $this->get('/service-requests/settings')->assertForbidden();
    }

    public function test_another_hotels_request_cannot_be_touched(): void
    {
        [$g2, $other] = $this->makeHotel('Other Group', 'OTH');
        $this->provision($other);
        $outsider = $this->makeUser($g2, 'general-manager', allHotels: true);
        $id = $this->req();
        $this->actAs($outsider, $other);
        $this->getJson("/api/v1/service-requests/$id")->assertNotFound();
        $this->postJson("/api/v1/service-requests/$id/acknowledge")->assertNotFound();
        $this->getJson('/api/v1/service-requests')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_translation_files_have_identical_keys(): void
    {
        $fr = array_keys(json_decode(file_get_contents(lang_path('fr/servicerequests.json')), true));
        $en = array_keys(json_decode(file_get_contents(lang_path('en/servicerequests.json')), true));
        sort($fr);
        sort($en);
        $this->assertSame($fr, $en);
        $this->assertNotEmpty($fr);
    }
}
