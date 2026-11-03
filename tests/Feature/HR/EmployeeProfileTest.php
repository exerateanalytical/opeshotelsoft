<?php

namespace Tests\Feature\HR;

use App\Modules\HR\Domain\Attendance;
use App\Modules\HR\Domain\Department;
use App\Modules\HR\Domain\Employee;
use App\Modules\HR\Domain\EmployeeDocument;
use App\Modules\HR\Domain\LeaveRequest;
use App\Modules\HR\Domain\Position;
use App\Modules\HR\Domain\SalaryAdvance;
use App\Modules\HR\Domain\Shift;
use App\Modules\HR\Domain\Lifecycle\SalaryHistory;
use App\Modules\HR\Talent\Domain\Certification;
use App\Modules\People\Domain\DisciplinaryAction;
use App\Modules\People\Domain\Task;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EmployeeProfileTest extends TestCase
{
    private array $emp;

    private function fullEmployee(): array
    {
        $dept = Department::create(['code' => 'FO', 'name' => 'Réception', 'cost_center' => 'rooms']);
        $pos = Position::create(['title' => 'Réceptionniste', 'department_id' => $dept->id, 'category' => '5A']);
        $hired = now()->subMonths(26)->toDateString();
        $e = $this->postJson('/api/v1/hr/employees', [
            'first_name' => 'Aïcha', 'last_name' => 'Bello', 'hire_date' => $hired, 'department_id' => $dept->id, 'position_id' => $pos->id,
            'phone' => '+237677000111', 'email' => 'aicha@test.cm', 'cnps_number' => 'CN123', 'tax_number' => 'NIU9',
            'payment_method' => 'mobile_money', 'momo_number' => '677000999',
            'contract' => ['kind' => 'CDI', 'start_date' => $hired, 'base_salary' => 200_000, 'probation_end' => now()->subMonths(22)->toDateString()],
        ])->assertCreated()->json();
        $id = $e['id'];

        // Raise approved by a second person (segregation of duties), applied today.
        $this->postJson("/api/v1/hr/employees/$id/compensation-changes", ['type' => 'raise', 'to_salary' => 230_000, 'effective_date' => now()->toDateString(), 'reason' => 'Annual review'])->assertCreated();
        $change = \App\Modules\HR\Domain\Lifecycle\CompensationChange::where('employee_id', $id)->firstOrFail();
        $approver = $this->makeUser($this->group, 'hr-officer', allHotels: true);
        $approver->givePermissionTo('hr.lifecycle.approve');
        $this->actAs($approver, $this->hotel);
        $this->postJson("/api/v1/hr/compensation-changes/{$change->id}/decide", ['approve' => true])->assertOk();
        $this->actAs($this->user, $this->hotel);
        $this->postJson("/api/v1/hr/employees/$id/pay-elements", ['code' => 'TRANSP', 'name' => 'Transport', 'kind' => 'recurring', 'amount' => 15_000, 'start_period' => now()->format('Y-m')])->assertCreated();

        // Advance, then two payroll runs: last month paid, this month approved (unpaid).
        SalaryAdvance::create(['employee_id' => $id, 'kind' => 'advance', 'amount' => 60_000, 'monthly_deduction' => 20_000, 'balance' => 60_000, 'granted_on' => now()->toDateString()]);
        $fd = $this->makeUser($this->group, 'finance-director', allHotels: true);
        $fd->givePermissionTo('payroll.approve');
        $prev = $this->postJson('/api/v1/payroll/runs', ['period' => now()->subMonth()->format('Y-m')])->assertOk()->json();
        $this->actAs($fd, $this->hotel);
        $this->postJson("/api/v1/payroll/runs/{$prev['id']}/approve")->assertOk();
        $this->postJson("/api/v1/payroll/runs/{$prev['id']}/pay", ['account' => '521500'])->assertOk();
        $this->actAs($this->user, $this->hotel);
        $cur = $this->postJson('/api/v1/payroll/runs', ['period' => now()->format('Y-m')])->assertOk()->json();
        $this->actAs($fd, $this->hotel);
        $this->postJson("/api/v1/payroll/runs/{$cur['id']}/approve")->assertOk();
        $this->actAs($this->user, $this->hotel);

        // Time.
        Shift::create(['employee_id' => $id, 'date' => now()->toDateString(), 'starts_at' => '08:00', 'ends_at' => '16:00', 'role' => 'FO']);
        Attendance::create(['employee_id' => $id, 'date' => now()->subDays(3)->toDateString(), 'clock_in' => now()->subDays(3)->setTime(8, 0), 'clock_out' => null, 'worked_minutes' => 0]);
        Attendance::create(['employee_id' => $id, 'date' => now()->subDays(2)->toDateString(), 'clock_in' => now()->subDays(2)->setTime(8, 0), 'clock_out' => now()->subDays(2)->setTime(17, 0), 'worked_minutes' => 540, 'overtime_minutes' => 60]);
        DB::table('overtime_entries')->insert(['group_id' => $this->group->id, 'hotel_id' => $this->hotel->id, 'employee_id' => $id, 'attendance_id' => Attendance::where('employee_id', $id)->value('id'), 'date' => now()->subDays(2)->toDateString(), 'category' => 'weekday', 'minutes' => 90, 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);
        $types = collect($this->getJson('/api/v1/hr/leave-types')->json())->keyBy('code');
        LeaveRequest::create(['employee_id' => $id, 'leave_type_id' => $types['ANNUAL']['id'], 'start_date' => now()->subDays(40)->toDateString(), 'end_date' => now()->subDays(36)->toDateString(), 'days' => 5, 'status' => 'approved']);

        // Documents.
        EmployeeDocument::create(['employee_id' => $id, 'type' => 'id', 'title' => 'CNI', 'path' => 'x/y.pdf', 'original_name' => 'cni.pdf', 'expires_on' => now()->addDays(10)->toDateString()]);
        $this->postJson("/api/v1/hr/employees/$id/econtracts", [])->assertCreated();

        // Growth.
        $skill = DB::table('tal_skills')->insertGetId(['group_id' => $this->group->id, 'code' => 'FR', 'name' => 'French', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tal_employee_skills')->insert(['group_id' => $this->group->id, 'hotel_id' => $this->hotel->id, 'employee_id' => $id, 'skill_id' => $skill, 'level' => 4, 'created_at' => now(), 'updated_at' => now()]);
        $ct = DB::table('tal_cert_types')->insertGetId(['group_id' => $this->group->id, 'code' => 'HYG', 'name' => 'Hygiene', 'created_at' => now(), 'updated_at' => now()]);
        Certification::create(['employee_id' => $id, 'cert_type_id' => $ct, 'issued_on' => now()->subYear()->toDateString(), 'expires_on' => now()->addDays(20)->toDateString()]);
        $cycle = DB::table('people_review_cycles')->insertGetId(['group_id' => $this->group->id, 'hotel_id' => $this->hotel->id, 'name' => 'H1', 'period_start' => now()->subMonths(6)->toDateString(), 'period_end' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('people_reviews')->insert(['group_id' => $this->group->id, 'hotel_id' => $this->hotel->id, 'cycle_id' => $cycle, 'employee_id' => $id, 'manager_rating' => 4, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('people_feedback')->insert(['group_id' => $this->group->id, 'hotel_id' => $this->hotel->id, 'employee_id' => $id, 'sentiment' => 'positive', 'received_on' => now()->toDateString(), 'comment' => 'Great', 'created_at' => now(), 'updated_at' => now()]);

        // Conduct and assets.
        DisciplinaryAction::create(['employee_id' => $id, 'kind' => 'verbal_warning', 'incident_date' => now()->subDays(5)->toDateString(), 'reason' => 'Late']);
        Task::create(['title' => 'Count keys', 'assignee_employee_id' => $id, 'due_at' => now()->subDay(), 'status' => 'open']);
        $iss = DB::table('issuables')->insertGetId(['group_id' => $this->group->id, 'hotel_id' => $this->hotel->id, 'code' => 'UNI', 'name' => 'Uniform', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('issue_records')->insert(['group_id' => $this->group->id, 'hotel_id' => $this->hotel->id, 'issuable_id' => $iss, 'employee_id' => $id, 'quantity' => 1, 'issued_on' => now()->subDays(60)->toDateString(), 'due_back_on' => now()->subDays(2)->toDateString(), 'status' => 'issued', 'created_at' => now(), 'updated_at' => now()]);

        return $e;
    }

    public function test_full_profile_has_every_section_with_data(): void
    {
        $e = $this->fullEmployee();
        $r = $this->getJson("/api/v1/hr/employees/{$e['id']}/profile?full=1")->assertOk()->json();

        $this->assertSame(['overview', 'employment', 'pay', 'time', 'documents', 'growth', 'conduct', 'assets', 'timeline'], $r['sections']);
        $this->assertSame('Aïcha Bello', $r['employee']['full_name']);
        $this->assertContains('edit', array_column($r['actions'], 'key'));
        $d = $r['data'];

        $this->assertSame(2, $d['overview']['tenure']['years']);
        $this->assertSame(2, $d['overview']['tenure']['months']);
        $this->assertContains('id_number', $d['overview']['completeness']['missing']);
        $this->assertSame('677000999', $d['overview']['momo_number']);

        $this->assertSame('CDI', $d['employment']['contracts'][0]['kind']);
        $this->assertGreaterThanOrEqual(1, count($d['employment']['econtracts']));
        $this->assertNotEmpty($d['employment']['position_history']);

        $this->assertSame(230_000, $d['pay']['base_salary']);
        $this->assertNotEmpty($d['pay']['pay_elements']);
        $this->assertNotEmpty($d['time']['shifts']);
        $this->assertCount(1, $d['time']['missing_clock_out']);
        $this->assertSame(1.5, $d['time']['overtime_by_month'][0]['approved_hours']);
        $this->assertSame('soon', $d['documents']['documents'][0]['expiry']);
        $this->assertSame('French', $d['growth']['skills'][0]['skill']);
        $this->assertSame('soon', $d['growth']['certificates'][0]['expiry']);
        $this->assertSame('H1', $d['growth']['reviews'][0]['cycle']);
        $this->assertSame('verbal_warning', $d['conduct']['warnings'][0]['kind']);
        $this->assertTrue($d['conduct']['tasks'][0]['overdue']);
        $this->assertSame(1, $d['assets']['overdue_count']);

        $kinds = array_column($d['timeline']['events'], 'kind');
        foreach (['hired', 'raise', 'warning', 'asset_issued', 'certificate_expiring', 'leave'] as $k) {
            $this->assertContains($k, $kinds, "timeline has $k");
        }
        $dates = array_column($d['timeline']['events'], 'date');
        $sorted = $dates;
        rsort($sorted);
        $this->assertSame($sorted, $dates, 'timeline is newest first');
    }

    public function test_pay_numbers_match_the_services(): void
    {
        $e = $this->fullEmployee();
        $pay = $this->getJson("/api/v1/hr/employees/{$e['id']}/profile/pay")->assertOk()->json();

        $history = $this->getJson("/api/v1/hr/employees/{$e['id']}/salary-history")->assertOk()->json();
        $this->assertCount(count($history), $pay['salary_history']);
        $raise = collect($pay['salary_history'])->firstWhere('source', 'raise');
        $this->assertSame(200_000, $raise['from']);
        $this->assertSame(230_000, $raise['to']);
        $this->assertEquals(15.0, $raise['percent']);
        $this->assertSame('Annual review', $raise['reason']);
        $this->assertNotNull($raise['approved_by']);
        foreach ($history as $i => $h) {
            $this->assertSame((int) $h['base_salary'], $pay['salary_history'][$i]['to']);
        }

        $advances = $this->getJson("/api/v1/hr/employees/{$e['id']}/advances")->assertOk()->json();
        $this->assertSame((int) $advances[0]['balance'], $pay['owing']['advances_balance']);
        $this->assertSame(20_000, $pay['owing']['advances_balance'], 'two approved runs each recovered 20 000');

        $slips = DB::table('payslips')->where('employee_id', $e['id'])->get();
        $this->assertCount(2, $pay['payslips']);
        $this->assertSame((int) $slips->sum('gross'), array_sum(array_column($pay['payslips'], 'gross')));
        $this->assertSame((int) $slips->sum('net'), array_sum(array_column($pay['payslips'], 'net')));
        // Year to date counts approved/paid runs of this calendar year only.
        $thisYear = DB::table('payslips')->join('payroll_runs', 'payroll_runs.id', '=', 'payslips.payroll_run_id')
            ->where('payslips.employee_id', $e['id'])->where('period', 'like', now()->year.'%')->whereIn('payroll_runs.status', ['approved', 'paid']);
        $this->assertSame((int) $thisYear->sum('payslips.gross'), $pay['ytd']['gross']);

        // This month's run is approved but not paid: its net is owing.
        $curSlip = collect($pay['payslips'])->firstWhere('period', now()->format('Y-m'));
        $this->assertSame((int) $curSlip['net'], $pay['owing']['unpaid_net']);
        $this->assertStringContainsString('/documents/payslips/'.$curSlip['id'], $curSlip['pdf_url']);
        $this->getJson($curSlip['pdf_url'])->assertOk();
    }

    public function test_permission_filtering_per_role(): void
    {
        $e = $this->fullEmployee();
        $id = $e['id'];
        $as = function (string $role, array $extra = []) {
            $u = $this->makeUser($this->group, $role, allHotels: true);
            foreach ($extra as $p) {
                $u->givePermissionTo($p);
            }
            $this->actAs($u, $this->hotel);
        };

        $as('receptionist');
        $this->getJson("/api/v1/hr/employees/$id/profile")->assertForbidden();
        $this->getJson("/api/v1/hr/employees/$id/profile/overview")->assertForbidden();

        $as('hr-officer');
        $r = $this->getJson("/api/v1/hr/employees/$id/profile?full=1")->assertOk()->json();
        $this->assertNotContains('pay', $r['sections']);
        $this->assertArrayNotHasKey('pay', $r['data']);
        $this->assertContains('growth', $r['sections']);
        $this->getJson("/api/v1/hr/employees/$id/profile/pay")->assertNotFound();
        $this->assertNull($r['data']['overview']['bank_account']);
        $this->assertNull($r['data']['overview']['momo_number'], 'payment destination is payroll data');
        $this->assertNull($r['data']['employment']['contracts'][0]['base_salary']);
        $kinds = array_column($r['data']['timeline']['events'], 'kind');
        $this->assertNotContains('raise', $kinds);
        $this->assertNotContains('payslip_paid', $kinds);
        $this->assertNotNull($r['data']['conduct']['warnings'], 'hr-officer holds people.discipline');
        $this->assertNotContains('advance', array_column($r['actions'], 'key'));

        $as('payroll-officer');
        $r = $this->getJson("/api/v1/hr/employees/$id/profile?full=1")->assertOk()->json();
        $this->assertContains('pay', $r['sections']);
        $this->assertSame(230_000, $r['data']['pay']['base_salary']);
        $this->assertNull($r['data']['conduct']['warnings'] ?? null, 'no people.discipline');
        $this->assertContains('advance', array_column($r['actions'], 'key'));
        $this->assertNotContains('edit', array_column($r['actions'], 'key'));

        $as('general-manager');
        $r = $this->getJson("/api/v1/hr/employees/$id/profile")->assertOk()->json();
        $this->assertCount(9, $r['sections']);
        foreach (['edit', 'raise', 'transfer', 'bonus', 'contract', 'letter', 'id_card', 'advance', 'terminate'] as $a) {
            $this->assertContains($a, array_column($r['actions'], 'key'));
        }
    }

    public function test_other_hotels_employee_is_404(): void
    {
        [, $hotel2] = $this->makeHotel('Akwa Test', 'KRB', $this->group);
        $other = app(\App\Platform\Tenancy\TenantContext::class)->withoutScope(function () use ($hotel2) {
            $e = new Employee(['number' => 'X1', 'first_name' => 'Far', 'last_name' => 'Away', 'hire_date' => now()->subYear()->toDateString()]);
            $e->group_id = $hotel2->group_id;
            $e->hotel_id = $hotel2->id;
            $e->save();

            return $e;
        });
        $mgr = $this->makeUser($this->group, 'general-manager', hotels: [$this->hotel]);
        $this->actAs($mgr, $this->hotel);
        $this->getJson("/api/v1/hr/employees/{$other->id}/profile")->assertNotFound();
        $this->getJson("/api/v1/hr/employees/{$other->id}/profile/pay")->assertNotFound();
    }

    public function test_page_route_renders_for_hr_and_is_refused_for_receptionist(): void
    {
        $e = $this->fullEmployee();
        $this->get("/hr/employees/{$e['id']}")->assertOk();
        $this->actAs($this->makeUser($this->group, 'receptionist', hotels: [$this->hotel]), $this->hotel);
        $this->get("/hr/employees/{$e['id']}")->assertForbidden();
    }
}
