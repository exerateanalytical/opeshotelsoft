<?php

namespace App\Modules\HR\Workforce\Http;

use App\Modules\HR\Domain\Department;
use App\Modules\HR\Domain\Employee;
use App\Modules\HR\Domain\LeaveType;
use App\Modules\HR\Workforce\Application\ClockService;
use App\Modules\HR\Workforce\Application\DemandForecast;
use App\Modules\HR\Workforce\Application\LabourCostService;
use App\Modules\HR\Workforce\Application\LeavePolicyService;
use App\Modules\HR\Workforce\Application\RosterService;
use App\Modules\HR\Workforce\Application\WorkRules;
use App\Modules\HR\Workforce\Domain\Anomaly;
use App\Modules\HR\Workforce\Domain\DeptRule;
use App\Modules\HR\Workforce\Domain\Roster;
use App\Modules\HR\Workforce\Domain\RosterShift;
use App\Modules\HR\Workforce\Domain\ShiftRequest;
use App\Modules\HR\Workforce\Domain\ShiftTemplate;
use App\Platform\Domain\DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * H-A workforce API. Permissions: workforce.view (read), workforce.manage (rules, rosters, policies, anomalies),
 * workforce.publish (publish rosters, decide swaps and claims), workforce.kiosk (PIN terminal).
 * Self-service endpoints (my shifts, swaps, claims, clock, my leave balance) need a user linked to an employee.
 */
class WorkforceController
{
    public function __construct(private RosterService $rosters, private DemandForecast $forecast, private LeavePolicyService $leave, private ClockService $clock, private LabourCostService $cost) {}

    private function need(Request $r, string ...$perms): void
    {
        foreach ($perms as $p) {
            if ($r->user()->can($p)) {
                return;
            }
        }
        abort(403);
    }

    private function me(Request $r): Employee
    {
        return Employee::where('user_id', $r->user()->id)->first()
            ?? throw new DomainException('Your user account is not linked to an employee record.', 'no-employee-record');
    }

    // ---- settings and department rules --------------------------------------------

    public function settings(Request $r)
    {
        $this->need($r, 'workforce.view', 'workforce.manage');

        return $this->rosters->settings();
    }

    public function saveSettings(Request $r)
    {
        $this->need($r, 'workforce.manage');
        $d = $r->validate([
            'rest_hours_min' => ['sometimes', 'numeric', 'between:0,24'], 'max_weekly_hours' => ['sometimes', 'integer', 'between:1,84'], 'max_daily_hours' => ['sometimes', 'integer', 'between:1,16'],
            'max_days_per_week' => ['sometimes', 'integer', 'between:1,7'], 'max_consecutive_nights' => ['sometimes', 'integer', 'between:1,14'],
            'night_start' => ['sometimes', 'date_format:H:i'], 'night_end' => ['sometimes', 'date_format:H:i'],
            'geo_lat' => ['nullable', 'numeric', 'between:-90,90'], 'geo_lng' => ['nullable', 'numeric', 'between:-180,180'], 'geo_radius_m' => ['sometimes', 'integer', 'between:20,5000'],
            'geo_required' => ['sometimes', 'boolean'], 'photo_required' => ['sometimes', 'boolean'], 'pin_enabled' => ['sometimes', 'boolean'],
            'late_tolerance_min' => ['sometimes', 'integer', 'between:0,120'], 'max_speed_kmh' => ['sometimes', 'integer', 'between:20,900'], 'missing_out_after_hours' => ['sometimes', 'integer', 'between:6,48'],
        ]);
        $s = $this->rosters->settings();
        $s->update($d);

        return $s->fresh();
    }

    public function deptRules(Request $r)
    {
        $this->need($r, 'workforce.view', 'workforce.manage');
        $rules = DeptRule::get()->keyBy('department_id');
        $tpls = ShiftTemplate::orderBy('starts_at')->get()->groupBy('department_id');

        return Department::where('active', true)->orderBy('name')->get(['id', 'code', 'name'])->map(fn ($d) => [
            'department_id' => $d->id, 'code' => $d->code, 'name' => $d->name,
            'rule' => $rules[$d->id] ?? null, 'templates' => ($tpls[$d->id] ?? collect())->values(),
        ]);
    }

