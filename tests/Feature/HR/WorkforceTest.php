<?php

namespace Tests\Feature\HR;

use App\Models\User;
use App\Modules\HR\Domain\Attendance;
use App\Modules\HR\Domain\Contract;
use App\Modules\HR\Domain\Department;
use App\Modules\HR\Domain\Employee;
use App\Modules\HR\Domain\LeaveRequest;
use App\Modules\HR\Domain\LeaveType;
use App\Modules\HR\Domain\Shift;
use App\Modules\HR\Workforce\Application\WorkRules;
use App\Modules\HR\Workforce\Domain\Roster;
use App\Modules\HR\Workforce\Domain\RosterShift;
use App\Modules\HR\Workforce\Domain\ShiftRequest;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WorkforceTest extends TestCase
{
    private Department $dept;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dept = Department::create(['code' => 'FO', 'name' => 'Front office', 'cost_center' => 'rooms']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function emp(string $n, ?Department $dept = null, int $salary = 208_000, ?string $hired = null): Employee
    {
        $e = Employee::create(['number' => $n, 'first_name' => "Emp$n", 'last_name' => 'Test', 'hire_date' => $hired ?? now()->subYears(2)->toDateString(), 'department_id' => ($dept ?? $this->dept)->id]);
        Contract::create(['employee_id' => $e->id, 'kind' => 'CDI', 'start_date' => $e->hire_date, 'base_salary' => $salary, 'hours_per_week' => 40]);

        return $e;
    }

    private function monday(): string
    {
        return now()->addWeeks(2)->startOfWeek()->toDateString();
    }

    private function reservation(string $arrival, string $departure, string $status = 'confirmed', ?int $hotelId = null): void
    {
        static $seq = 0;
        DB::table('reservations')->insert([
            'group_id' => $this->group->id, 'hotel_id' => $hotelId ?? $this->hotel->id, 'number' => 'R'.(++$seq).uniqid(), 'booking_ref' => 'B'.$seq, 'status' => $status,
            'guest_id' => DB::table('guests')->value('id'), 'room_type_id' => DB::table('room_types')->value('id'), 'rate_plan_id' => DB::table('rate_plans')->value('id'),
            'arrival' => $arrival, 'departure' => $departure, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function configureDept(array $extra = []): void
    {
        $this->putJson('/api/v1/workforce/dept-rules', $extra + [
            'department_id' => $this->dept->id, 'driver' => 'inhouse', 'units_per_staff' => 2, 'min_staff' => 1, 'revenue_keys' => ['rooms'], 'include_pos' => false,
            'templates' => [['name' => 'Morning', 'starts_at' => '06:00', 'ends_at' => '14:00', 'share' => 100], ['name' => 'Evening', 'starts_at' => '14:00', 'ends_at' => '22:00', 'share' => 50]],
        ])->assertOk();
    }

    // ---- pure labour rules --------------------------------------------------------

    public function test_work_rules_detect_rest_weekly_hours_weekly_rest_and_nights(): void
    {
        $mon = $this->monday();
        $d = fn (int $i) => Carbon::parse($mon)->addDays($i)->toDateString();
        // 22:00 -> 06:00 then 14:00 next day = 8h rest
        $rest = WorkRules::violations([['date' => $d(0), 'starts_at' => '22:00', 'ends_at' => '06:00'], ['date' => $d(1), 'starts_at' => '14:00', 'ends_at' => '20:00']]);
        $this->assertSame(['rest'], array_values(array_unique(array_column($rest, 'rule'))));

        $week = array_map(fn ($i) => ['date' => $d($i), 'starts_at' => '08:00', 'ends_at' => '17:00'], range(0, 6)); // 7 days x 9h = 63h
        $rules = array_column(WorkRules::violations($week), 'rule');
        $this->assertContains('weekly_hours', $rules);
        $this->assertContains('weekly_rest', $rules);

        $nights = array_map(fn ($i) => ['date' => $d($i), 'starts_at' => '22:00', 'ends_at' => '06:00'], range(0, 5)); // 6 nights in a row
        $this->assertContains('night', array_column(WorkRules::violations($nights), 'rule'));

        $this->assertSame([], WorkRules::violations([['date' => $d(0), 'starts_at' => '08:00', 'ends_at' => '16:00'], ['date' => $d(1), 'starts_at' => '08:00', 'ends_at' => '16:00']]));
    }

    // ---- demand forecast ----------------------------------------------------------

    public function test_forecast_counts_arrivals_departures_inhouse_and_ignores_other_hotels_and_cancelled(): void
    {
        $mon = $this->monday();
        $d = fn (int $i) => Carbon::parse($mon)->addDays($i)->toDateString();
        $this->seedInventory(1);
        $this->reservation($d(0), $d(2));
        $this->reservation($d(0), $d(1));
        $this->reservation($d(1), $d(3), 'cancelled');
        [, $other] = $this->makeHotel('Other Group', 'OTH');
        $this->reservation($d(0), $d(2), 'confirmed', $other->id);
        $this->configureDept();

        $rows = collect($this->getJson("/api/v1/workforce/forecast?from=$mon&to={$d(2)}")->assertOk()->json())->keyBy('date');
        $this->assertSame([2, 0, 2], [$rows[$d(0)]['arrivals'], $rows[$d(0)]['departures'], $rows[$d(0)]['inhouse']]);
        $this->assertSame([0, 1, 1], [$rows[$d(1)]['arrivals'], $rows[$d(1)]['departures'], $rows[$d(1)]['inhouse']]);
        $this->assertSame(1, $rows[$d(0)]['departments'][0]['required']); // 2 in-house / 2 per staff
    }

    // ---- auto-rostering -----------------------------------------------------------

    public function test_auto_roster_follows_demand_rules_and_leave_then_publishes_to_hr_shifts(): void
    {
        $mon = $this->monday();
        $d = fn (int $i) => Carbon::parse($mon)->addDays($i)->toDateString();
        $this->seedInventory(1);
        foreach (range(0, 6) as $i) {
            foreach (range(1, 6) as $k) { // 6 in-house guests each night -> 3 staff morning, 2 evening
                $this->reservation($d($i), $d($i + 1));
            }
        }
        $emps = collect(range(1, 10))->map(fn ($n) => $this->emp("E$n"));
        $this->configureDept();
        $on = LeaveType::firstOrCreate(['code' => 'ANNUAL'], ['name' => 'Annual', 'paid' => true, 'accrues' => true]);
        LeaveRequest::create(['employee_id' => $emps[0]->id, 'leave_type_id' => $on->id, 'start_date' => $d(0), 'end_date' => $d(2), 'days' => 3, 'status' => 'approved']);

        $roster = $this->postJson('/api/v1/workforce/rosters/generate', ['week_start' => $mon])->assertCreated()->json();
        $this->assertSame('draft', $roster['status']);
        $shifts = collect($roster['shifts']);
        $this->assertSame(3 * 7, $shifts->where('starts_at', '06:00')->count());
        $this->assertSame(2 * 7, $shifts->where('starts_at', '14:00')->count());
        $this->assertSame(35, $shifts->count());
        $this->assertSame(0, $shifts->where('employee_id', $emps[0]->id)->filter(fn ($s) => substr($s['date'], 0, 10) <= $d(2))->count(), 'nobody on leave is scheduled');

        // the generated roster itself satisfies every blocking labour rule
        $shown = $this->getJson("/api/v1/workforce/rosters/{$roster['id']}")->assertOk()->json();
        $blocking = collect($shown['violations'])->whereIn('rule', ['overlap', 'rest', 'daily_hours', 'weekly_hours', 'weekly_rest', 'night', 'on_leave']);
        $this->assertSame([], $blocking->values()->all());

        $this->postJson("/api/v1/workforce/rosters/{$roster['id']}/publish")->assertOk()->assertJsonPath('status', 'published');
        $this->assertSame($shifts->whereNotNull('employee_id')->count(), Shift::whereBetween('date', [$mon, $d(6)])->count());
        $this->postJson('/api/v1/workforce/rosters/generate', ['week_start' => $mon])->assertStatus(422);
    }

    public function test_understaffed_shifts_stay_open_with_warning_and_rule_breaking_edit_blocks_publish(): void
    {
        $mon = $this->monday();
        $this->seedInventory(1);
        foreach ([0, 1] as $i) {
            foreach (range(1, 8) as $k) {
                $this->reservation(Carbon::parse($mon)->addDays($i)->toDateString(), Carbon::parse($mon)->addDays($i + 1)->toDateString());
            }
        }
        $a = $this->emp('A1');
        $this->configureDept();
        $roster = $this->postJson('/api/v1/workforce/rosters/generate', ['week_start' => $mon, 'department_id' => $this->dept->id])->assertCreated()->json();
        $this->assertGreaterThan(0, collect($roster['shifts'])->whereNull('employee_id')->count());
        $this->assertContains('understaffed', array_column($roster['warnings'], 'rule'));

        // manager forces the same person onto overlapping shifts
        $d0 = $mon;
        $bad = [
            ['employee_id' => $a->id, 'department_id' => $this->dept->id, 'date' => $d0, 'starts_at' => '06:00', 'ends_at' => '14:00'],
            ['employee_id' => $a->id, 'department_id' => $this->dept->id, 'date' => $d0, 'starts_at' => '10:00', 'ends_at' => '18:00'],
        ];
        $this->putJson("/api/v1/workforce/rosters/{$roster['id']}/shifts", ['shifts' => $bad])->assertOk();
        $this->postJson("/api/v1/workforce/rosters/{$roster['id']}/publish")->assertStatus(422)->assertJsonPath('title', 'Labour rules');
        $this->assertSame(0, Shift::count());
        $this->postJson("/api/v1/workforce/rosters/{$roster['id']}/publish", ['acknowledge' => true])->assertOk();
        $this->assertSame(2, Shift::count());
    }

    public function test_published_shifts_feed_existing_overtime_computation(): void
    {
        $mon = $this->monday();
        $a = $this->emp('OT1');
        $roster = Roster::create(['week_start' => $mon, 'status' => 'draft']);
        $this->putJson("/api/v1/workforce/rosters/{$roster->id}/shifts", ['shifts' => [['employee_id' => $a->id, 'department_id' => $this->dept->id, 'date' => $mon, 'starts_at' => '08:00', 'ends_at' => '16:00']]])->assertOk();
        $this->postJson("/api/v1/workforce/rosters/{$roster->id}/publish")->assertOk();
        // worked two hours past the scheduled end: only those two hours are overtime
        Attendance::create(['employee_id' => $a->id, 'date' => $mon, 'clock_in' => "$mon 08:00:00", 'clock_out' => "$mon 18:00:00", 'worked_minutes' => 600]);
        $this->postJson('/api/v1/hr/overtime/compute', ['from' => $mon, 'to' => $mon])->assertSuccessful();
        $this->assertSame(120, (int) DB::table('overtime_entries')->where('employee_id', $a->id)->sum('minutes'));
    }

    // ---- swaps and open shifts ----------------------------------------------------

    private function publishedRoster(array $rows): Roster
    {
        $mon = $this->monday();
        $roster = Roster::create(['week_start' => $mon, 'status' => 'draft']);
        $this->putJson("/api/v1/workforce/rosters/{$roster->id}/shifts", ['shifts' => $rows])->assertOk();
        $this->postJson("/api/v1/workforce/rosters/{$roster->id}/publish")->assertOk();

        return $roster->fresh();
    }

    private function loginAs(Employee $e): User
    {
        $u = $this->makeUser($this->group, 'receptionist', hotels: [$this->hotel]);
        $e->update(['user_id' => $u->id]);
        $this->actAs($u, $this->hotel);

        return $u;
    }

    public function test_shift_swap_needs_colleague_then_manager_and_moves_hr_shifts(): void
    {
        $mon = $this->monday();
        $a = $this->emp('S1');
        $b = $this->emp('S2');
        $roster = $this->publishedRoster([
            ['employee_id' => $a->id, 'department_id' => $this->dept->id, 'date' => $mon, 'starts_at' => '06:00', 'ends_at' => '14:00'],
            ['employee_id' => $b->id, 'department_id' => $this->dept->id, 'date' => $mon, 'starts_at' => '14:00', 'ends_at' => '22:00'],
        ]);
        [$sa, $sb] = [$roster->shifts()->where('employee_id', $a->id)->first(), $roster->shifts()->where('employee_id', $b->id)->first()];
        $gm = $this->user;

        $this->loginAs($a);
        $req = $this->postJson('/api/v1/workforce/swaps', ['roster_shift_id' => $sa->id, 'counterpart_id' => $b->id, 'target_shift_id' => $sb->id])->assertCreated()->json();
        $this->postJson("/api/v1/workforce/requests/{$req['id']}/decide", ['approve' => true])->assertForbidden(); // employees cannot decide

        $this->actAs($gm, $this->hotel);
        $this->postJson("/api/v1/workforce/requests/{$req['id']}/decide", ['approve' => true])->assertStatus(422); // colleague has not accepted

        $this->loginAs($b);
        $this->postJson("/api/v1/workforce/requests/{$req['id']}/respond", ['accept' => true])->assertOk()->assertJsonPath('status', 'accepted');

        $this->actAs($gm, $this->hotel);
        $this->postJson("/api/v1/workforce/requests/{$req['id']}/decide", ['approve' => true])->assertOk()->assertJsonPath('status', 'approved');
        $this->assertSame($b->id, $sa->fresh()->employee_id);
        $this->assertSame($a->id, $sb->fresh()->employee_id);
        $this->assertTrue(Shift::where('employee_id', $b->id)->where('starts_at', '06:00:00')->exists());
        $this->assertFalse(Shift::where('employee_id', $a->id)->where('starts_at', '06:00:00')->exists());
    }

    public function test_open_shift_claim_is_approved_once_and_refuses_rule_breaks(): void
    {
        $mon = $this->monday();
        $tue = Carbon::parse($mon)->addDay()->toDateString();
        $a = $this->emp('C1');
        $b = $this->emp('C2');
        $roster = $this->publishedRoster([
            ['employee_id' => null, 'department_id' => $this->dept->id, 'date' => $mon, 'starts_at' => '06:00', 'ends_at' => '14:00'],
            ['employee_id' => $b->id, 'department_id' => $this->dept->id, 'date' => $mon, 'starts_at' => '22:00', 'ends_at' => '23:59'],
            ['employee_id' => null, 'department_id' => $this->dept->id, 'date' => $tue, 'starts_at' => '06:00', 'ends_at' => '14:00'],
        ]);
        $open = $roster->shifts()->whereNull('employee_id')->orderBy('date')->get();
        $gm = $this->user;

        $this->loginAs($a);
        $this->assertCount(2, $this->getJson('/api/v1/workforce/open-shifts')->assertOk()->json());
        $claimA = $this->postJson("/api/v1/workforce/open-shifts/{$open[0]->id}/claim")->assertCreated()->json();
        $this->postJson("/api/v1/workforce/open-shifts/{$open[0]->id}/claim")->assertStatus(422); // duplicate
        $this->loginAs($b);
        $claimB = $this->postJson("/api/v1/workforce/open-shifts/{$open[0]->id}/claim")->assertCreated()->json();

        // B worked 22:00-23:59 on Monday: a 06:00 Tuesday start would break the 11h rest rule, a Monday 06:00 shift is fine for A
        $this->actAs($gm, $this->hotel);
        $this->postJson("/api/v1/workforce/requests/{$claimA['id']}/decide", ['approve' => true])->assertOk();
        $this->assertSame($a->id, $open[0]->fresh()->employee_id);
        $this->assertSame('rejected', ShiftRequest::find($claimB['id'])->status, 'competing claim closed');
        $this->assertTrue(Shift::where('employee_id', $a->id)->whereDate('date', $mon)->exists());

        $this->loginAs($b);
        $claimB2 = $this->postJson("/api/v1/workforce/open-shifts/{$open[1]->id}/claim")->assertCreated()->json();
        $this->actAs($gm, $this->hotel);
        $this->postJson("/api/v1/workforce/requests/{$claimB2['id']}/decide", ['approve' => true])->assertStatus(422)->assertJsonPath('title', 'Labour rules');
        $this->assertNull($open[1]->fresh()->employee_id);
    }

    // ---- leave policies -----------------------------------------------------------

    public function test_leave_accrual_is_idempotent_with_seniority_cap_carry_over_and_expiry(): void
    {
        $type = LeaveType::firstOrCreate(['code' => 'ANNUAL'], ['name' => 'Annual', 'paid' => true, 'accrues' => true]);
        $e = $this->emp('L1', hired: '2018-03-10'); // 5+ years of service in 2024
        $this->putJson('/api/v1/workforce/leave/policies', [
            'leave_type_id' => $type->id, 'accrual_per_month' => 1.5, 'max_balance' => 30, 'carry_over_max' => 10, 'carry_over_expiry_months' => 3,
            'seniority_rules' => [['years' => 5, 'extra_days' => 2], ['years' => 10, 'extra_days' => 4]],
        ])->assertSuccessful();

        foreach (range(1, 12) as $m) {
            $month = sprintf('2024-%02d', $m);
            $this->postJson('/api/v1/workforce/leave/accrue', ['month' => $month])->assertOk();
            $again = $this->postJson('/api/v1/workforce/leave/accrue', ['month' => $month])->assertOk()->json();
            $this->assertSame(0, $again['entries'], 'running a month twice adds nothing');
        }
        // 12 x 1.5 = 18 + 2 seniority days in March
        $bal = fn (string $asOf) => collect($this->getJson("/api/v1/workforce/leave/balances?employee_id={$e->id}&as_of=$asOf")->assertOk()->json('balances'))->firstWhere('leave_type_id', $type->id)['balance'];
        $this->assertEquals(20.0, $bal('2024-12-31'));

        // 6 days taken in 2024 (reduces the closing balance to 14), then year-end: carry 10, 4 lapse
        LeaveRequest::create(['employee_id' => $e->id, 'leave_type_id' => $type->id, 'start_date' => '2024-08-05', 'end_date' => '2024-08-10', 'days' => 6, 'status' => 'approved']);
        $this->assertEquals(14.0, $bal('2024-12-31'));
        $res = $this->postJson('/api/v1/workforce/leave/year-end', ['year' => 2024])->assertOk()->json();
        $this->assertEquals(10.0, $res['carried_days']);
        $this->assertEquals(4.0, $res['lapsed_days']);
        $this->assertEquals(10.0, $bal('2025-01-02'));
        $this->assertEquals(0.0, $this->postJson('/api/v1/workforce/leave/year-end', ['year' => 2024])->json('carried_days'), 'year-end is idempotent');

        // 4 carried days used in Feb 2025; the remaining 6 expire after 3 months
        LeaveRequest::create(['employee_id' => $e->id, 'leave_type_id' => $type->id, 'start_date' => '2025-02-03', 'end_date' => '2025-02-06', 'days' => 4, 'status' => 'approved']);
        $this->assertEquals(6.0, $bal('2025-03-01'));
        $this->assertSame(1, app(\App\Modules\HR\Workforce\Application\LeavePolicyService::class)->expireCarried('2025-05-01'));
        $this->assertEquals(0.0, $bal('2025-05-01'));
    }

    public function test_leave_balance_cap_limits_accrual(): void
    {
        $type = LeaveType::firstOrCreate(['code' => 'ANNUAL'], ['name' => 'Annual', 'paid' => true, 'accrues' => true]);
        $e = $this->emp('L2', hired: '2024-01-01');
        $this->putJson('/api/v1/workforce/leave/policies', ['leave_type_id' => $type->id, 'accrual_per_month' => 2, 'max_balance' => 3, 'carry_over_max' => 0])->assertSuccessful();
        foreach (['2024-01', '2024-02', '2024-03'] as $m) {
            $this->postJson('/api/v1/workforce/leave/accrue', ['month' => $m])->assertOk();
        }
        $this->assertEquals(3.0, collect($this->getJson("/api/v1/workforce/leave/balances?employee_id={$e->id}&as_of=2024-03-31")->json('balances'))->first()['balance']);
    }

    // ---- clocking and anomalies -----------------------------------------------------

    private function geoHotel(): void
    {
        $this->putJson('/api/v1/workforce/settings', ['geo_lat' => 4.0511, 'geo_lng' => 9.7679, 'geo_radius_m' => 150, 'geo_required' => true, 'photo_required' => false, 'late_tolerance_min' => 10])->assertOk();
    }

    public function test_geofenced_clock_rejects_outside_accepts_inside_and_flags_late_with_photo_proof(): void
    {
        Storage::fake('local');
        Carbon::setTestNow('2026-12-09 09:00:00');
        $e = $this->emp('G1');
        Shift::create(['employee_id' => $e->id, 'date' => '2026-12-09', 'starts_at' => '08:00', 'ends_at' => '16:00']);
        $this->geoHotel();
        $this->loginAs($e);

        $this->postJson('/api/v1/workforce/clock/in', ['lat' => 4.1, 'lng' => 9.9])->assertStatus(422)->assertJsonPath('title', 'Outside geofence');
        $this->postJson('/api/v1/workforce/clock/in', [])->assertStatus(422);
        $this->assertSame(0, Attendance::count());

        $png = 'data:image/png;base64,'.base64_encode('fakepng');
        $res = $this->postJson('/api/v1/workforce/clock/in', ['lat' => 4.0512, 'lng' => 9.7680, 'accuracy' => 12, 'photo' => $png, 'device_id' => 'phone-1'])->assertOk()->json();
        $this->assertTrue($res['inside_geofence']);
        $this->assertSame(['late'], array_column($res['anomalies'], 'kind')); // 09:00 vs 08:00 + 10 min tolerance
        $this->assertCount(1, Storage::disk('local')->allFiles('workforce/photos'));
        $this->assertSame(1, Attendance::whereNull('clock_out')->count());

        Carbon::setTestNow('2026-12-09 17:00:00');
        $this->postJson('/api/v1/workforce/clock/out', ['lat' => 4.0511, 'lng' => 9.7679])->assertOk();
        $this->assertSame(480, Attendance::first()->worked_minutes);
    }

    public function test_impossible_travel_and_buddy_punching_hints_are_flagged_and_reviewable(): void
    {
        Carbon::setTestNow('2026-12-09 08:00:00');
        $a = $this->emp('B1');
        $b = $this->emp('B2');
        $this->putJson('/api/v1/workforce/settings', ['geo_lat' => 4.0511, 'geo_lng' => 9.7679, 'geo_radius_m' => 150, 'geo_required' => false])->assertOk();

        $this->loginAs($a);
        $this->postJson('/api/v1/workforce/clock/in', ['lat' => 4.0511, 'lng' => 9.7679, 'device_id' => 'shared-phone'])->assertOk();
        Carbon::setTestNow('2026-12-09 08:10:00');
        // 200+ km away 10 minutes later
        $far = $this->postJson('/api/v1/workforce/clock/out', ['lat' => 5.9631, 'lng' => 10.1591, 'device_id' => 'shared-phone'])->assertOk()->json();
        $kinds = array_column($far['anomalies'], 'kind');
        $this->assertContains('impossible_travel', $kinds);
        $this->assertContains('outside_geofence', $kinds);

        $this->loginAs($b);
        Carbon::setTestNow('2026-12-09 08:12:00');
        $buddy = $this->postJson('/api/v1/workforce/clock/in', ['lat' => 4.0511, 'lng' => 9.7679, 'device_id' => 'shared-phone'])->assertOk()->json();
        $this->assertContains('buddy_punch', array_column($buddy['anomalies'], 'kind'));

        $this->actAs($this->user, $this->hotel);
        $list = $this->getJson('/api/v1/workforce/anomalies')->assertOk()->json();
        $this->assertGreaterThanOrEqual(3, count($list));
        $id = collect($list)->firstWhere('kind', 'buddy_punch')['id'];
        $this->postJson("/api/v1/workforce/anomalies/$id/review", ['status' => 'reviewed'])->assertOk()->assertJsonPath('status', 'reviewed');
        $this->assertNotContains($id, array_column($this->getJson('/api/v1/workforce/anomalies')->json(), 'id'));
    }

    public function test_kiosk_pin_clock_locks_after_failures_and_missing_clock_out_is_flagged_once(): void
    {
        Carbon::setTestNow('2026-12-09 08:00:00');
        $e = $this->emp('K1');
        $this->putJson("/api/v1/workforce/employees/{$e->id}/pin", ['pin' => '4321'])->assertOk();
        $this->postJson('/api/v1/workforce/employees/'.$e->id.'/pin', [])->assertStatus(405);

        $this->postJson('/api/v1/workforce/kiosk/in', ['number' => 'K1', 'pin' => '4321'])->assertOk()->assertJsonPath('employee', $e->full_name);
        $this->assertSame('pin', Attendance::first()->source);

        $e2 = $this->emp('K2');
        $this->putJson("/api/v1/workforce/employees/{$e2->id}/pin", ['pin' => '1111'])->assertOk();
        foreach (range(1, 5) as $i) {
            $this->postJson('/api/v1/workforce/kiosk/in', ['number' => 'K2', 'pin' => '9999'])->assertStatus(403);
        }
        $this->postJson('/api/v1/workforce/kiosk/in', ['number' => 'K2', 'pin' => '1111'])->assertStatus(423); // locked even with the right PIN

        Carbon::setTestNow('2026-12-10 08:00:00'); // K1 never clocked out
        $this->assertSame(1, $this->postJson('/api/v1/workforce/scan')->assertOk()->json('missing_clock_out'));
        $this->assertSame(0, $this->postJson('/api/v1/workforce/scan')->json('missing_clock_out'));
    }

    // ---- dashboard ------------------------------------------------------------------

    public function test_labour_cost_vs_revenue_by_department_and_day(): void
    {
        $mon = $this->monday();
        $a = $this->emp('D1', salary: 173_333); // 40h/week -> ~1000 per hour
        $this->configureDept();
        $this->publishedRoster([['employee_id' => $a->id, 'department_id' => $this->dept->id, 'date' => $mon, 'starts_at' => '08:00', 'ends_at' => '16:00']]);
        Attendance::create(['employee_id' => $a->id, 'date' => $mon, 'clock_in' => "$mon 08:00:00", 'clock_out' => "$mon 17:00:00", 'worked_minutes' => 540]);
        $folio = DB::table('folios')->insertGetId(['group_id' => $this->group->id, 'hotel_id' => $this->hotel->id, 'number' => 'F1', 'name' => 'Test', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([[100_000, 18_000, 'charge', 'rooms'], [-10_000, -1_800, 'reversal', 'rooms'], [50_000, 0, 'charge', 'fnb']] as [$amt, $tax, $type, $dep]) {
            DB::table('folio_lines')->insert(['folio_id' => $folio, 'hotel_id' => $this->hotel->id, 'business_date' => $mon, 'type' => $type, 'department' => $dep, 'description' => 'x', 'amount' => $amt, 'tax_amount' => $tax, 'created_at' => now()]);
        }

        $out = $this->getJson("/api/v1/workforce/dashboard?from=$mon&to=$mon")->assertOk()->json();
        $row = collect($out['rows'])->firstWhere('department_id', $this->dept->id);
        $this->assertSame(100_000 - 18_000 - 10_000 + 1_800, $row['revenue'], 'rooms revenue net of tax, reversal included; fnb not mapped');
        $this->assertEqualsWithDelta(8_000, $row['planned_cost'], 10);
        $this->assertEqualsWithDelta(9_000, $row['actual_cost'], 10);
        $this->assertEqualsWithDelta(round(9_000 / 73_800 * 100, 1), $row['labour_pct'], 0.2);
        $this->assertSame($row['revenue'], $out['totals']['revenue']);
    }

    // ---- access and isolation -----------------------------------------------------------

    public function test_permissions_and_tenant_isolation(): void
    {
        $mon = $this->monday();
        $roster = Roster::create(['week_start' => $mon, 'status' => 'draft']);
        [$g2, $h2] = $this->makeHotel('Second Group', 'SEC');
        $this->provision($h2);
        $u2 = $this->makeUser($g2, 'general-manager', allHotels: true);
        $this->actAs($u2, $h2);
        $this->getJson("/api/v1/workforce/rosters/{$roster->id}")->assertNotFound();
        $this->assertCount(0, $this->getJson('/api/v1/workforce/rosters')->json());

        $recep = $this->makeUser($this->group, 'receptionist', hotels: [$this->hotel]);
        $this->actAs($recep, $this->hotel);
        $this->getJson("/api/v1/workforce/dashboard?from=$mon&to=$mon")->assertForbidden();
        $this->postJson('/api/v1/workforce/rosters/generate', ['week_start' => $mon])->assertForbidden();
        $this->postJson("/api/v1/workforce/rosters/{$roster->id}/publish")->assertForbidden();
        $this->postJson('/api/v1/workforce/kiosk/in', ['number' => 'X', 'pin' => '1234'])->assertForbidden();
        $this->postJson('/api/v1/workforce/clock/in', [])->assertStatus(422); // self-service: user not linked to an employee

        $hr = $this->makeUser($this->group, 'hr-officer', hotels: [$this->hotel]);
        $this->actAs($hr, $this->hotel);
        $this->getJson('/api/v1/workforce/settings')->assertOk();
        $this->assertSame(0, RosterShift::count());
        app(TenantContext::class)->set($this->group->id, $this->hotel->id, [$this->hotel->id], $hr);
    }
}
