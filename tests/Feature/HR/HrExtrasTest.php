<?php

namespace Tests\Feature\HR;

use App\Modules\HR\Domain\Attendance;
use App\Modules\HR\Domain\Department;
use App\Modules\HR\Domain\Position;
use App\Modules\HR\Domain\Shift;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HrExtrasTest extends TestCase
{
    private ?Department $dept = null;

    private function hire(int $salary = 200_000, array $extra = []): array
    {
        $this->dept ??= Department::create(['code' => 'FO', 'name' => 'Réception', 'cost_center' => 'rooms']);
        $hired = now()->startOfMonth()->subYear()->toDateString();

        return $this->postJson('/api/v1/hr/employees', $extra + [
            'first_name' => 'Aïcha', 'last_name' => 'Bello'.uniqid(), 'hire_date' => $hired, 'department_id' => $this->dept->id,
            'contract' => ['kind' => 'CDI', 'start_date' => $hired, 'base_salary' => $salary],
        ])->assertCreated()->json();
    }

    public function test_documents_upload_authorised_download_and_expiry_alerts(): void
    {
        Storage::fake('local');
        $e = $this->hire();
        $doc = $this->post("/api/v1/hr/employees/{$e['id']}/documents", [
            'file' => UploadedFile::fake()->create('cni.pdf', 50, 'application/pdf'), 'type' => 'id', 'expires_on' => now()->addDays(10)->toDateString(),
        ])->assertCreated()->assertJsonMissingPath('path')->json();
        $this->post("/api/v1/hr/employees/{$e['id']}/documents", ['file' => UploadedFile::fake()->create('diplome.pdf', 50, 'application/pdf'), 'type' => 'diploma'])->assertCreated();
        $this->post("/api/v1/hr/employees/{$e['id']}/documents", ['file' => UploadedFile::fake()->create('x.exe', 5), 'type' => 'id'])->assertStatus(422);

        $this->assertCount(2, $this->getJson("/api/v1/hr/employees/{$e['id']}/documents")->assertOk()->json());
        $this->get("/api/v1/hr/documents/{$doc['id']}/download")->assertOk()->assertDownload('cni.pdf');

        $alerts = $this->getJson('/api/v1/hr/documents/expiring?days=30')->assertOk()->json();
        $this->assertCount(1, $alerts);
        $this->assertSame('id', $alerts[0]['type']);
        $this->assertFalse($alerts[0]['expired']);

        $r = $this->makeUser($this->group, 'receptionist', hotels: [$this->hotel]);
        $this->actAs($r, $this->hotel);
        $this->get("/api/v1/hr/documents/{$doc['id']}/download")->assertForbidden();
        $this->getJson('/api/v1/hr/documents/expiring')->assertForbidden();
    }

    public function test_org_chart_tree_and_no_cycles(): void
    {
        $boss = $this->hire();
        $mid = $this->hire(extra: ['reports_to' => $boss['id']]);
        $low = $this->hire(extra: ['reports_to' => $mid['id']]);

        $this->patchJson("/api/v1/hr/employees/{$boss['id']}", ['reports_to' => $low['id']])->assertStatus(422)
            ->assertJsonPath('type', 'https://docs.opeshotelsoft.test/problems/org-cycle');
        $this->patchJson("/api/v1/hr/employees/{$mid['id']}", ['reports_to' => $mid['id']])->assertStatus(422);
        $this->patchJson("/api/v1/hr/employees/{$low['id']}", ['reports_to' => 999999])->assertStatus(422);

        $chart = $this->getJson('/api/v1/hr/org-chart')->assertOk()->json();
        $this->assertCount(1, $chart);
        $this->assertSame('Réception', $chart[0]['department']);
        $this->assertSame($boss['id'], $chart[0]['employees'][0]['id']);
        $this->assertSame($low['id'], $chart[0]['employees'][0]['reports'][0]['reports'][0]['id']);
    }

    public function test_onboarding_checklist_created_on_hire_and_completed_by_owner_role(): void
    {
        $this->dept = Department::create(['code' => 'HK', 'name' => 'Housekeeping']);
        $pos = Position::create(['department_id' => $this->dept->id, 'title' => 'Room attendant']);
        $this->putJson("/api/v1/hr/onboarding-templates/{$pos->id}", ['tasks' => [
            ['title' => 'Sign contract', 'owner_role' => 'hr-officer', 'due_days' => 0],
            ['title' => 'Uniform and keys', 'owner_role' => 'housekeeping-manager', 'due_days' => 2],
        ]])->assertSuccessful();

        $e = $this->hire(extra: ['position_id' => $pos->id]);
        $tasks = $this->getJson("/api/v1/hr/employees/{$e['id']}/onboarding")->assertOk()->json();
        $this->assertCount(2, $tasks);

        $hk = $this->makeUser($this->group, 'housekeeping-manager', hotels: [$this->hotel]);
        $this->actAs($hk, $this->hotel);
        $mine = $this->getJson('/api/v1/hr/onboarding-tasks/mine')->assertOk()->json();
        $this->assertSame(['Uniform and keys'], array_column($mine, 'title'));
        $this->postJson("/api/v1/hr/onboarding-tasks/{$tasks[0]['id']}/complete")->assertForbidden();
        $this->postJson("/api/v1/hr/onboarding-tasks/{$tasks[1]['id']}/complete")->assertOk()->assertJsonPath('completed_by', $hk->id);
    }

    public function test_public_holidays_seeded_movable_added_and_excluded_from_leave(): void
    {
        $year = now()->year + 1;
        $list = $this->getJson("/api/v1/hr/public-holidays?year=$year")->assertOk()->json();
        $this->assertContains("$year-05-20", array_map(fn ($h) => substr($h['date'], 0, 10), $list));
        $this->assertCount(6, $list);

        $e = $this->hire();
        $types = collect($this->getJson('/api/v1/hr/leave-types')->json())->keyBy('code');
        // Mon–Sat week containing Christmas: 6 working days, minus Christmas (fixed) and a movable holiday = 4.
        $start = Carbon::create($year, 12, 25)->startOfWeek()->toDateString();
        $end = Carbon::parse($start)->addDays(5)->toDateString();
        $movable = Carbon::create($year, 12, 25)->isTuesday() ? Carbon::parse($start)->addDays(2) : Carbon::parse($start)->addDay();

        $this->postJson('/api/v1/hr/public-holidays', ['date' => $movable->toDateString(), 'name' => 'Fête religieuse (test)'])->assertCreated()->assertJsonPath('movable', true);

        $req = $this->postJson('/api/v1/hr/leave-requests', ['employee_id' => $e['id'], 'leave_type_id' => $types['ANNUAL']['id'], 'start_date' => $start, 'end_date' => $end])
            ->assertCreated()->json();
        $this->assertEquals(Carbon::create($year, 12, 25)->isSunday() ? 5 : 4, $req['days']);
    }

    /**
     * Worked example (UNCERTIFIED rates, payroll expert must confirm): base 173,330 → hourly 1,000.
     * Tue 1 Dec shift 08–16, worked 08–23: 6 h weekday (×1.2 = 7,200) + 1 h night 22–23 (×1.5 = 1,500).
     * Sun 6 Dec shift 08–10, worked 08–12: 2 h Sunday (×1.5 = 3,000).
     * Fri 25 Dec (Christmas) shift 08–12, worked 08–15: 3 h holiday (×2.0 = 6,000).
     * Overtime = 17,700; gross = 191,030.
     */
    public function test_overtime_from_attendance_vs_shift_approved_then_paid(): void
    {
        $e = $this->hire(173_330);
        $y = now()->year;
        foreach ([["$y-12-01", '08:00', '16:00', '23:00'], ["$y-12-06", '08:00', '10:00', '12:00'], ["$y-12-25", '08:00', '12:00', '15:00']] as [$d, $s, $se, $out]) {
            Shift::create(['employee_id' => $e['id'], 'date' => $d, 'starts_at' => $s, 'ends_at' => $se]);
            Attendance::create(['employee_id' => $e['id'], 'date' => $d, 'clock_in' => "$d $s", 'clock_out' => "$d $out", 'worked_minutes' => 0]);
        }

        $entries = $this->postJson('/api/v1/hr/overtime/compute', ['from' => "$y-12-01", 'to' => "$y-12-31"])->assertOk()->json('entries');
        $byCat = collect($entries)->groupBy('category')->map(fn ($g) => $g->sum('minutes'));
        $this->assertEquals(['holiday' => 180, 'night' => 60, 'sunday' => 120, 'weekday' => 360], $byCat->sortKeys()->all());

        // Pending overtime is not paid.
        $run = $this->postJson('/api/v1/payroll/runs', ['period' => "$y-12"])->assertOk()->json();
        $this->assertSame(173_330, $run['payslips'][0]['gross']);

        $rules = $this->getJson('/api/v1/hr/overtime/rules')->assertOk()->json('rules');
        $this->assertSame('uncertified, payroll expert must confirm', $rules['note']);

        foreach ($entries as $x) {
            $this->postJson("/api/v1/hr/overtime/{$x['id']}/decide", ['approve' => true])->assertOk()->assertJsonPath('status', 'approved');
        }
        $this->postJson("/api/v1/hr/overtime/{$entries[0]['id']}/decide", ['approve' => true])->assertStatus(422);

        $run = $this->postJson('/api/v1/payroll/runs', ['period' => "$y-12"])->assertOk()->json();
        $lines = collect($run['payslips'][0]['lines'])->keyBy('code');
        $this->assertSame(7_200, $lines['HS']['amount']);
        $this->assertSame(1_500, $lines['HS_NUIT']['amount']);
        $this->assertSame(3_000, $lines['HS_DIM']['amount']);
        $this->assertSame(6_000, $lines['HS_FERIE']['amount']);
        $this->assertSame(191_030, $run['payslips'][0]['gross']);
    }

    public function test_payslip_labels_follow_employee_language(): void
    {
        $e = $this->hire(200_000, ['payslip_language' => 'en']);
        $run = $this->postJson('/api/v1/payroll/runs', ['period' => now()->format('Y-m')])->assertOk()->json();
        $slip = $this->getJson("/api/v1/payroll/payslips/{$run['payslips'][0]['id']}")->assertOk();
        $slip->assertJsonPath('language', 'en')->assertJsonPath('labels.net', 'Net pay')->assertJsonPath('lines.0.label', 'Base salary');

        $this->patchJson("/api/v1/hr/employees/{$e['id']}", ['payslip_language' => 'fr'])->assertOk();
        $this->getJson("/api/v1/payroll/payslips/{$run['payslips'][0]['id']}")->assertJsonPath('labels.net', 'Net à payer')->assertJsonPath('lines.0.label', 'Salaire de base');
        $this->patchJson("/api/v1/hr/employees/{$e['id']}", ['payslip_language' => 'de'])->assertStatus(422);
    }
}
