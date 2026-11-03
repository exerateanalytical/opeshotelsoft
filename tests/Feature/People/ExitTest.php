<?php

namespace Tests\Feature\People;

use App\Modules\People\Application\SettlementCalculator;
use Carbon\Carbon;

class ExitTest extends PeopleTestCase
{
    public function test_termination_settlement_notice_leave_and_severance(): void
    {
        $c = new SettlementCalculator;
        // 7 years, 260 000/month, notice not worked, 13 leave days.
        $s = $c->calculate('termination', false, false, false, Carbon::parse('2019-06-30'), Carbon::parse('2026-06-30'), 260_000, 13);
        $lines = collect($s['lines'])->keyBy('code');
        $this->assertTrue($s['requires_review']);
        $this->assertSame(2.0, (float) $s['notice_months']);
        $this->assertSame(520_000, $lines['notice_indemnity']['amount']);
        $this->assertSame(130_000, $lines['leave_indemnity']['amount']); // 13 × 260000/26
        // 5 y × 20 % + ~2 y × 25 % ≈ 1.5 months
        $this->assertEqualsWithDelta(390_000, $lines['severance']['amount'], 1_000);
        $this->assertSame(260_000, $lines['final_month_salary']['amount']);
    }

    public function test_no_notice_or_severance_on_gross_misconduct_end_of_contract_or_short_service(): void
    {
        $c = new SettlementCalculator;
        foreach ([['termination', true, '2018-01-01'], ['end_of_contract', false, '2018-01-01']] as [$kind, $gross, $hire]) {
            $codes = array_column($c->calculate($kind, $gross, false, false, Carbon::parse($hire), Carbon::parse('2026-06-15'), 200_000, 0)['lines'], 'code');
            $this->assertNotContains('notice_indemnity', $codes);
            $this->assertNotContains('severance', $codes);
        }
        $codes = array_column($c->calculate('termination', false, false, false, Carbon::parse('2025-06-01'), Carbon::parse('2026-06-15'), 200_000, 0)['lines'], 'code');
        $this->assertContains('notice_indemnity', $codes);
        $this->assertNotContains('severance', $codes);
        // Resigning without working the notice: owed by the employee.
        $r = $c->calculate('resignation', false, false, true, Carbon::parse('2025-01-01'), Carbon::parse('2026-01-31'), 300_000, 0);
        $this->assertSame(-600_000, collect($r['lines'])->firstWhere('code', 'notice_not_worked')['amount']);
    }

    public function test_exit_flow_approve_terminates_and_issues_work_certificate(): void
    {
        $emp = $this->employee(['hire_date' => '2020-03-01']);
        $exit = $this->postJson('/api/v1/people/exits', ['employee_id' => $emp->id, 'kind' => 'resignation', 'notice_date' => '2026-10-01', 'last_day' => '2026-10-31'])
            ->assertCreated()->assertJsonPath('settlement.requires_review', true)->json();
        $this->postJson('/api/v1/people/exits', ['employee_id' => $emp->id, 'kind' => 'resignation', 'notice_date' => '2026-10-01', 'last_day' => '2026-10-31'])->assertStatus(422);

        $this->get("/api/v1/people/exits/{$exit['id']}/work-certificate")->assertStatus(422);
        $this->patchJson("/api/v1/people/exits/{$exit['id']}", ['kind' => 'termination', 'notice_worked' => false])->assertOk()
            ->assertJsonPath('settlement.lines.0.code', 'notice_indemnity');

        $this->postJson("/api/v1/people/exits/{$exit['id']}/approve")->assertOk()->assertJsonPath('status', 'approved');
        $this->assertSame('terminated', $emp->fresh()->status);
        $this->assertSame('2026-10-31', $emp->fresh()->termination_date->toDateString());
        $this->patchJson("/api/v1/people/exits/{$exit['id']}", ['reason' => 'x'])->assertStatus(422);

        $pdf = $this->get("/api/v1/people/exits/{$exit['id']}/work-certificate?lang=en")->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_exit_needs_permission(): void
    {
        $emp = $this->employee();
        $this->actAs($this->makeUser($this->group, 'receptionist', hotels: [$this->hotel]), $this->hotel);
        $this->getJson('/api/v1/people/exits')->assertForbidden();
        $this->postJson('/api/v1/people/exits', ['employee_id' => $emp->id, 'kind' => 'resignation', 'notice_date' => '2026-10-01', 'last_day' => '2026-10-31'])->assertForbidden();
        $this->actAs($this->makeUser($this->group, 'hr-officer', hotels: [$this->hotel]), $this->hotel);
        $this->getJson('/api/v1/people/exits')->assertOk();
    }
}
