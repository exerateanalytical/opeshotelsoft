<?php

namespace Tests\Feature\Documents;

use App\Modules\Documents\Domain\IssuedDocument;
use App\Modules\Documents\Operations\HrSheets;
use App\Modules\HR\Domain\Department;
use App\Modules\HR\Domain\Employee;
use App\Modules\HR\Domain\LeaveType;
use App\Modules\HR\Domain\Lifecycle\CompensationChange;
use App\Modules\HR\Domain\Lifecycle\Transfer;
use App\Modules\HR\Domain\Position;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** D-C: HR letters from editable templates, settlement, leave slip, ID card and payroll journal. */
class OpsDocumentsHrTest extends TestCase
{
    private function hire(int $salary = 200_000, array $extra = []): array
    {
        $dept = Department::firstOrCreate(['code' => 'FO'], ['name' => 'Réception', 'cost_center' => 'rooms']);
        $pos = Position::firstOrCreate(['title' => 'Réceptionniste'], ['department_id' => $dept->id, 'category' => '5A']);
        $hired = now()->startOfMonth()->subYear()->toDateString();

        return $this->postJson('/api/v1/hr/employees', $extra + [
            'first_name' => 'Aïcha', 'last_name' => 'Bello'.uniqid(), 'hire_date' => $hired, 'department_id' => $dept->id, 'position_id' => $pos->id,
            'phone' => '+237677000111', 'email' => 'aicha@test.cm', 'id_number' => 'CNI123', 'cnps_number' => 'CNPS777',
            'contract' => ['kind' => 'CDI', 'start_date' => $hired, 'base_salary' => $salary, 'probation_end' => now()->subMonths(10)->toDateString()],
        ])->assertCreated()->json();
    }

    private function pdf(string $path, string $type, ?string $lang = null): IssuedDocument
    {
        $res = $this->get("/api/v1/documents/ops/$path");
        $res->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $res->getContent());
        $this->assertGreaterThan(1000, strlen($res->getContent()));
        $rec = IssuedDocument::where('type', $type)->latest('id')->first();
        $this->assertNotNull($rec, "no issued record for $type");
        $this->assertNotEmpty($rec->code);
        $this->assertNull($rec->amount, 'personal documents carry no public amount');
        $lang && $this->assertSame($lang, $rec->locale);

