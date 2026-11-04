<?php

namespace Tests\Feature\HR;

use App\Modules\HR\Domain\Department;
use Tests\TestCase;

class PayrollWorkflowTest extends TestCase
{
    private function hire(int $salary): array
    {
        $dept = Department::create(['code' => 'FO', 'name' => 'Réception', 'cost_center' => 'rooms']);

        return $this->postJson('/api/v1/hr/employees', [
            'first_name' => 'Aïcha', 'last_name' => 'Bello', 'hire_date' => now()->startOfMonth()->subYear()->toDateString(),
            'department_id' => $dept->id, 'payment_method' => 'mobile_money', 'momo_number' => '677000000',
            'contract' => ['kind' => 'CDI', 'start_date' => now()->startOfMonth()->subYear()->toDateString(), 'base_salary' => $salary],
        ])->assertCreated()->json();
    }

    public function test_payroll_compute_approve_pay_posts_balanced_entries(): void
    {
        $this->hire(300_000);
        $period = now()->format('Y-m');

        $run = $this->postJson('/api/v1/payroll/runs', ['period' => $period])->assertOk()->json();
        $this->assertCount(1, $run['payslips']);

        // Preparer cannot approve (segregation of duties).
        $this->postJson("/api/v1/payroll/runs/{$run['id']}/approve")->assertStatus(422);

        $fd = $this->makeUser($this->group, 'finance-director', allHotels: true);
        $fd->givePermissionTo('payroll.approve');
        $this->actAs($fd, $this->hotel);
        $this->postJson("/api/v1/payroll/runs/{$run['id']}/approve")->assertOk()->assertJsonPath('status', 'approved');
        $this->postJson("/api/v1/payroll/runs/{$run['id']}/pay", ['account' => '521500'])->assertOk();

        $this->actAs($this->user, $this->hotel);
        $b = $this->assertBooksBalance();
        $this->assertSame(300_000, $b['661100'], 'gross salary expense');
        $this->assertSame(0, $b['422100'] ?? 0, 'net pay settled');
        $this->assertSame(-$run['net'], $b['521500'], 'paid by mobile money');

        $decl = $this->getJson("/api/v1/payroll/runs/{$run['id']}/declaration")->assertOk()->json();
        $this->assertSame(12_600, $decl['cnps']['employee']);
    }

    public function test_leave_request_respects_balance(): void
    {
        $e = $this->hire(150_000);
        $types = collect($this->getJson('/api/v1/hr/leave-types')->json())->keyBy('code');

        $this->postJson('/api/v1/hr/leave-requests', [
            'employee_id' => $e['id'], 'leave_type_id' => $types['ANNUAL']['id'],
            'start_date' => now()->addDays(10)->toDateString(), 'end_date' => now()->addDays(40)->toDateString(),
        ])->assertStatus(422)->assertJsonPath('type', 'https://docs.opeshotelsoft.test/problems/insufficient-leave');

        $req = $this->postJson('/api/v1/hr/leave-requests', [
            'employee_id' => $e['id'], 'leave_type_id' => $types['ANNUAL']['id'],
            'start_date' => now()->addDays(10)->toDateString(), 'end_date' => now()->addDays(14)->toDateString(),
        ])->assertCreated()->json();
        $this->postJson("/api/v1/hr/leave-requests/{$req['id']}/decide", ['approve' => true])->assertOk()->assertJsonPath('status', 'approved');
    }

    public function test_receptionist_cannot_see_payroll(): void
    {
        $r = $this->makeUser($this->group, 'receptionist', hotels: [$this->hotel]);
        $this->actAs($r, $this->hotel);

        $this->getJson('/api/v1/payroll/runs')->assertForbidden();
        $this->getJson('/api/v1/hr/employees')->assertForbidden();
    }

    public function test_salary_edit_updates_contract_and_payroll(): void
    {
        $e = $this->hire(200_000);
        $this->patchJson("/api/v1/hr/employees/{$e['id']}", ['base_salary' => 250_000])->assertOk()
            ->assertJsonPath('contracts.0.base_salary', 250_000);
        $run = $this->postJson('/api/v1/payroll/runs', ['period' => now()->format('Y-m')])->assertOk()->json();
        $this->assertSame(250_000, collect($run['payslips'][0]['lines'])->firstWhere('section', 'earning')['amount']);
    }

    public function test_self_service_endpoints(): void
    {
        $e = $this->hire(300_000);
        \App\Modules\HR\Domain\Employee::find($e['id'])->update(['user_id' => $this->user->id]);

        $this->getJson('/api/v1/hr/clock/status')->assertOk()->assertJsonPath('clocked_in', false);
        $this->postJson('/api/v1/hr/clock/in')->assertSuccessful();
        $st = $this->getJson('/api/v1/hr/clock/status')->assertOk()->assertJsonPath('clocked_in', true)->json();
        $this->assertNotNull($st['since']);
        $this->getJson('/api/v1/hr/attendance?employee_id='.$e['id'].'&from='.now()->subDay()->toDateString().'&to='.now()->toDateString())
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.employee.number', $e['number']);

        $this->putJson('/api/v1/hr/shifts', ['shifts' => [['employee_id' => $e['id'], 'date' => now()->toDateString(), 'starts_at' => '07:00', 'ends_at' => '15:00']]])->assertOk();
        $this->getJson('/api/v1/hr/shifts?from='.now()->toDateString().'&to='.now()->toDateString())->assertOk()
            ->assertJsonPath('0.employee.full_name', 'Aïcha Bello')->assertJsonPath('0.employee.number', $e['number']);

        // SEC2: nobody grants themselves an advance; a colleague with payroll rights does.
        $this->postJson("/api/v1/hr/employees/{$e['id']}/advances", ['amount' => 100_000, 'monthly_deduction' => 25_000])->assertStatus(422);
        $this->actAs($this->makeUser($this->group, 'payroll-officer', allHotels: true), $this->hotel);
        $this->postJson("/api/v1/hr/employees/{$e['id']}/advances", ['amount' => 100_000, 'monthly_deduction' => 25_000])->assertCreated();
        $this->actAs($this->user, $this->hotel);
        $this->getJson("/api/v1/hr/employees/{$e['id']}/advances")->assertOk()->assertJsonPath('0.balance', 100_000);

        $run = $this->postJson('/api/v1/payroll/runs', ['period' => now()->format('Y-m')])->assertOk()->json();
        $fd = $this->makeUser($this->group, 'finance-director', allHotels: true);
        $fd->givePermissionTo('payroll.approve');
        $this->actAs($fd, $this->hotel);
        $this->postJson("/api/v1/payroll/runs/{$run['id']}/approve")->assertOk();
        $this->actAs($this->user, $this->hotel);
        $this->getJson('/api/v1/payroll/payslips/mine')->assertOk()
            ->assertJsonPath('0.run.status', 'approved')
            ->assertJsonPath('0.run.period_start', now()->startOfMonth()->toDateString())
            ->assertJsonPath('0.run.period_end', now()->endOfMonth()->toDateString());
    }

    public function test_leave_request_without_employee_record_is_422(): void
    {
        $types = collect($this->getJson('/api/v1/hr/leave-types')->json())->keyBy('code');
        $this->postJson('/api/v1/hr/leave-requests', [
            'leave_type_id' => $types['SICK']['id'], 'start_date' => now()->addDay()->toDateString(), 'end_date' => now()->addDays(2)->toDateString(),
        ])->assertStatus(422)->assertJsonPath('type', 'https://docs.opeshotelsoft.test/problems/no-employee-record');
    }
}
