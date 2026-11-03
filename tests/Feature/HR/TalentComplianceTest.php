<?php

namespace Tests\Feature\HR;

use App\Modules\HR\Domain\Department;
use App\Modules\HR\Domain\Employee;
use App\Modules\HR\Domain\EmployeeDocument;
use App\Modules\HR\Domain\Payslip;
use App\Modules\HR\Talent\Domain\PrivacyLog;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TalentComplianceTest extends TestCase
{
    private Department $dept;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dept = Department::create(['code' => 'FO', 'name' => 'Reception', 'cost_center' => 'rooms']);
    }

    private function hire(string $first, int $salary = 200_000, array $extra = []): array
    {
        $start = now()->startOfMonth()->subYear()->toDateString();

        return $this->postJson('/api/v1/hr/employees', $extra + [
            'first_name' => $first, 'last_name' => 'Test', 'hire_date' => $start, 'department_id' => $this->dept->id, 'payment_method' => 'mobile_money', 'momo_number' => '677'.random_int(100000, 999999),
            'contract' => ['kind' => 'CDI', 'start_date' => $start, 'base_salary' => $salary],
        ])->assertCreated()->json();
    }

    private function codes(array $checks, bool $flagged = true): array
    {
        return collect($checks)->filter(fn ($c) => ! $flagged || $c['items'])->pluck('code')->all();
    }

    private function approver()
    {
        $fd = $this->makeUser($this->group, 'finance-director', allHotels: true);
        $fd->givePermissionTo('payroll.approve');

        return $fd;
    }

    public function test_payroll_checks_flag_missing_and_duplicate_bank_details_and_overtime(): void
    {
        $a = $this->hire('Ann', extra: ['payment_method' => 'bank']);
        $b = $this->hire('Bea', extra: ['payment_method' => 'bank', 'bank_name' => 'AFB', 'bank_account' => 'CM21 0001']);
        $c = $this->hire('Cy', extra: ['payment_method' => 'bank', 'bank_name' => 'AFB', 'bank_account' => 'cm210001']);
        $d = $this->hire('Dan');
        $period = now()->format('Y-m');
        $run = $this->postJson('/api/v1/payroll/runs', ['period' => $period, 'inputs' => [$d['id'] => ['overtime_hours' => 85], $a['id'] => ['overtime_hours' => 5]]])->assertOk()->json();

        $res = $this->getJson("/api/v1/hr/talent/payroll/{$run['id']}/checks")->assertOk()->json();
        $by = collect($res['checks'])->keyBy('code');
        $this->assertSame([$a['id']], collect($by['missing_bank']['items'])->pluck('employee_id')->all());
        $this->assertEqualsCanonicalizing([$b['id'], $c['id']], collect($by['duplicate_bank']['items'])->pluck('employee_id')->all());
        $this->assertSame([$d['id']], collect($by['overtime_outliers']['items'])->pluck('employee_id')->all());
        $this->assertFalse($res['required']);
    }

    public function test_payroll_checks_compare_with_previous_month(): void
    {
        $a = $this->hire('Ann', 200_000);
        $b = $this->hire('Bea', 200_000);
        $prev = now()->subMonth()->format('Y-m');
        $this->postJson('/api/v1/payroll/runs', ['period' => $prev])->assertOk();
        Employee::find($a['id'])->update(['base_salary' => 400_000]); // payroll reads the employee record
        $c = $this->hire('Cy', 200_000);
        Employee::find($b['id'])->update(['status' => 'terminated', 'termination_date' => now()->startOfMonth()->subDay()->toDateString()]);
        $run = $this->postJson('/api/v1/payroll/runs', ['period' => now()->format('Y-m')])->assertOk()->json();

        $by = collect($this->getJson("/api/v1/hr/talent/payroll/{$run['id']}/checks")->assertOk()->json('checks'))->keyBy('code');
        $this->assertContains($a['id'], collect($by['big_changes']['items'])->pluck('employee_id')->all());
        $this->assertSame([$c['id']], collect($by['new_employees']['items'])->pluck('employee_id')->all());
        $this->assertSame([$b['id']], collect($by['leaving_employees']['items'])->pluck('employee_id')->all());
    }

    public function test_approval_is_never_blocked_unless_configured_then_needs_acknowledgement(): void
    {
        $this->hire('Ann', extra: ['payment_method' => 'bank']); // missing bank account -> flagged
        $run = $this->postJson('/api/v1/payroll/runs', ['period' => now()->format('Y-m')])->assertOk()->json();
        $fd = $this->approver();

        // Default: checks are advisory, approval goes through.
        $this->actAs($fd, $this->hotel);
        $this->postJson("/api/v1/payroll/runs/{$run['id']}/approve")->assertOk()->assertJsonPath('status', 'approved');

        // Configured: a second hotel run must be acknowledged first.
        $this->actAs($this->user, $this->hotel);
        $this->putJson('/api/v1/hr/talent/settings', ['payroll_review_required' => true])->assertOk();
        $run2 = $this->postJson('/api/v1/payroll/runs', ['period' => now()->addMonth()->format('Y-m')])->assertOk()->json();
        $this->actAs($fd, $this->hotel);
        $this->postJson("/api/v1/payroll/runs/{$run2['id']}/approve")->assertStatus(422)->assertJsonPath('type', 'https://docs.opeshotelsoft.test/problems/payroll-review-pending');
        $this->actAs($this->user, $this->hotel);
        $res = $this->postJson("/api/v1/hr/talent/payroll/{$run2['id']}/acknowledge", ['codes' => ['missing_bank'], 'comment' => 'Account follows'])->assertSuccessful()->json();
        $this->assertNotNull($res['reviewed_at']);
        $this->actAs($fd, $this->hotel);
        $this->postJson("/api/v1/payroll/runs/{$run2['id']}/approve")->assertOk()->assertJsonPath('status', 'approved');
    }

    public function test_clean_payroll_needs_no_acknowledgement_when_review_is_required(): void
    {
        $this->hire('Ann');
        $this->putJson('/api/v1/hr/talent/settings', ['payroll_review_required' => true])->assertOk();
        $run = $this->postJson('/api/v1/payroll/runs', ['period' => now()->format('Y-m')])->assertOk()->json();
        $this->actAs($this->approver(), $this->hotel);
        $this->postJson("/api/v1/payroll/runs/{$run['id']}/approve")->assertOk();
    }

    public function test_employee_data_export_json_and_excel(): void
    {
        $e = $this->hire('Ann');
        $run = $this->postJson('/api/v1/payroll/runs', ['period' => now()->format('Y-m')])->assertOk()->json();
        $json = $this->getJson("/api/v1/hr/talent/privacy/employees/{$e['id']}/export")->assertOk()->json();
        $this->assertSame('Ann', $json['profile']['first_name']);
        $this->assertCount(1, $json['contracts']);
        $this->assertCount(1, $json['payslips']);
        $res = $this->get("/api/v1/hr/talent/privacy/employees/{$e['id']}/export?format=xlsx")->assertOk();
        $this->assertStringContainsString('spreadsheetml', $res->headers->get('Content-Type'));
        $this->assertSame('PK', substr($res->getContent(), 0, 2)); // xlsx is a zip
        $this->assertSame(2, PrivacyLog::where('action', 'export')->count());
        $this->assertNotNull($run);
    }

    public function test_erasure_anonymises_leavers_after_retention_and_keeps_payroll_history(): void
    {
        Storage::fake('local');
        $e = $this->hire('Ann', extra: ['phone' => '699123456', 'email' => 'ann@test.cm', 'id_number' => 'ID123', 'cnps_number' => 'C1']);
        $this->postJson('/api/v1/payroll/runs', ['period' => now()->format('Y-m')])->assertOk();
        $emp = Employee::find($e['id']);
        Storage::disk('local')->put('hr/doc.pdf', 'x');
        EmployeeDocument::create(['employee_id' => $emp->id, 'type' => 'id', 'title' => 'ID card', 'path' => 'hr/doc.pdf', 'original_name' => 'id.pdf']);
        $net = Payslip::where('employee_id', $emp->id)->value('net');

        $this->postJson("/api/v1/hr/talent/privacy/employees/$emp->id/erase")->assertUnprocessable(); // still employed
        $emp->update(['status' => 'terminated', 'termination_date' => now()->subMonths(6)->toDateString()]);
        $this->postJson("/api/v1/hr/talent/privacy/employees/$emp->id/erase")->assertUnprocessable(); // inside retention
        $this->assertSame([], $this->getJson('/api/v1/hr/talent/privacy/eligible')->json('employees'));

        $emp->update(['termination_date' => now()->subMonths(61)->toDateString()]);
        $this->assertCount(1, $this->getJson('/api/v1/hr/talent/privacy/eligible')->json('employees'));
        $this->postJson("/api/v1/hr/talent/privacy/employees/$emp->id/erase")->assertOk();
        $emp = $emp->fresh();
        $this->assertSame('Anonymised', $emp->first_name);
        $this->assertNull($emp->phone);
        $this->assertNull($emp->id_number);
        $this->assertNull($emp->email);
        Storage::disk('local')->assertMissing('hr/doc.pdf');
        $this->assertSame($net, Payslip::where('employee_id', $emp->id)->value('net')); // payroll history untouched
        $this->assertSame(1, PrivacyLog::where('action', 'erase')->count());
        $this->postJson("/api/v1/hr/talent/privacy/employees/$emp->id/erase")->assertUnprocessable(); // already done
    }

    public function test_privacy_and_review_endpoints_need_permissions(): void
    {
        $e = $this->hire('Ann');
        $u = $this->makeUser($this->group, 'receptionist', hotels: [$this->hotel]);
        $this->actAs($u, $this->hotel);
        $this->getJson("/api/v1/hr/talent/privacy/employees/{$e['id']}/export")->assertForbidden();
        $this->postJson("/api/v1/hr/talent/privacy/employees/{$e['id']}/erase")->assertForbidden();
        $this->putJson('/api/v1/hr/talent/settings', ['payroll_review_required' => true])->assertForbidden();
    }
}
