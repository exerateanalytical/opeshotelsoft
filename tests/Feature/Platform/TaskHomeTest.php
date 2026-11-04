<?php

namespace Tests\Feature\Platform;

use App\Modules\Housekeeping\Domain\HkTask;
use App\Modules\Property\Domain\Room;
use App\Modules\ServiceRequests\Application\ServiceRequestService;
use App\Modules\ServiceRequests\Domain\ServiceRequest;
use Database\Seeders\DemoUsersSeeder;
use Tests\TestCase;

/** Staff task home: "my work" first, filtered per role, one-tap actions go through the owning modules. */
class TaskHomeTest extends TestCase
{
    private function asRole(string $role)
    {
        $user = $this->makeUser($this->group, $role, hotels: [$this->hotel]);
        $this->actAs($user, $this->hotel);

        return $user;
    }

    private function request(string $category = 'housekeeping'): ServiceRequest
    {
        return app(ServiceRequestService::class)->create([
            'hotel_id' => $this->hotel->id, 'room_number' => '204', 'category' => $category, 'items' => [$category === 'room_service' ? 'meal' : 'towels'], 'source' => 'reception',
        ]);
    }

    public function test_page_route_is_named_and_open_to_every_demo_role(): void
    {
        $this->assertSame('/tasks', route('tasks.home', [], false));
        foreach (collect(DemoUsersSeeder::USERS)->map(fn ($u) => $u[1])->unique() as $role) {
            $this->asRole($role);
            $this->get('/tasks', ['Accept' => 'text/html'])->assertOk();
            $this->getJson('/api/v1/tasks/home')->assertOk()->assertJsonStructure(['generated_at', 'sections', 'counts', 'overdue_total']);
        }
    }

    public function test_each_role_only_gets_its_own_sections(): void
    {
        $this->request();
        $has = fn (string $role) => array_keys($this->asRoleJson($role)['sections']);

        $this->assertEqualsCanonicalizing(['people_tasks', 'service_requests', 'housekeeping'], $has('room-attendant'));
        $this->assertEqualsCanonicalizing(['people_tasks', 'service_requests', 'work_orders'], $has('maintenance-technician'));
        $this->assertSame(['people_tasks'], $has('storekeeper'));
        $this->assertSame(['people_tasks'], $has('accountant'));
        $this->assertContains('approvals', $has('hr-officer'));
        $this->assertNotContains('approvals', $has('hotel-owner'));
        $this->assertContains('escalations', $has('housekeeping-manager'));
        $this->assertNotContains('escalations', $has('room-attendant'));
    }

    private function asRoleJson(string $role): array
    {
        $this->asRole($role);

        return $this->getJson('/api/v1/tasks/home')->assertOk()->json();
    }

    public function test_service_request_shows_for_its_department_with_permission_aware_actions(): void
    {
        $r = $this->request('housekeeping');

        $attendant = $this->asRoleJson('room-attendant')['sections']['service_requests'];
        $this->assertSame($r->id, $attendant[0]['id']);
        $this->assertTrue($attendant[0]['can_acknowledge']);

        // The POS cashier holds view only and is not in the housekeeping department: nothing to act on.
        $this->assertSame([], $this->asRoleJson('pos-cashier')['sections']['service_requests']);

        $this->asRole('room-attendant');
        $this->postJson("/api/v1/service-requests/{$r->id}/acknowledge")->assertOk();
        $this->assertSame('acknowledged', $r->fresh()->status);
        $row = $this->getJson('/api/v1/tasks/home')->json('sections.service_requests.0');
        $this->assertFalse($row['can_acknowledge']);
        $this->assertTrue($row['can_complete']);
    }

    public function test_view_only_role_is_refused_the_one_tap_action(): void
    {
        $r = $this->request('room_service');
        $this->asRole('pos-cashier');
        $this->assertFalse($this->getJson('/api/v1/tasks/home')->json('sections.service_requests.0.can_acknowledge'));
        $this->postJson("/api/v1/service-requests/{$r->id}/acknowledge")->assertForbidden();
    }

    public function test_housekeeping_jobs_are_the_attendants_own(): void
    {
        $mine = $this->makeUser($this->group, 'room-attendant', hotels: [$this->hotel]);
        $other = $this->makeUser($this->group, 'room-attendant', hotels: [$this->hotel]);
        [$type] = $this->seedInventory(2);
        [$r1, $r2] = Room::orderBy('id')->get();
        $today = $this->hotel->business_date->toDateString();
        $t1 = HkTask::create(['room_id' => $r1->id, 'date' => $today, 'kind' => 'checkout', 'status' => 'pending', 'priority' => 1, 'assigned_to' => $mine->id]);
        HkTask::create(['room_id' => $r2->id, 'date' => $today, 'kind' => 'checkout', 'status' => 'pending', 'priority' => 1, 'assigned_to' => $other->id]);

        $this->actAs($mine, $this->hotel);
        $rows = $this->getJson('/api/v1/tasks/home')->assertOk()->json('sections.housekeeping');
        $this->assertCount(1, $rows);
        $this->assertSame($t1->id, $rows[0]['id']);
        $this->assertTrue($rows[0]['can_start']);

        $this->actAs($other, $this->hotel);
        $this->postJson("/api/v1/housekeeping/tasks/{$t1->id}/start")->assertForbidden(); // not mine: the module refuses
        $this->actAs($mine, $this->hotel);
        $this->postJson("/api/v1/housekeeping/tasks/{$t1->id}/start")->assertOk();
    }

    public function test_other_hotels_work_never_appears(): void
    {
        $this->request();
        [$group2, $hotel2] = $this->makeHotel('Other Group', 'OTH');
        $this->provision($hotel2);
        $user2 = $this->makeUser($group2, 'room-attendant', hotels: [$hotel2]);
        $this->actAs($user2, $hotel2);
        $home = $this->getJson('/api/v1/tasks/home')->assertOk()->json();
        $this->assertSame([], $home['sections']['service_requests']);
        $this->assertSame([], $home['sections']['housekeeping']);
    }

    public function test_lang_files_have_identical_keys_with_the_tasks_prefix(): void
    {
        $en = json_decode(file_get_contents(lang_path('en/tasks.json')), true);
        $fr = json_decode(file_get_contents(lang_path('fr/tasks.json')), true);
        $this->assertSame(array_keys($en), array_keys($fr));
        foreach (array_keys($en) as $k) {
            $this->assertTrue($k === 'nav.tasks' || str_starts_with($k, 'tasks.'), $k);
        }
        $this->assertSame('Mes tâches', $fr['nav.tasks']);
        $this->assertSame('My tasks', $en['nav.tasks']);
    }
}