    public function saveDeptRule(Request $r)
    {
        $this->need($r, 'workforce.manage');
        $d = $r->validate([
            'department_id' => ['required', 'integer'], 'driver' => ['required', Rule::in(['arrivals', 'departures', 'inhouse', 'none'])],
            'units_per_staff' => ['required', 'integer', 'between:1,500'], 'min_staff' => ['required', 'integer', 'between:0,100'],
            'revenue_keys' => ['sometimes', 'array'], 'revenue_keys.*' => ['string', 'max:40'], 'include_pos' => ['sometimes', 'boolean'],
            'templates' => ['sometimes', 'array'], 'templates.*.name' => ['required', 'string', 'max:60'], 'templates.*.starts_at' => ['required', 'date_format:H:i'],
            'templates.*.ends_at' => ['required', 'date_format:H:i'], 'templates.*.share' => ['sometimes', 'integer', 'between:0,200'], 'templates.*.min_staff' => ['sometimes', 'integer', 'between:0,50'],
        ]);
        $dept = Department::findOrFail($d['department_id']);

        return DB::transaction(function () use ($d, $dept) {
            $rule = DeptRule::updateOrCreate(['department_id' => $dept->id], collect($d)->only(['driver', 'units_per_staff', 'min_staff', 'revenue_keys', 'include_pos'])->all());
            if (isset($d['templates'])) {
                ShiftTemplate::where('department_id', $dept->id)->delete();
                foreach ($d['templates'] as $t) {
                    ShiftTemplate::create(['department_id' => $dept->id, 'name' => $t['name'], 'starts_at' => $t['starts_at'], 'ends_at' => $t['ends_at'], 'share' => $t['share'] ?? 100, 'min_staff' => $t['min_staff'] ?? 0]);
                }
            }

            return ['rule' => $rule, 'templates' => ShiftTemplate::where('department_id', $dept->id)->orderBy('starts_at')->get()];
        });
    }

    // ---- forecast and rosters ---------------------------------------------------------

    public function forecast(Request $r)
    {
        $this->need($r, 'workforce.view', 'workforce.manage');
        $d = $r->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);
        abort_if(\Illuminate\Support\Carbon::parse($d['from'])->diffInDays($d['to']) > 62, 422, 'Range too long.');

