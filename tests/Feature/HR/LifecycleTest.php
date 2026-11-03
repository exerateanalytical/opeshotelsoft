<?php

namespace Tests\Feature\HR;

use App\Modules\HR\Domain\Department;
use App\Modules\HR\Domain\Employee;
use App\Modules\HR\Domain\LeaveType;
use App\Modules\HR\Domain\Lifecycle\EContract;
use App\Modules\HR\Domain\Position;
use App\Modules\Notifications\Domain\OutboxMessage;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LifecycleTest extends TestCase
{
    private function hire(int $salary = 200_000, array $extra = []): array
    {
        $dept = Department::firstOrCreate(['code' => 'FO'], ['name' => 'Réception', 'cost_center' => 'rooms']);
        $pos = Position::firstOrCreate(['title' => 'Réceptionniste'], ['department_id' => $dept->id, 'category' => '5A']);
        $hired = now()->startOfMonth()->subYear()->toDateString();

        return $this->postJson('/api/v1/hr/employees', $extra + [
            'first_name' => 'Aïcha', 'last_name' => 'Bello'.uniqid(), 'hire_date' => $hired, 'department_id' => $dept->id, 'position_id' => $pos->id,
            'phone' => '+237677000111', 'email' => 'aicha@test.cm', 'id_number' => 'CNI123',
            'contract' => ['kind' => 'CDI', 'start_date' => $hired, 'base_salary' => $salary, 'probation_end' => now()->subMonths(10)->toDateString()],
        ])->assertCreated()->json();
    }

    private function signature(): string
    {
        $im = imagecreatetruecolor(60, 30);
        imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
        imageline($im, 2, 25, 55, 5, imagecolorallocate($im, 0, 0, 0));
        ob_start();
        imagepng($im);

        return 'data:image/png;base64,'.base64_encode(ob_get_clean());
    }

    private function approver()
    {
        $u = $this->makeUser($this->group, 'hr-officer', allHotels: true);
        $u->givePermissionTo('hr.lifecycle.approve');

        return $u;
    }

    public function test_econtract_generate_send_otp_sign_countersign_seals_pdf(): void
    {
        Storage::fake('local');
        $e = $this->hire(250_000);

        $doc = $this->postJson("/api/v1/hr/employees/{$e['id']}/econtracts", [])->assertCreated()->json();
        $this->assertSame('CDI', $doc['kind']);
        $this->assertStringContainsString($e['first_name'], $doc['body']);
        $this->assertStringContainsString('250 000', $doc['body']);
        $this->assertStringContainsString('Réceptionniste', $doc['body']);
        $this->assertStringNotContainsString('{{', $doc['body']);
        $this->assertArrayNotHasKey('token_hash', $doc);

        // Draft preview renders.
        $this->get("/api/v1/hr/econtracts/{$doc['id']}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $sent = $this->postJson("/api/v1/hr/econtracts/{$doc['id']}/send")->assertOk()->json();
        $token = basename($sent['url']);
        $this->assertSame(48, strlen($token));
        $this->assertTrue(OutboxMessage::where('event', 'hr.contract_sign_link')->where('ref_id', $doc['id'])->exists());

        // Public signing page needs no login.
        $this->get('/sign/contract/'.$token)->assertOk();
        $this->flushHeaders();
        $view = $this->getJson("/api/v1/hr/sign/$token")->assertOk()->json();
        $this->assertSame('sent', $view['status']);
        $this->assertStringNotContainsString('677000111', json_encode($view['channels']));
        $this->getJson('/api/v1/hr/sign/'.str_repeat('a', 48))->assertNotFound();

        // Signing without a code fails; wrong code counts attempts.
        $this->postJson("/api/v1/hr/sign/$token", ['otp' => '000000', 'signature' => $this->signature(), 'agree' => true])->assertStatus(422);
        $this->postJson("/api/v1/hr/sign/$token/otp", ['channel' => 'sms'])->assertOk();
        $msg = OutboxMessage::where('event', 'hr.contract_otp.sms')->latest('id')->first();
        $this->assertSame('sms', $msg->channel);
        preg_match('/: (\d{6}) /', $msg->body, $m);
        $code = $m[1];
        $wrong = $code === '111111' ? '222222' : '111111';
        $this->postJson("/api/v1/hr/sign/$token", ['otp' => $wrong, 'signature' => $this->signature(), 'agree' => true])->assertStatus(422)
            ->assertJsonPath('type', 'https://docs.opeshotelsoft.test/problems/otp-invalid');
        $this->assertSame(1, app(TenantContext::class)->withoutScope(fn () => EContract::find($doc['id'])->otp_attempts));
        $this->postJson("/api/v1/hr/sign/$token", ['otp' => $code, 'signature' => 'data:image/png;base64,AAAA', 'agree' => true])->assertStatus(422);
        $this->postJson("/api/v1/hr/sign/$token", ['otp' => $code, 'signature' => $this->signature(), 'agree' => true], ['User-Agent' => 'PhoneTest'])
            ->assertOk()->assertJsonPath('status', 'employee_signed');
        $this->postJson("/api/v1/hr/sign/$token", ['otp' => $code, 'signature' => $this->signature(), 'agree' => true])->assertStatus(422);

        // Employer counter-signature: needs approve permission.
        $this->actAs($this->makeUser($this->group, 'hr-officer', allHotels: true), $this->hotel);
        $this->postJson("/api/v1/hr/econtracts/{$doc['id']}/countersign", ['signature' => $this->signature()])->assertForbidden();
        $this->actAs($this->approver(), $this->hotel);
        $done = $this->postJson("/api/v1/hr/econtracts/{$doc['id']}/countersign", ['signature' => $this->signature()])->assertOk()->json();
        $this->assertSame('completed', $done['status']);

        $sealed = EContract::find($doc['id']);
        Storage::disk('local')->assertExists($sealed->pdf_path);
        $pdf = Storage::disk('local')->get($sealed->pdf_path);
        $this->assertSame(hash('sha256', $pdf), $sealed->pdf_sha256);
        $this->assertSame(hash('sha256', $sealed->body), $sealed->body_sha256);
        $this->assertNotNull($sealed->employee_ip);
        $this->assertSame('PhoneTest', $sealed->employee_user_agent);
        $this->assertNull($sealed->token_hash, 'link closed after completion');
        $this->assertSame(hash('sha256', $this->get("/api/v1/hr/econtracts/{$doc['id']}/pdf")->assertOk()->getContent()), $sealed->pdf_sha256);

        $events = collect($this->getJson("/api/v1/hr/econtracts/{$doc['id']}")->assertOk()->json('audit'))->pluck('event')->all();
        $this->assertSame(['generated', 'sent', 'otp_sent', 'otp_failed', 'otp_verified', 'employee_signed', 'employer_signed', 'sealed'], $events);
        $this->postJson("/api/v1/hr/econtracts/{$doc['id']}/cancel")->assertStatus(422);
        $this->getJson("/api/v1/hr/sign/$token")->assertNotFound();
    }

    public function test_otp_locks_after_too_many_wrong_codes_and_templates_cover_all_kinds(): void
    {
        $e = $this->hire();
        foreach (['CDD', 'stage', 'journalier'] as $kind) {
            $d = $this->postJson("/api/v1/hr/employees/{$e['id']}/econtracts", ['kind' => $kind, 'locale' => 'en'])->assertCreated()->json();
            $this->assertStringNotContainsString('{{', $d['body']);
        }
        $this->putJson('/api/v1/hr/contract-templates', ['kind' => 'CDI', 'locale' => 'fr', 'title' => 'Mon CDI', 'body' => 'Salarié {{employee.full_name}} payé {{salary.amount}}'])->assertSuccessful();
        $doc = $this->postJson("/api/v1/hr/employees/{$e['id']}/econtracts", ['kind' => 'CDI'])->assertCreated()->json();
        $this->assertSame('Mon CDI', $doc['title']);
        $this->assertStringContainsString('200 000', $doc['body']);

        $token = basename($this->postJson("/api/v1/hr/econtracts/{$doc['id']}/send")->json('url'));
        $this->flushHeaders();
        $this->postJson("/api/v1/hr/sign/$token/otp", ['channel' => 'email'])->assertOk();
        $this->assertSame('email', OutboxMessage::where('event', 'hr.contract_otp.email')->latest('id')->value('channel'));
        foreach (range(1, 5) as $_) {
            $this->postJson("/api/v1/hr/sign/$token", ['otp' => '000001', 'signature' => $this->signature(), 'agree' => true])->assertStatus(422);
        }
        $this->postJson("/api/v1/hr/sign/$token", ['otp' => '000001', 'signature' => $this->signature(), 'agree' => true])
            ->assertJsonPath('type', 'https://docs.opeshotelsoft.test/problems/otp-locked');
    }

    public function test_raise_and_promotion_need_another_approver_and_record_history_and_amendment(): void
    {
        $e = $this->hire(200_000);
        $dept = Department::first();
        $chef = Position::create(['department_id' => $dept->id, 'title' => 'Chef de réception', 'category' => '7A']);

        $this->postJson("/api/v1/hr/employees/{$e['id']}/compensation-changes", ['type' => 'raise', 'to_salary' => 150_000, 'effective_date' => today()->toDateString()])->assertStatus(422);
        $c = $this->postJson("/api/v1/hr/employees/{$e['id']}/compensation-changes", [
            'type' => 'promotion', 'to_salary' => 260_000, 'to_position_id' => $chef->id, 'effective_date' => today()->toDateString(), 'reason' => 'Excellent travail',
        ])->assertCreated()->json();
        $this->postJson("/api/v1/hr/employees/{$e['id']}/compensation-changes", ['type' => 'raise', 'to_salary' => 300_000, 'effective_date' => today()->toDateString()])->assertStatus(422);

        // The requester (GM, who has every permission) cannot approve their own request.
        $this->postJson("/api/v1/hr/compensation-changes/{$c['id']}/decide", ['approve' => true])->assertStatus(422)
            ->assertJsonPath('type', 'https://docs.opeshotelsoft.test/problems/sod');

        $this->actAs($this->approver(), $this->hotel);
        $done = $this->postJson("/api/v1/hr/compensation-changes/{$c['id']}/decide", ['approve' => true])->assertOk()->json();
        $this->assertSame('applied', $done['status']);
        $emp = Employee::find($e['id']);
        $this->assertSame(260_000, (int) $emp->base_salary);
        $this->assertSame($chef->id, $emp->position_id);
        $this->assertSame(260_000, (int) $emp->contracts()->where('status', 'active')->value('base_salary'));

        $amend = EContract::find($done['econtract_id']);
        $this->assertSame('avenant', $amend->kind);
        $this->assertStringContainsString('260 000', $amend->body);
        $this->assertStringContainsString('Chef de réception', $amend->body);

        $hist = $this->getJson("/api/v1/hr/employees/{$e['id']}/salary-history")->assertOk()->json();
        $this->assertSame(['hire', 'promotion'], array_column($hist, 'source'));
        $this->assertSame([200_000, 260_000], array_map('intval', array_column($hist, 'base_salary')));

        // Future-dated raise waits for its effective date.
        $this->actAs($this->user, $this->hotel);
        $r = $this->postJson("/api/v1/hr/employees/{$e['id']}/compensation-changes", ['type' => 'raise', 'to_salary' => 280_000, 'effective_date' => today()->addMonth()->toDateString()])->assertCreated()->json();
        $this->actAs($this->approver(), $this->hotel);
        $this->postJson("/api/v1/hr/compensation-changes/{$r['id']}/decide", ['approve' => true])->assertOk()->assertJsonPath('status', 'approved');
        $this->assertSame(260_000, (int) Employee::find($e['id'])->base_salary);
        $this->travel(32)->days();
        $this->postJson('/api/v1/hr/lifecycle/apply-due')->assertOk()->assertJsonPath('applied', 1);
        $this->assertSame(280_000, (int) Employee::find($e['id'])->base_salary);
    }

    public function test_bonuses_allowances_and_service_charge_feed_payroll_with_flags_and_balanced_gl(): void
    {
        $a = $this->hire(300_000);
        $b = $this->hire(300_000);
        $period = now()->format('Y-m');

        $this->postJson("/api/v1/hr/employees/{$a['id']}/pay-elements", ['code' => 'PRIME_REND', 'name' => 'Prime de rendement', 'kind' => 'one_off', 'amount' => 50_000, 'period' => $period])->assertCreated();
        $this->postJson("/api/v1/hr/employees/{$a['id']}/pay-elements", ['code' => 'TRANSPORT', 'name' => 'Indemnité de transport', 'kind' => 'recurring', 'amount' => 20_000, 'taxable' => false, 'cnps' => false, 'start_period' => now()->subMonth()->format('Y-m')])->assertCreated();
        $old = $this->postJson("/api/v1/hr/employees/{$a['id']}/pay-elements", ['code' => 'OLD', 'name' => 'Ancienne prime', 'kind' => 'one_off', 'amount' => 9_999, 'period' => $period])->assertCreated()->json();
        $this->postJson("/api/v1/hr/pay-elements/{$old['id']}/cancel")->assertOk();
        $this->postJson("/api/v1/hr/employees/{$a['id']}/pay-elements", ['code' => 'IRPP', 'name' => 'x', 'kind' => 'one_off', 'amount' => 1, 'period' => $period])->assertStatus(422);

        // Service charge 100 001 split 2:1 by points → shares add up exactly.
        $pool = $this->postJson('/api/v1/hr/service-charge-pools', ['period' => $period, 'amount' => 100_001, 'method' => 'points', 'entries' => [
            ['employee_id' => $a['id'], 'weight' => 2], ['employee_id' => $b['id'], 'weight' => 1],
        ]])->assertCreated()->json();
        $this->assertSame(100_001, array_sum(array_column($pool['shares'], 'amount')));
        $this->assertSame(66_667, $pool['shares'][0]['amount']);
        $this->postJson("/api/v1/hr/service-charge-pools/{$pool['id']}/distribute")->assertOk()->assertJsonPath('status', 'distributed');
        $this->postJson("/api/v1/hr/service-charge-pools/{$pool['id']}/distribute")->assertStatus(422);

        $run = $this->postJson('/api/v1/payroll/runs', ['period' => $period])->assertOk()->json();
        $slips = collect($run['payslips'])->keyBy('employee_id');
        $sa = $slips[$a['id']];
        $codes = collect($sa['lines'])->where('section', 'earning')->pluck('amount', 'code')->all();
        $this->assertSame(50_000, $codes['PRIME_REND']);
        $this->assertSame(20_000, $codes['TRANSPORT']);
        $this->assertSame(66_667, $codes['SVC']);
        $this->assertArrayNotHasKey('OLD', $codes);
        $this->assertSame(300_000 + 50_000 + 20_000 + 66_667, $sa['gross']);
        $this->assertSame($sa['gross'] - 20_000, $sa['taxable'], 'non-taxable allowance excluded from taxable');
        $this->assertSame(33_334, collect($slips[$b['id']]['lines'])->firstWhere('code', 'SVC')['amount']);

        $fd = $this->makeUser($this->group, 'finance-director', allHotels: true);
        $fd->givePermissionTo('payroll.approve');
        $this->actAs($fd, $this->hotel);
        $this->postJson("/api/v1/payroll/runs/{$run['id']}/approve")->assertOk();
        $this->actAs($this->user, $this->hotel);
        $bal = $this->assertBooksBalance();
        $this->assertSame($run['gross'], $bal['661100'], 'all earnings in salary expense');

        // Approved period is locked for new pools / cancellations.
        $p2 = $this->postJson('/api/v1/hr/service-charge-pools', ['period' => $period, 'amount' => 10, 'method' => 'days', 'entries' => [['employee_id' => $a['id'], 'weight' => 3]]])->assertCreated()->json();
        $this->postJson("/api/v1/hr/service-charge-pools/{$p2['id']}/distribute")->assertStatus(422);
    }

    public function test_transfer_between_hotels_keeps_seniority_and_leave_balance(): void
    {
        [, $hotel2] = $this->makeHotel('Akwa Test', 'BON', $this->group);
        $this->provision($hotel2);
        $this->actAs($this->user, $hotel2);
        $dept2 = Department::create(['code' => 'FO', 'name' => 'Réception Bonanjo']);
        $pos2 = Position::create(['department_id' => $dept2->id, 'title' => 'Réceptionniste senior']);
        $this->actAs($this->user, $this->hotel);

        $e = $this->hire(200_000);
        app(\App\Modules\HR\Application\HrService::class)->seedLeaveTypes();
        $annual = LeaveType::where('accrues', true)->first();
        $start = now()->subMonths(2)->startOfWeek();
        $this->postJson('/api/v1/hr/leave-requests', ['employee_id' => $e['id'], 'leave_type_id' => $annual->id, 'start_date' => $start->toDateString(), 'end_date' => $start->copy()->addDays(2)->toDateString()])->assertSuccessful();
        $before = $this->getJson("/api/v1/hr/employees/{$e['id']}")->json('leave_balance');

        $this->postJson("/api/v1/hr/employees/{$e['id']}/transfers", ['to_hotel_id' => $this->hotel->id, 'effective_date' => today()->toDateString()])->assertStatus(422);
        $this->postJson("/api/v1/hr/employees/{$e['id']}/transfers", ['to_hotel_id' => $hotel2->id, 'to_department_id' => Department::first()->id, 'effective_date' => today()->toDateString()])->assertStatus(422);
        $t = $this->postJson("/api/v1/hr/employees/{$e['id']}/transfers", [
            'to_hotel_id' => $hotel2->id, 'to_department_id' => $dept2->id, 'to_position_id' => $pos2->id, 'new_base_salary' => 220_000, 'effective_date' => today()->toDateString(),
        ])->assertCreated()->json();
        $this->postJson("/api/v1/hr/transfers/{$t['id']}/decide", ['approve' => true])->assertStatus(422); // same person

        $this->actAs($this->approver(), $this->hotel);
        $done = $this->postJson("/api/v1/hr/transfers/{$t['id']}/decide", ['approve' => true])->assertOk()->json();
        $this->assertSame('completed', $done['status']);
        $this->assertEquals($before, $done['leave_balance']);

        $this->getJson("/api/v1/hr/employees/{$e['id']}")->assertNotFound(); // no longer in hotel 1
        $this->actAs($this->user, $hotel2);
        $moved = $this->getJson("/api/v1/hr/employees/{$e['id']}")->assertOk()->json();
        $this->assertSame($e['hire_date'], $moved['hire_date'], 'seniority kept');
        $this->assertEquals($before, $moved['leave_balance'], 'leave balance kept');
        $this->assertSame(220_000, $moved['base_salary']);
        $this->assertSame($dept2->id, $moved['department_id']);
        $this->assertSame($done['to_number'], $moved['number']);
        $this->assertSame(['hire', 'transfer'], array_column($this->getJson("/api/v1/hr/employees/{$e['id']}/salary-history")->json(), 'source'));
        $this->assertCount(1, $this->getJson("/api/v1/hr/transfers?employee_id={$e['id']}")->json('data'));
    }
}