        return $rec;
    }

    private function html(array $built): string
    {
        return $built['data']['docHtml'];
    }

    public function test_templated_letters_follow_source_values_language_and_regenerate(): void
    {
        $e = $this->hire(250_000, ['payslip_language' => 'en']);
        $id = $e['id'];
        $hr = app(HrSheets::class);

        // offer, attestation, salary certificate: language is ?lang, then the employee's, then the hotel's
        $this->pdf("offer-letter/$id", 'offer_letter', 'en');
        $this->pdf("offer-letter/$id?lang=fr", 'offer_letter', 'en'); // same source: same record, now a copy
        $this->assertSame(1, (int) IssuedDocument::where('type', 'offer_letter')->value('copy_count'));
        $offer = $this->html($hr->offerLetter($id, null, 'fr'));
        $this->assertStringContainsString('250 000', $offer);
        $this->assertStringContainsString('Réceptionniste', $offer);
        $this->assertStringContainsString('Dear', $offer);
        $this->assertStringContainsString('Madame, Monsieur', $this->html($hr->offerLetter($id, 'fr', 'en')));
        $this->assertStringNotContainsString('{{', $offer);

        $this->pdf("employment-attestation/$id", 'employment_attestation', 'en');
        $this->assertStringContainsString('CNPS777', $this->html($hr->attestation($id, null, 'fr')));
        $run = $this->postJson('/api/v1/payroll/runs', ['period' => now()->format('Y-m')])->assertOk()->json();
        $slip = $run['payslips'][0];
        $this->pdf("salary-certificate/$id", 'salary_certificate');
        $cert = $this->html($hr->salaryCertificate($id, 'en', 'fr'));
        $this->assertStringContainsString(number_format($slip['gross'], 0, ',', ' '), $cert);

        // raise and transfer letters need an approved decision
        $change = CompensationChange::create(['employee_id' => $id, 'type' => 'raise', 'from_salary' => 250_000, 'to_salary' => 300_000, 'effective_date' => now()->addMonth()->toDateString(),
            'reason' => 'Performance', 'status' => 'pending', 'requested_by' => $this->user->id]);
        $this->get("/api/v1/documents/ops/raise-letter/{$change->id}")->assertStatus(422);
        $change->update(['status' => 'approved']);
        $this->pdf("raise-letter/{$change->id}", 'raise_letter', 'en');
        $this->assertStringContainsString('300 000', $this->html($hr->raiseLetter($change->id, null, 'fr')));

        [, $hotel2] = $this->makeHotel('Akwa Test', 'BON', $this->group);
        $tr = Transfer::create(['employee_id' => $id, 'from_hotel_id' => $this->hotel->id, 'to_hotel_id' => $hotel2->id, 'new_base_salary' => 260_000, 'effective_date' => now()->addMonth()->toDateString(),
            'reason' => 'Service need', 'status' => 'approved', 'requested_by' => $this->user->id]);
        $this->pdf("transfer-letter/{$tr->id}", 'transfer_letter');
        $this->assertStringContainsString('260 000', $this->html($hr->transferLetter($tr->id, 'fr', 'fr')));

        // warning and suspension letters from People sanctions; withdrawing voids the letter
        $w = $this->postJson('/api/v1/people/sanctions', ['employee_id' => $id, 'kind' => 'written_warning', 'incident_date' => now()->subDay()->toDateString(), 'reason' => 'Lateness', 'facts' => 'Three late arrivals'])->assertCreated()->json();
        $this->pdf("warning-letter/{$w['id']}", 'warning_letter');
        $this->assertStringContainsString('Three late arrivals', $this->html($hr->warningLetter($w['id'], 'en', 'fr')));
        $s = $this->postJson('/api/v1/people/sanctions', ['employee_id' => $id, 'kind' => 'suspension', 'incident_date' => now()->subDay()->toDateString(), 'reason' => 'Misconduct',
            'suspension_start' => now()->addDay()->toDateString(), 'suspension_end' => now()->addDays(3)->toDateString()])->assertCreated()->json();
        $susp = $this->pdf("warning-letter/{$s['id']}?lang=fr", 'warning_letter', 'fr');
        $this->assertStringContainsString('mise à pied', $this->html($hr->warningLetter($s['id'], 'fr', 'fr')));
        $this->postJson("/api/v1/people/sanctions/{$w['id']}/withdraw")->assertOk();
        $this->assertSame('voided', IssuedDocument::where('type', 'warning_letter')->where('source_id', $w['id'])->value('status'));
        $this->assertSame('valid', $susp->fresh()->status);
        $this->get("/api/v1/documents/ops/warning-letter/{$w['id']}")->assertStatus(422);

        // regenerate: edited template -> new version, the old verify page says "replaced by"
        $this->putJson('/api/v1/documents/templates/hr_attestation/en', ['title' => 'Attestation', 'body' => 'Certified: {{ employee.name }} works as {{ employee.position }}. Ref {{ doc.number }}'])->assertOk();
        $this->putJson('/api/v1/documents/templates/hr_attestation/en', ['title' => 'x', 'body' => '{{ bad.thing }}'])->assertStatus(422);
        $this->postJson("/api/v1/documents/ops/employment-attestation/$id/regenerate", ['reason' => 'Template updated'])->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $old = IssuedDocument::where('type', 'employment_attestation')->where('source_type', 'employment_attestation')->first();
        $new = IssuedDocument::where('type', 'employment_attestation')->where('source_type', 'employment_attestation#v2')->first();
        $this->assertNotNull($new);
        $this->assertSame('replaced', $old->fresh()->status);
        $this->assertSame($new->id, $old->fresh()->replaced_by_id);
        $this->assertStringEndsWith('-R2', $new->number);
        $this->assertStringContainsString('-R2', $this->html((function () use ($hr, $id) { $hr->suffix = '-R2'; return $hr->attestation($id, 'en', 'fr'); })()));
        $hr->suffix = '';
        $this->pdf("employment-attestation/$id", 'employment_attestation'); // reprints hit the current version
        $this->assertSame(1, (int) $new->fresh()->copy_count);
        $this->postJson("/api/v1/documents/ops/employment-attestation/$id/regenerate", [])->assertStatus(422);
        $this->postJson("/api/v1/documents/ops/staff-id-card/999999/regenerate", ['reason' => 'abc'])->assertStatus(422);
        $this->actAs($this->makeUser($this->group, 'payroll-officer', allHotels: true), $this->hotel);
        $this->postJson("/api/v1/documents/ops/employment-attestation/$id/regenerate", ['reason' => 'No right'])->assertForbidden();
    }

    public function test_exit_settlement_leave_slip_id_card_and_payroll_journal(): void
    {
        Storage::fake('public');
        $e = $this->hire(300_000);
        $id = $e['id'];
        $hr = app(HrSheets::class);

        // exit: termination letter or resignation acceptance, settlement flagged for payroll-expert review
        $x = $this->postJson('/api/v1/people/exits', ['employee_id' => $id, 'kind' => 'termination', 'notice_date' => now()->toDateString(), 'last_day' => now()->addMonth()->toDateString(), 'notice_worked' => false])->assertCreated()->json();
        $this->pdf("termination-letter/{$x['id']}", 'termination_letter');
        $this->assertStringContainsString('Licenciement', $this->html($hr->terminationLetter($x['id'], 'fr', 'fr')));
        $this->assertStringContainsString('Termination', $this->html($hr->terminationLetter($x['id'], 'en', 'fr')));
        $this->pdf("final-settlement/{$x['id']}?lang=en", 'final_settlement', 'en');
        $sheet = $hr->settlement($x['id'], 'en', 'fr')['data']['sheet'];
        $this->assertSame((int) $x['settlement']['total'], $sheet['blocks'][0]['totals'][0][2]);
        $this->assertSame(count($x['settlement']['lines']), count($sheet['blocks'][0]['rows']));
        $this->assertStringContainsString('payroll expert', $sheet['status_note'][1]);
        $this->assertStringContainsString('expert paie', $sheet['status_note'][0]);
        $res = $this->postJson('/api/v1/people/exits', ['employee_id' => $this->hire()['id'], 'kind' => 'resignation', 'notice_date' => now()->toDateString(), 'last_day' => now()->addMonth()->toDateString()])->assertCreated()->json();
        $this->pdf("termination-letter/{$res['id']}", 'termination_letter');
        $this->assertStringContainsString('démission', $this->html($hr->terminationLetter($res['id'], 'fr', 'fr')));

        // leave slip: only approved leave
        app(\App\Modules\HR\Application\HrService::class)->seedLeaveTypes();
        $annual = LeaveType::where('accrues', true)->first();
        $start = now()->addMonths(2)->startOfWeek()->toDateString();
        $leave = $this->postJson('/api/v1/hr/leave-requests', ['employee_id' => $id, 'leave_type_id' => $annual->id, 'start_date' => $start, 'end_date' => now()->addMonths(2)->startOfWeek()->addDays(2)->toDateString()])->assertCreated()->json();
        $this->get("/api/v1/documents/ops/leave-slip/{$leave['id']}")->assertStatus(422);
        $this->postJson("/api/v1/hr/leave-requests/{$leave['id']}/decide", ['approve' => true])->assertOk();
        $this->pdf("leave-slip/{$leave['id']}?lang=en", 'leave_slip', 'en');
        $this->assertSame((string) (float) $leave['days'], $hr->leaveSlip($leave['id'], 'en', 'fr')['data']['sheet']['meta'][6][2]);

        // staff ID card (ID-1) with the employee photo
        Storage::disk('public')->put('photos/e.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        Employee::whereKey($id)->update(['photo_path' => 'photos/e.png']);
        $card = $hr->idCard($id, null, 'fr');
        $this->assertStringStartsWith('data:image/png', $card['data']['card']['photo']);
        $this->assertSame('id1', $card['size']);
        $this->pdf("staff-id-card/$id", 'staff_id_card');

        // payroll journal: totals match the run
        $run = $this->postJson('/api/v1/payroll/runs', ['period' => now()->format('Y-m')])->assertOk()->json();
        $this->pdf("payroll-journal/{$run['id']}", 'payroll_journal');
        $foot = $hr->payrollJournal($run['id'], null, 'fr')['data']['sheet']['blocks'][0]['foot'];
        $this->assertSame((int) $run['gross'], $foot['gross']);
        $this->assertSame((int) $run['net'], $foot['net']);

        // permissions, then tenant isolation
        $this->actAs($this->makeUser($this->group, 'receptionist', allHotels: true), $this->hotel);
        foreach (["offer-letter/$id", "employment-attestation/$id", "salary-certificate/$id", "staff-id-card/$id", "payroll-journal/{$run['id']}", "leave-slip/{$leave['id']}", "termination-letter/{$x['id']}", "final-settlement/{$x['id']}"] as $p) {
            $this->get("/api/v1/documents/ops/$p")->assertForbidden();
        }
        $this->actAs($this->makeUser($this->group, 'payroll-officer', allHotels: true), $this->hotel);
        $this->get("/api/v1/documents/ops/warning-letter/1")->assertForbidden(); // payroll staff are not discipline staff
        [$g2, $h2] = $this->makeHotel('Other Group', 'OTH');
        $this->provision($h2);
        $this->actAs($this->makeUser($g2, 'general-manager', allHotels: true), $h2);
        foreach (["offer-letter/$id", "staff-id-card/$id", "payroll-journal/{$run['id']}", "leave-slip/{$leave['id']}", "termination-letter/{$x['id']}", "final-settlement/{$x['id']}"] as $p) {
            $this->get("/api/v1/documents/ops/$p")->assertNotFound();
        }
    }

    public function test_templates_screen_lists_the_hr_kinds_with_placeholders(): void
    {
        $list = collect($this->getJson('/api/v1/documents/templates')->assertOk()->json('templates'));
        foreach (['hr_offer', 'hr_attestation', 'hr_salary_cert', 'hr_raise', 'hr_transfer', 'hr_warning', 'hr_suspension', 'hr_termination', 'hr_resignation'] as $k) {
            foreach (['fr', 'en'] as $l) {
                $t = $list->first(fn ($x) => $x['kind'] === $k && $x['locale'] === $l);
                $this->assertNotNull($t, "$k/$l");
                $this->assertContains('employee.name', $t['placeholders']);
                $this->assertNotEmpty($t['body']);
            }
        }
        $this->postJson('/api/v1/documents/templates/preview', ['kind' => 'hr_raise', 'locale' => 'en', 'title' => 't', 'body' => '{{ employee.name }} {{ change.to_salary }}'])->assertOk()
            ->assertJsonPath('html', '<p>Marie Nkoulou 170 000</p>');
    }
}