        return $this->forecast->forecast($d['from'], $d['to']);
    }

    public function rosters(Request $r)
    {
        $this->need($r, 'workforce.view', 'workforce.manage');

        return Roster::withCount('shifts')->orderByDesc('week_start')->orderByDesc('id')->limit(60)->get();
    }

    public function generate(Request $r)
    {
        $this->need($r, 'workforce.manage');
        $d = $r->validate(['week_start' => ['required', 'date'], 'department_id' => ['nullable', 'integer']]);

        return response()->json($this->rosters->generate($d['week_start'], $d['department_id'] ?? null), 201);
    }

    public function showRoster(Request $r, Roster $roster)
    {
        $this->need($r, 'workforce.view', 'workforce.manage');

        return $roster->load('shifts.employee:id,number,first_name,last_name,department_id')->setAttribute('violations', $this->rosters->violations($roster));
    }

    public function saveShifts(Request $r, Roster $roster)
    {
        $this->need($r, 'workforce.manage');
        $d = $r->validate([
            'shifts' => ['present', 'array', 'max:1500'], 'shifts.*.employee_id' => ['nullable', 'integer'], 'shifts.*.department_id' => ['nullable', 'integer'],
            'shifts.*.date' => ['required', 'date_format:Y-m-d'], 'shifts.*.starts_at' => ['required', 'date_format:H:i'], 'shifts.*.ends_at' => ['required', 'date_format:H:i'],
            'shifts.*.role' => ['nullable', 'string', 'max:60'], 'shifts.*.template_id' => ['nullable', 'integer'],
        ]);
        $ids = collect($d['shifts'])->pluck('employee_id')->filter()->unique();
        abort_unless(Employee::whereIn('id', $ids)->count() === $ids->count(), 422, 'Unknown employee.');

        return $this->rosters->replaceShifts($roster, $d['shifts'])->setAttribute('violations', $this->rosters->violations($roster));
    }

    public function publish(Request $r, Roster $roster)
    {
        $this->need($r, 'workforce.publish');
        $d = $r->validate(['acknowledge' => ['sometimes', 'boolean']]);

        return $this->rosters->publish($roster, (bool) ($d['acknowledge'] ?? false));
    }

    public function myShifts(Request $r)
    {
        $me = $this->me($r);
        $d = $r->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);

        return RosterShift::where('employee_id', $me->id)->whereHas('roster', fn ($q) => $q->where('status', 'published'))
            ->whereBetween('date', [$d['from'] ?? now()->toDateString(), $d['to'] ?? now()->addDays(30)->toDateString()])->orderBy('date')->orderBy('starts_at')->get();
    }

    /** Colleagues of the same department with their shifts, for choosing a swap partner. */
    public function colleagueShifts(Request $r)
    {
        $me = $this->me($r);
        $ids = Employee::where('department_id', $me->department_id)->where('id', '!=', $me->id)->pluck('id');

        return RosterShift::with('employee:id,first_name,last_name')->whereIn('employee_id', $ids)->where('date', '>=', now()->toDateString())
            ->whereHas('roster', fn ($q) => $q->where('status', 'published'))->orderBy('date')->limit(300)->get();
    }

    // ---- open shifts and swaps ----------------------------------------------------------

    public function openShifts(Request $r)
    {
        $me = Employee::where('user_id', $r->user()->id)->first();
        abort_unless($me || $r->user()->can('workforce.view'), 403);

        return $this->rosters->openShifts($me?->id);
    }

    public function claim(Request $r, RosterShift $shift)
    {
        return response()->json($this->rosters->claim($this->me($r), $shift->id, $r->input('note')), 201);
    }

    public function requestSwap(Request $r)
    {
        $d = $r->validate(['roster_shift_id' => ['required', 'integer'], 'counterpart_id' => ['required', 'integer'], 'target_shift_id' => ['nullable', 'integer'], 'note' => ['nullable', 'string', 'max:200']]);

        return response()->json($this->rosters->requestSwap($this->me($r), $d['roster_shift_id'], $d['counterpart_id'], $d['target_shift_id'] ?? null, $d['note'] ?? null), 201);
    }

    public function requests(Request $r)
    {
        $me = Employee::where('user_id', $r->user()->id)->first();
        $manager = $r->user()->can('workforce.publish') || $r->user()->can('workforce.view');
        abort_unless($me || $manager, 403);
        $q = ShiftRequest::with('shift:id,date,starts_at,ends_at,role,employee_id')->orderByDesc('id');
        if ($r->query('scope') === 'mine' || ! $manager) {
            abort_unless($me, 403);
            $q->where(fn ($w) => $w->where('requester_id', $me->id)->orWhere('counterpart_id', $me->id));
        } elseif ($r->query('status')) {
            $q->where('status', $r->query('status'));
        }
        $rows = $q->limit(200)->get();
        $names = Employee::whereIn('id', $rows->pluck('requester_id')->merge($rows->pluck('counterpart_id'))->filter())->get()->keyBy('id');

        return $rows->map(fn ($x) => $x->toArray() + ['requester' => $names[$x->requester_id]->full_name ?? null, 'counterpart' => $names[$x->counterpart_id]->full_name ?? null]);
    }

    public function respondSwap(Request $r, ShiftRequest $req)
    {
        $d = $r->validate(['accept' => ['required', 'boolean']]);

        return $this->rosters->respond($this->me($r), $req, $d['accept']);
    }

    public function cancelRequest(Request $r, ShiftRequest $req)
    {
        return $this->rosters->cancel($this->me($r), $req);
    }

    /** GAPI: manager cancel of any open request (requester-only cancel stays on cancelRequest). */
    public function managerCancelRequest(Request $r, ShiftRequest $req)
    {
        $this->need($r, 'workforce.manage');
        $d = $r->validate(['reason' => ['required', 'string', 'min:3', 'max:200']]);

        return $this->rosters->managerCancel($req, $d['reason']);
    }

    public function decideRequest(Request $r, ShiftRequest $req)
    {
        $this->need($r, 'workforce.publish');
        $d = $r->validate(['approve' => ['required', 'boolean'], 'acknowledge' => ['sometimes', 'boolean']]);

        return $this->rosters->decide($req, $d['approve'], (bool) ($d['acknowledge'] ?? false));
    }

    // ---- leave policies -------------------------------------------------------------

    public function leavePolicies(Request $r)
    {
        $this->need($r, 'workforce.view', 'workforce.manage');
        $policies = $this->leave->policies()->keyBy('leave_type_id');

        return LeaveType::orderBy('name')->get()->map(fn ($t) => ['leave_type_id' => $t->id, 'code' => $t->code, 'name' => $t->name, 'policy' => $policies[$t->id] ?? null]);
    }

    public function saveLeavePolicy(Request $r)
    {
        $this->need($r, 'workforce.manage');
        $d = $r->validate([
            'leave_type_id' => ['required', 'integer'], 'accrual_per_month' => ['required', 'numeric', 'between:0,10'], 'max_balance' => ['nullable', 'numeric', 'min:0'],
            'carry_over_max' => ['required', 'numeric', 'min:0'], 'carry_over_expiry_months' => ['nullable', 'integer', 'between:1,12'],
            'seniority_rules' => ['sometimes', 'array'], 'seniority_rules.*.years' => ['required', 'integer', 'min:1'], 'seniority_rules.*.extra_days' => ['required', 'numeric', 'min:0'],
        ]);
        $type = $d['leave_type_id'];
        unset($d['leave_type_id']);

        return $this->leave->savePolicy($type, $d);
    }

    public function accrue(Request $r)
    {
        $this->need($r, 'workforce.manage');
        $d = $r->validate(['month' => ['required', 'date_format:Y-m']]);

        return $this->leave->accrue($d['month']);
    }

    public function yearEnd(Request $r)
    {
        $this->need($r, 'workforce.manage');
        $d = $r->validate(['year' => ['required', 'integer', 'between:2020,2100']]);

        return $this->leave->yearEnd($d['year']);
    }

    public function expireLeave(Request $r)
    {
        $this->need($r, 'workforce.manage');

        return ['expired' => $this->leave->expireCarried($r->input('as_of'))];
    }

    public function adjustLeave(Request $r)
    {
        $this->need($r, 'workforce.manage');
        $d = $r->validate(['employee_id' => ['required', 'integer'], 'leave_type_id' => ['required', 'integer'], 'days' => ['required', 'numeric', 'between:-60,60', 'not_in:0'], 'note' => ['required', 'string', 'max:200']]);
        Employee::findOrFail($d['employee_id']);
        LeaveType::findOrFail($d['leave_type_id']);

        return response()->json($this->leave->adjust($d['employee_id'], $d['leave_type_id'], (float) $d['days'], $d['note']), 201);
    }

    public function leaveBalances(Request $r)
    {
        $d = $r->validate(['employee_id' => ['nullable', 'integer'], 'as_of' => ['nullable', 'date']]);
        if (! empty($d['employee_id'])) {
            $this->need($r, 'workforce.view', 'workforce.manage');
            $emp = Employee::findOrFail($d['employee_id']);
        } else {
            $emp = $this->me($r);
        }

        return ['employee_id' => $emp->id, 'balances' => $this->leave->balances($emp->id, $d['as_of'] ?? null)];
    }

    // ---- clocking and anomalies -------------------------------------------------------

    private function clockInput(Request $r): array
    {
        return $r->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90'], 'lng' => ['nullable', 'numeric', 'between:-180,180'], 'accuracy' => ['nullable', 'integer', 'min:0'],
            'photo' => ['nullable', 'string', 'max:3000000'], 'device_id' => ['nullable', 'string', 'max:64'],
        ]) + ['ip' => $r->ip()];
    }

    private function clockResult(array $res): array
    {
        return ['attendance' => $res['attendance'], 'inside_geofence' => $res['event']->inside_geofence, 'distance_m' => $res['event']->distance_m,
            'anomalies' => collect($res['anomalies'])->map(fn ($a) => ['kind' => $a->kind, 'severity' => $a->severity, 'detail' => $a->detail])->all()];
    }

    public function clockSelf(Request $r, string $direction)
    {
        return $this->clockResult($this->clock->clock($this->me($r), $direction, $r->has('lat') ? 'gps' : 'web', $this->clockInput($r)));
    }

    public function kioskClock(Request $r, string $direction)
    {
        $this->need($r, 'workforce.kiosk');
        $d = $r->validate(['number' => ['required', 'string', 'max:20'], 'pin' => ['required', 'string', 'min:4', 'max:8']]);
        $emp = Employee::where('number', $d['number'])->where('status', 'active')->first() ?? throw new DomainException('Unknown employee number.', 'unknown-employee', 404);
        $this->clock->verifyPin($emp, $d['pin']);

        return $this->clockResult($this->clock->clock($emp, $direction, 'pin', $this->clockInput($r))) + ['employee' => $emp->full_name];
    }

    /** GAPI: what the shared kiosk tablet needs: who can punch by PIN (name and number only), today's last punches, photo rule. */
    public function kioskBoard(Request $r)
    {
        $this->need($r, 'workforce.kiosk');
        $s = $this->rosters->settings();
        $ids = \App\Modules\HR\Workforce\Domain\Pin::pluck('employee_id');
        $staff = Employee::whereIn('id', $ids)->where('status', 'active')->orderBy('first_name')->orderBy('last_name')->get(['id', 'number', 'first_name', 'last_name'])
            ->map(fn ($e) => ['number' => $e->number, 'name' => trim($e->first_name.' '.mb_substr((string) $e->last_name, 0, 1).'.')])->values();
        $events = \App\Modules\HR\Workforce\Domain\ClockEvent::where('method', 'pin')->where('occurred_at', '>=', now()->startOfDay())->orderByDesc('id')->limit(8)->get(['employee_id', 'direction', 'occurred_at']);
        $names = Employee::whereIn('id', $events->pluck('employee_id'))->get(['id', 'first_name', 'last_name'])->keyBy('id');
        $recent = $events->map(fn ($e) => ['name' => isset($names[$e->employee_id]) ? trim($names[$e->employee_id]->first_name.' '.mb_substr((string) $names[$e->employee_id]->last_name, 0, 1).'.') : '-', 'direction' => $e->direction, 'at' => $e->occurred_at->toIso8601String()])->values();

        return ['pin_enabled' => (bool) $s->pin_enabled, 'photo_required' => (bool) $s->photo_required, 'staff' => $staff, 'recent' => $recent];
    }

    public function setPin(Request $r, Employee $employee)
    {
        $this->need($r, 'workforce.manage');
        $d = $r->validate(['pin' => ['required', 'string', 'regex:/^\d{4,8}$/']]);
        $this->clock->setPin($employee, $d['pin']);

        return response()->json(['ok' => true]);
    }

    public function anomalies(Request $r)
    {
        $this->need($r, 'workforce.view', 'workforce.manage');
        $rows = Anomaly::when($r->query('status', 'open'), fn ($q, $v) => $q->where('status', $v))->when($r->query('kind'), fn ($q, $v) => $q->where('kind', $v))
            ->orderByDesc('date')->orderByDesc('id')->limit(200)->get();
        $names = Employee::whereIn('id', $rows->pluck('employee_id'))->get()->keyBy('id');

        return $rows->map(fn ($a) => $a->toArray() + ['employee' => $names[$a->employee_id]->full_name ?? null, 'has_photo' => (bool) $a->clock_event_id]);
    }

    public function reviewAnomaly(Request $r, Anomaly $anomaly)
    {
        $this->need($r, 'workforce.manage');
        $d = $r->validate(['status' => ['required', Rule::in(['reviewed', 'dismissed'])]]);
        $anomaly->update($d + ['reviewed_by' => $r->user()->id, 'reviewed_at' => now()]);

        return $anomaly;
    }

    public function scan(Request $r)
    {
        $this->need($r, 'workforce.manage');

        return ['missing_clock_out' => $this->clock->scanMissingClockOut()];
    }

    public function photo(Request $r, Anomaly $anomaly)
    {
        $this->need($r, 'workforce.manage');
        $path = $anomaly->clock_event_id ? DB::table('workforce_clock_events')->where('id', $anomaly->clock_event_id)->where('hotel_id', $anomaly->hotel_id)->value('photo_path') : null;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path);
    }

    // ---- dashboard ----------------------------------------------------------------------

    public function dashboard(Request $r)
    {
        $this->need($r, 'workforce.view', 'workforce.manage');
        $d = $r->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);
        abort_if(\Illuminate\Support\Carbon::parse($d['from'])->diffInDays($d['to']) > 92, 422, 'Range too long.');

        return $this->cost->dashboard($d['from'], $d['to']);
    }

    /** Pure rule check for ad-hoc what-if (used by the roster editor before saving). */
    public function check(Request $r)
    {
        $this->need($r, 'workforce.view', 'workforce.manage');
        $d = $r->validate(['shifts' => ['required', 'array'], 'shifts.*.date' => ['required', 'date_format:Y-m-d'], 'shifts.*.starts_at' => ['required', 'date_format:H:i'], 'shifts.*.ends_at' => ['required', 'date_format:H:i']]);

        return WorkRules::violations($d['shifts'], $this->rosters->ruleConfig());
    }
}
