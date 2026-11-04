<?php

namespace Tests\Feature\HR;

use App\Modules\HR\Domain\Contract;
use App\Modules\HR\Domain\Department;
use App\Modules\HR\Domain\Employee;
use App\Modules\HR\Workforce\Domain\Roster;
use App\Modules\HR\Workforce\Domain\ShiftRequest;
use App\Platform\Events\StoredEvent;
use Tests\TestCase;

/** GAPI: training records tab, kiosk clock-in screen and manager cancel of shift requests. */
class GapiHrTest extends TestCase
{
    private Department $dept;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dept = Department::create(['code' => 'FO', 'name' => 'Front office', 'cost_center' => 'rooms']);
    }

    private function emp(string $n): Employee
    {
        $e = Employee::create(['number' => $n, 'first_name' => "Emp$n", 'last_name' => 'Test', 'hire_date' => now()->subYear()->toDateString(), 'department_id' => $this->dept->id]);
        Contract::create(['employee_id' => $e->id, 'kind' => 'CDI', 'start_date' => $e->hire_date, 'base_salary' => 200000, 'hours_per_week' => 40]);

        return $e;
    }

    private function receptionist(): void
    {
        $this->actAs($this->makeUser($this->group, 'receptionist', allHotels: true), $this->hotel);
    }

    public function test_training_records_permitted_and_forbidden(): void
    {
        $e = $this->emp('T1');
        $this->getJson("/api/v1/hr/talent/employees/{$e->id}/training")->assertOk()->assertExactJson([]);
        $this->actingAs($this->user)->get("/hr/employees/{$e->id}")->assertOk();

        $this->receptionist();
        $this->getJson("/api/v1/hr/talent/employees/{$e->id}/training")->assertForbidden();
        $this->postJson("/api/v1/hr/talent/employees/{$e->id}/certifications", ['cert_type_id' => 1, 'issued_on' => '2026-01-01'])->assertForbidden();
    }

    public function test_kiosk_page_board_and_pin_failures(): void
    {
        $e = $this->emp('K1');
        $this->putJson("/api/v1/workforce/employees/{$e->id}/pin", ['pin' => '4321'])->assertOk();

        $this->actingAs($this->user)->get('/clock/kiosk')->assertOk()->assertInertia(fn ($p) => $p->component('HR/WorkforceKiosk'));
        $board = $this->getJson('/api/v1/workforce/kiosk/board')->assertOk()->json();
        $this->assertSame([['number' => 'K1', 'name' => 'EmpK1 T.']], $board['staff']);

        $this->postJson('/api/v1/workforce/kiosk/in', ['number' => 'K1', 'pin' => '0000'])->assertStatus(403);
        $this->postJson('/api/v1/workforce/kiosk/in', ['number' => 'NOPE', 'pin' => '4321'])->assertStatus(404);
        $this->postJson('/api/v1/workforce/kiosk/in', ['number' => 'K1', 'pin' => '4321'])->assertOk()->assertJsonPath('employee', $e->full_name);
        $this->assertSame('in', $this->getJson('/api/v1/workforce/kiosk/board')->json('recent.0.direction'));

        $this->receptionist();
        $this->get('/clock/kiosk')->assertForbidden();
        $this->getJson('/api/v1/workforce/kiosk/board')->assertForbidden();
        $this->postJson('/api/v1/workforce/kiosk/in', ['number' => 'K1', 'pin' => '4321'])->assertForbidden();
    }

    public function test_manager_cancel_needs_permission_and_reason_and_is_audited(): void
    {
        $a = $this->emp('C1');
        $roster = Roster::create(['week_start' => now()->addWeeks(2)->startOfWeek()->toDateString(), 'status' => 'published']);
        $shift = $roster->shifts()->create(['employee_id' => null, 'department_id' => $this->dept->id, 'date' => now()->addWeeks(2)->startOfWeek()->toDateString(), 'starts_at' => '06:00', 'ends_at' => '14:00']);
        $req = ShiftRequest::create(['kind' => 'claim', 'roster_shift_id' => $shift->id, 'requester_id' => $a->id, 'status' => 'pending']);
        $gm = $this->user;

        $this->receptionist();
        $this->postJson("/api/v1/workforce/requests/{$req->id}/manager-cancel", ['reason' => 'Overstaffed'])->assertForbidden();

        $this->actAs($gm, $this->hotel);
        $this->postJson("/api/v1/workforce/requests/{$req->id}/manager-cancel", [])->assertStatus(422);
        $this->postJson("/api/v1/workforce/requests/{$req->id}/manager-cancel", ['reason' => 'Overstaffed'])->assertOk()->assertJsonPath('status', 'cancelled');
        $req->refresh();
        $this->assertSame($gm->id, $req->decided_by);
        $ev = StoredEvent::where('type', 'hr.shift_request_cancelled')->where('aggregate_id', $req->id)->firstOrFail();
        $this->assertSame('Overstaffed', $ev->payload['reason']);
        $this->assertSame($gm->id, $ev->actor_id);

        $this->postJson("/api/v1/workforce/requests/{$req->id}/manager-cancel", ['reason' => 'Again'])->assertStatus(422);
    }
}
