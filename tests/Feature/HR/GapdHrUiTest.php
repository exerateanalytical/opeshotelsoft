<?php

namespace Tests\Feature\HR;

use App\Modules\HR\Domain\Contract;
use App\Modules\HR\Domain\Department;
use App\Modules\HR\Domain\Employee;
use App\Modules\HR\Domain\Position;
use Tests\TestCase;

/** GAPD: endpoints wired by the HR / workforce / people / talent UI gaps; permitted roles succeed, others get 403. */
class GapdHrUiTest extends TestCase
{
    private Department $dept;

    private Position $pos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dept = Department::create(['code' => 'FB', 'name' => 'Food and beverage', 'cost_center' => 'fb']);
        $this->pos = Position::create(['department_id' => $this->dept->id, 'title' => 'Chef']);
    }

    private function emp(string $n, ?Position $pos = null): Employee
    {
        $e = Employee::create(['number' => $n, 'first_name' => "Emp$n", 'last_name' => 'Test', 'hire_date' => now()->subYear()->toDateString(), 'department_id' => $this->dept->id,
            'position_id' => ($pos ?? $this->pos)->id, 'email' => "e$n@test.cm", 'base_salary' => 200000]);
        Contract::create(['employee_id' => $e->id, 'kind' => 'CDI', 'start_date' => $e->hire_date, 'base_salary' => 200000, 'hours_per_week' => 40]);

        return $e;
    }

    private function receptionist(): void
    {
        $this->actAs($this->makeUser($this->group, 'receptionist', allHotels: true), $this->hotel);
    }

    public function test_pages_render_for_hr_manager(): void
    {
        foreach (['/hr/workforce' => 'HR/WorkforceRoster', '/hr/workforce/requests' => 'HR/WorkforceRequests', '/hr/workforce/time' => 'HR/WorkforceTime', '/hr/lifecycle' => 'HR/Lifecycle', '/people/exits' => 'People/Exits', '/people/discipline' => 'People/Discipline', '/hr/talent/planning' => 'HR/TalentPlanning'] as $url => $component) {
            $this->actingAs($this->user)->get($url)->assertOk()->assertInertia(fn ($p) => $p->component($component));
        }
    }

    public function test_successor_rows_carry_an_id_that_can_be_removed(): void
    {
        $this->emp('1');
        $cand = $this->emp('2', Position::create(['department_id' => $this->dept->id, 'title' => 'Sous chef']));
        $this->postJson('/api/v1/hr/talent/key-positions', ['position_id' => $this->pos->id, 'risk' => 'high'])->assertSuccessful();
        $this->postJson('/api/v1/hr/talent/successors', ['position_id' => $this->pos->id, 'employee_id' => $cand->id, 'readiness' => 'now'])->assertCreated();
        $id = $this->getJson('/api/v1/hr/talent/succession')->assertOk()->json('positions.0.successors.0.id');
        $this->assertIsInt($id);

        $gm = $this->user;
        $this->receptionist();
        $this->deleteJson("/api/v1/hr/talent/successors/$id")->assertForbidden();

        $this->actAs($gm, $this->hotel);
        $this->deleteJson("/api/v1/hr/talent/successors/$id")->assertNoContent();
        $this->assertCount(0, $this->getJson('/api/v1/hr/talent/succession')->json('positions.0.successors'));
    }

    public function test_workforce_pin_rules_and_forecast_permissions(): void
    {
        $e = $this->emp('1');
        $this->putJson("/api/v1/workforce/employees/$e->id/pin", ['pin' => '4821'])->assertOk();
        $this->putJson('/api/v1/workforce/dept-rules', ['department_id' => $this->dept->id, 'driver' => 'inhouse', 'units_per_staff' => 10, 'min_staff' => 1,
            'templates' => [['name' => 'Morning', 'starts_at' => '07:00', 'ends_at' => '15:00']]])->assertOk();
        $this->getJson('/api/v1/workforce/dept-rules')->assertOk();
        $from = now()->toDateString();
        $this->getJson("/api/v1/workforce/forecast?from=$from&to=".now()->addDays(13)->toDateString())->assertOk()->assertJsonCount(14);

        $this->receptionist();
        $this->putJson("/api/v1/workforce/employees/$e->id/pin", ['pin' => '4821'])->assertForbidden();
        $this->putJson('/api/v1/workforce/dept-rules', ['department_id' => $this->dept->id, 'driver' => 'none', 'units_per_staff' => 1, 'min_staff' => 0])->assertForbidden();
        $this->getJson("/api/v1/workforce/forecast?from=$from&to=$from")->assertForbidden();
    }

    public function test_lifecycle_templates_and_apply_due_permissions(): void
    {
        $tpl = $this->getJson('/api/v1/hr/contract-templates')->assertOk()->json('templates.0');
        $this->putJson('/api/v1/hr/contract-templates', ['kind' => $tpl['kind'], 'locale' => $tpl['locale'], 'title' => 'Custom', 'body' => 'Body {{employee_name}}'])->assertSuccessful();
        $this->assertTrue($this->getJson('/api/v1/hr/contract-templates')->json('templates.0.custom'));

        $this->receptionist();
        $this->getJson('/api/v1/hr/contract-templates')->assertForbidden();
        $this->putJson('/api/v1/hr/contract-templates', ['kind' => $tpl['kind'], 'locale' => $tpl['locale'], 'title' => 'X', 'body' => 'Y'])->assertForbidden();
        $this->postJson('/api/v1/hr/lifecycle/apply-due')->assertForbidden();
    }

    public function test_people_feedback_summary_and_withdraw_permissions(): void
    {
        $e = $this->emp('1');
        $this->getJson("/api/v1/people/employees/$e->id/feedback-summary")->assertOk()->assertJsonPath('count', 0);

        $this->receptionist();
        $this->getJson("/api/v1/people/employees/$e->id/feedback-summary")->assertForbidden();
    }
}
