<?php

namespace Tests\Feature\HR;

use App\Modules\HR\Domain\Contract;
use App\Modules\HR\Domain\Department;
use App\Modules\HR\Domain\Employee;
use App\Modules\HR\Domain\Position;
use App\Modules\HR\Talent\Domain\Certification;
use App\Modules\Notifications\Domain\OutboxMessage;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TalentSkillsTest extends TestCase
{
    private Department $dept;

    private Position $pos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dept = Department::create(['code' => 'FB', 'name' => 'Food and beverage', 'cost_center' => 'fb']);
        $this->pos = Position::create(['department_id' => $this->dept->id, 'title' => 'Chef']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function emp(string $n, ?Position $pos = null, array $extra = []): Employee
    {
        $e = Employee::create(['number' => $n, 'first_name' => "Emp$n", 'last_name' => 'Test', 'hire_date' => now()->subYear()->toDateString(), 'department_id' => $this->dept->id,
            'position_id' => ($pos ?? $this->pos)->id, 'email' => "e$n@test.cm", 'base_salary' => 200000] + $extra);
        Contract::create(['employee_id' => $e->id, 'kind' => 'CDI', 'start_date' => $e->hire_date, 'base_salary' => 200000, 'hours_per_week' => 40]);

        return $e;
    }

    private function skill(string $code = 'HACCP'): int
    {
        return $this->postJson('/api/v1/hr/talent/skills', ['code' => $code, 'name' => "Skill $code"])->assertCreated()->json('id');
    }

    private function certType(array $extra = []): int
    {
        return $this->postJson('/api/v1/hr/talent/cert-types', $extra + ['code' => 'FOODSAFE', 'name' => 'Food safety', 'validity_months' => 12, 'mandatory' => true, 'alert_days' => 30])->assertCreated()->json('id');
    }

    public function test_skills_matrix_and_gaps_report_per_department(): void
    {
        $s = $this->skill();
        $e1 = $this->emp('1');
        $e2 = $this->emp('2');
        $this->postJson('/api/v1/hr/talent/position-skills', ['position_id' => $this->pos->id, 'skill_id' => $s, 'required_level' => 4])->assertSuccessful();
        $this->postJson("/api/v1/hr/talent/employees/$e1->id/skills", ['skill_id' => $s, 'level' => 4])->assertSuccessful();
        $this->postJson("/api/v1/hr/talent/employees/$e2->id/skills", ['skill_id' => $s, 'level' => 2])->assertSuccessful();

        $m = $this->getJson('/api/v1/hr/talent/matrix')->assertOk()->json();
        $rows = collect($m['rows'])->keyBy('employee_id');
        $this->assertFalse($rows[$e1->id]['cells'][$s]['gap']);
        $this->assertTrue($rows[$e2->id]['cells'][$s]['gap']);

        $gaps = $this->getJson('/api/v1/hr/talent/gaps')->assertOk()->json('departments');
        $this->assertSame(1, $gaps[0]['skill_gap_count']);
        $this->assertSame('Emp2 Test', $gaps[0]['skill_gaps'][0]['employee']);
    }

    public function test_certificate_status_expiry_and_mandatory_gap(): void
    {
        $t = $this->certType();
        $e1 = $this->emp('1');
        $e2 = $this->emp('2');
        $e3 = $this->emp('3');
        $this->postJson("/api/v1/hr/talent/employees/$e1->id/certifications", ['cert_type_id' => $t, 'issued_on' => now()->subMonths(2)->toDateString()])->assertCreated();
        $this->postJson("/api/v1/hr/talent/employees/$e2->id/certifications", ['cert_type_id' => $t, 'issued_on' => now()->subMonths(11)->subDays(10)->toDateString()])->assertCreated();
        $this->postJson("/api/v1/hr/talent/employees/$e3->id/certifications", ['cert_type_id' => $t, 'issued_on' => now()->subMonths(14)->toDateString()])->assertCreated();
        $by = collect($this->getJson('/api/v1/hr/talent/certifications')->assertOk()->json())->keyBy('employee_id');
        $this->assertSame('valid', $by[$e1->id]['status']);
        $this->assertSame('expiring', $by[$e2->id]['status']);
        $this->assertSame('expired', $by[$e3->id]['status']);
        $this->assertCount(1, $this->getJson('/api/v1/hr/talent/certifications?status=expired')->json());
        $gaps = $this->getJson('/api/v1/hr/talent/gaps')->json('departments')[0];
        $this->assertSame(1, $gaps['cert_gap_count']); // only the expired one
        $this->postJson("/api/v1/hr/talent/employees/$e1->id/certifications", ['cert_type_id' => $t, 'issued_on' => '2024-05-01', 'expires_on' => '2024-01-01'])->assertUnprocessable();
    }

    public function test_expiry_scan_alerts_once_per_stage_and_renewal_clears_it(): void
    {
        $t = $this->certType();
        $e = $this->emp('1', extra: ['phone' => '699111222']);
        $this->postJson("/api/v1/hr/talent/employees/$e->id/certifications", ['cert_type_id' => $t, 'issued_on' => now()->subMonths(11)->subDays(5)->toDateString()])->assertCreated();
        $this->postJson('/api/v1/hr/talent/certifications/scan')->assertOk()->assertJsonPath('alerts', 1);
        $this->assertGreaterThan(0, OutboxMessage::where('event', 'talent.cert_expiring')->count());
        $this->postJson('/api/v1/hr/talent/certifications/scan')->assertOk()->assertJsonPath('alerts', 0); // not repeated
        Carbon::setTestNow(now()->addMonths(2));
        $this->postJson('/api/v1/hr/talent/certifications/scan')->assertOk()->assertJsonPath('alerts', 1);
        $this->assertGreaterThan(0, OutboxMessage::where('event', 'talent.cert_expired')->count());
        // A renewal supersedes the expired row, so nothing is flagged.
        $this->postJson("/api/v1/hr/talent/employees/$e->id/certifications", ['cert_type_id' => $t, 'issued_on' => now()->toDateString()])->assertCreated();
        $this->assertSame('valid', $this->getJson('/api/v1/hr/talent/certifications')->json('0.status'));
        $this->assertSame(2, Certification::count());
        $this->postJson('/api/v1/hr/talent/certifications/scan')->assertOk()->assertJsonPath('alerts', 0);
    }

    public function test_training_session_attendance_results_cost_and_links(): void
    {
        $s = $this->skill();
        $t = $this->certType();
        $e1 = $this->emp('1');
        $e2 = $this->emp('2');
        $e3 = $this->emp('3');
        $course = $this->postJson('/api/v1/hr/talent/courses', ['title' => 'Food hygiene', 'skill_id' => $s, 'skill_level' => 4, 'cert_type_id' => $t, 'duration_hours' => 6, 'default_cost' => 90000])->assertCreated()->json('id');
        $sess = $this->postJson('/api/v1/hr/talent/sessions', ['course_id' => $course, 'starts_on' => now()->toDateString(), 'capacity' => 3, 'trainer' => 'Ministry'])->assertCreated();
        $sess->assertJsonPath('cost', 90000);
        $id = $sess->json('id');
        $this->postJson("/api/v1/hr/talent/sessions/$id/enroll", ['employee_ids' => [$e1->id, $e2->id, $e3->id]])->assertOk();
        $this->postJson("/api/v1/hr/talent/sessions/$id/enroll", ['employee_ids' => [$e1->id]])->assertUnprocessable(); // duplicate
        $this->postJson("/api/v1/hr/talent/sessions/$id/record", ['employee_id' => $e1->id, 'attendance' => 'attended', 'result' => 'passed', 'score' => 82])->assertOk();
        $this->postJson("/api/v1/hr/talent/sessions/$id/record", ['employee_id' => $e2->id, 'attendance' => 'attended', 'result' => 'failed'])->assertOk();
        $this->postJson("/api/v1/hr/talent/sessions/$id/record", ['employee_id' => $e3->id, 'attendance' => 'absent', 'result' => 'passed'])->assertOk();
        // Only the person who passed gains the skill level and the certificate.
        $this->assertSame(4, \App\Modules\HR\Talent\Domain\EmployeeSkill::where('employee_id', $e1->id)->value('level'));
        $this->assertSame(1, Certification::count());
        $this->assertSame($e1->id, Certification::first()->employee_id);
        $this->postJson("/api/v1/hr/talent/sessions/$id/record", ['employee_id' => $e1->id, 'attendance' => 'attended', 'result' => 'passed'])->assertOk();
        $this->assertSame(1, Certification::count()); // applied once
        $this->postJson("/api/v1/hr/talent/sessions/$id/complete")->assertOk();
        $cost = $this->getJson('/api/v1/hr/talent/training-cost?year='.now()->year)->assertOk()->json();
        $this->assertSame(90000, $cost['total']); // split over the two who attended
        $this->assertSame(45000, $this->getJson("/api/v1/hr/talent/employees/$e1->id/training")->json('0.cost'));
        $this->postJson("/api/v1/hr/talent/sessions/$id/cancel")->assertUnprocessable();
    }

    public function test_headcount_budget_vs_actual_and_vacancies(): void
    {
        $this->emp('1');
        $this->emp('2');
        $month = now()->format('Y-m');
        $this->putJson('/api/v1/hr/talent/headcount', ['department_id' => $this->dept->id, 'month' => $month, 'budgeted' => 5])->assertSuccessful();
        $this->putJson('/api/v1/hr/talent/headcount', ['department_id' => $this->dept->id, 'month' => $month, 'budgeted' => 4])->assertSuccessful(); // upsert
        $this->postJson('/api/v1/hr/talent/posts', ['title' => 'Cook', 'department_id' => $this->dept->id, 'openings' => 2])->assertCreated();
        $this->postJson('/api/v1/hr/talent/posts/'.\App\Modules\HR\Talent\Domain\JobPost::first()->id.'/status', ['status' => 'open'])->assertOk();
        $r = $this->getJson("/api/v1/hr/talent/headcount?from=$month&to=$month")->assertOk()->json('rows.0');
        $this->assertSame(4, $r['months'][$month]['budgeted']);
        $this->assertSame(2, $r['months'][$month]['actual']);
        $this->assertSame(-2, $r['months'][$month]['variance']);
        $this->assertSame(2, $r['months'][$month]['vacancies']);
        $this->assertSame(2, $r['open_posts']);
    }

    public function test_succession_readiness_cover_and_validation(): void
    {
        $boss = $this->emp('1');
        $other = Position::create(['department_id' => $this->dept->id, 'title' => 'Sous chef']);
        $cand = $this->emp('2', $other);
        $this->postJson('/api/v1/hr/talent/key-positions', ['position_id' => $this->pos->id, 'risk' => 'high'])->assertSuccessful();
        $p = $this->getJson('/api/v1/hr/talent/succession')->json('positions.0');
        $this->assertTrue($p['at_risk']);
        $this->postJson('/api/v1/hr/talent/successors', ['position_id' => $this->pos->id, 'employee_id' => $boss->id, 'readiness' => 'now'])->assertUnprocessable(); // incumbent
        $this->postJson('/api/v1/hr/talent/successors', ['position_id' => $this->pos->id, 'employee_id' => $cand->id, 'readiness' => '1_2y'])->assertCreated();
        $this->assertTrue($this->getJson('/api/v1/hr/talent/succession')->json('positions.0.at_risk')); // nobody ready now
        $this->postJson('/api/v1/hr/talent/successors', ['position_id' => $this->pos->id, 'employee_id' => $cand->id, 'readiness' => 'now'])->assertCreated();
        $p = $this->getJson('/api/v1/hr/talent/succession')->json('positions.0');
        $this->assertTrue($p['covered']);
        $this->assertFalse($p['at_risk']);
        $this->assertCount(1, $p['successors']);
    }

    public function test_skills_data_is_tenant_scoped(): void
    {
        $this->skill();
        [$g2, $h2] = $this->makeHotel('Other Group', 'OTH');
        $u2 = $this->makeUser($g2, 'general-manager', allHotels: true);
        $this->provision($h2);
        $this->actAs($u2, $h2);
        $this->assertCount(0, $this->getJson('/api/v1/hr/talent/catalogue')->assertOk()->json('skills'));
    }
}
