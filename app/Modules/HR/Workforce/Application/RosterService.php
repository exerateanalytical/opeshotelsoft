<?php

namespace App\Modules\HR\Workforce\Application;

use App\Modules\HR\Domain\Contract;
use App\Modules\HR\Domain\Employee;
use App\Modules\HR\Domain\LeaveRequest;
use App\Modules\HR\Domain\Shift;
use App\Modules\HR\Workforce\Domain\DeptRule;
use App\Modules\HR\Workforce\Domain\Roster;
use App\Modules\HR\Workforce\Domain\RosterShift;
use App\Modules\HR\Workforce\Domain\ShiftRequest;
use App\Modules\HR\Workforce\Domain\ShiftTemplate;
use App\Modules\HR\Workforce\Domain\WorkforceSettings;
use App\Platform\Domain\DomainException;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Weekly roster: generate a draft from the demand forecast under labour-law rules, the manager reviews and
 * edits it, then publishes. Publishing writes the HR `shifts` rows that overtime and attendance already read.
 * After publication, changes only happen through approved swaps and open-shift claims.
 */
class RosterService
{
    public function __construct(private DemandForecast $forecast, private TenantContext $ctx, private EventBus $bus) {}

    public function settings(): WorkforceSettings
    {
        $s = WorkforceSettings::firstOrCreate([], []);

        return $s->wasRecentlyCreated ? WorkforceSettings::firstOrFail() : $s;
    }

    public function ruleConfig(): array
    {
        $s = $this->settings();

        return [
            'rest_hours_min' => (float) $s->rest_hours_min, 'max_weekly_hours' => $s->max_weekly_hours, 'max_daily_hours' => $s->max_daily_hours,
            'max_days_per_week' => $s->max_days_per_week, 'max_consecutive_nights' => $s->max_consecutive_nights,
            'night_start' => $s->night_start, 'night_end' => $s->night_end,
        ];
    }

    public function generate(string $weekStart, ?int $departmentId = null): Roster
    {
        $monday = Carbon::parse($weekStart)->startOfWeek();
        $from = $monday->toDateString();
        $to = $monday->copy()->addDays(6)->toDateString();
        if ($monday->toDateString() !== Carbon::parse($weekStart)->toDateString()) {
            throw new DomainException('The week must start on a Monday.', 'invalid-week');
        }
        if (Roster::where('week_start', $from)->where('department_id', $departmentId)->where('status', 'published')->exists()) {
            throw new DomainException('This week is already published.', 'already-published');
        }
        $cfg = $this->ruleConfig();
        $forecast = $this->forecast->forecast($from, $to);
        $templates = ShiftTemplate::where('active', true)->orderBy('starts_at')->get()->groupBy('department_id');
        $rules = DeptRule::when($departmentId, fn ($q) => $q->where('department_id', $departmentId))->get();

        $slots = [];
        foreach ($forecast as $day) {
            foreach ($day['departments'] as $d) {
                if ($departmentId && $d['department_id'] !== $departmentId) {
                    continue;
                }
                $tpls = $templates->get($d['department_id'], collect());
                foreach ($tpls as $tpl) {
                    $head = max((int) $tpl->min_staff, (int) ceil($d['required'] * $tpl->share / 100));
                    if ($head > 0) {
                        $slots[] = ['date' => $day['date'], 'department_id' => $d['department_id'], 'template_id' => $tpl->id, 'starts_at' => $tpl->starts_at, 'ends_at' => $tpl->ends_at, 'headcount' => $head, 'role' => $tpl->name];
                    }
                }
            }
        }

        $employees = Employee::where('status', 'active')->whereIn('department_id', $rules->pluck('department_id'))->get(['id', 'department_id'])
            ->map(fn ($e) => ['id' => $e->id, 'department_id' => $e->department_id, 'hours_per_week' => $this->hoursPerWeek($e->id)])->all();
        $ids = array_column($employees, 'id');
        $absent = $this->absences($ids, $from, $to);
        $existing = Shift::whereIn('employee_id', $ids)->where(fn ($q) => $q->whereBetween('date', [Carbon::parse($from)->subDays(8)->toDateString(), Carbon::parse($from)->subDay()->toDateString()])
            ->orWhereBetween('date', [Carbon::parse($to)->addDay()->toDateString(), Carbon::parse($to)->addDays(8)->toDateString()]))->get()
            ->groupBy('employee_id')->map(fn ($g) => $g->map(fn ($s) => ['date' => $s->date->toDateString(), 'starts_at' => substr($s->starts_at, 0, 5), 'ends_at' => substr($s->ends_at, 0, 5)])->all())->all();

        $plan = WorkRules::plan($slots, $employees, $absent, $existing, $cfg);

        return DB::transaction(function () use ($from, $departmentId, $forecast, $plan) {
            $roster = Roster::where('week_start', $from)->where('department_id', $departmentId)->where('status', 'draft')->first();
            if ($roster) {
                $roster->shifts()->delete();
            }
            $roster ??= new Roster(['week_start' => $from, 'department_id' => $departmentId]);
            $roster->fill(['status' => 'draft', 'forecast' => $forecast, 'warnings' => $plan['warnings'], 'generated_by' => $this->ctx->actor()?->id])->save();
            foreach ($plan['shifts'] as $s) {
                $roster->shifts()->create($s);
            }
            $roster->update(['warnings' => [...$plan['warnings'], ...$this->violations($roster)]]);

            return $roster->load('shifts.employee:id,number,first_name,last_name');
        });
    }

    /** Draft edit: replace the shifts of a draft roster. Each item: {employee_id|null, department_id, date, starts_at, ends_at, role?}. */
    public function replaceShifts(Roster $roster, array $shifts): Roster
    {
        if ($roster->status !== 'draft') {
            throw new DomainException('A published roster cannot be edited; use swaps or open-shift claims.', 'invalid-state');
        }
        $from = $roster->week_start->toDateString();
        $to = $roster->week_start->copy()->addDays(6)->toDateString();
        foreach ($shifts as $s) {
            if ($s['date'] < $from || $s['date'] > $to) {
                throw new DomainException('A shift falls outside the roster week.', 'out-of-week');
            }
        }
        DB::transaction(function () use ($roster, $shifts) {
            $roster->shifts()->delete();
            foreach ($shifts as $s) {
                $roster->shifts()->create(['employee_id' => $s['employee_id'] ?? null, 'department_id' => $s['department_id'] ?? null, 'date' => $s['date'], 'starts_at' => $s['starts_at'], 'ends_at' => $s['ends_at'], 'role' => $s['role'] ?? null, 'template_id' => $s['template_id'] ?? null]);
            }
        });
        $roster->load('shifts');
        $roster->update(['warnings' => $this->violations($roster)]);

        return $roster->load('shifts.employee:id,number,first_name,last_name');
    }

    /** Labour-law and leave violations of a roster (hard problems the manager must resolve or acknowledge). */
    public function violations(Roster $roster): array
    {
        $cfg = $this->ruleConfig();
        $shifts = $roster->shifts()->get();
        $from = $roster->week_start->toDateString();
        $to = $roster->week_start->copy()->addDays(6)->toDateString();
        $out = [];
        $ids = $shifts->pluck('employee_id')->filter()->unique()->values();
        $neighbour = Shift::whereIn('employee_id', $ids)->where(fn ($q) => $q->whereBetween('date', [Carbon::parse($from)->subDays(8)->toDateString(), Carbon::parse($from)->subDay()->toDateString()])
            ->orWhereBetween('date', [Carbon::parse($to)->addDay()->toDateString(), Carbon::parse($to)->addDays(8)->toDateString()]))->get()->groupBy('employee_id');
        $absent = $this->absences($ids->all(), $from, $to);
        foreach ($shifts->groupBy('employee_id') as $empId => $group) {
            if (! $empId) {
                continue;
            }
            $list = $group->map(fn ($s) => ['date' => $s->date->toDateString(), 'starts_at' => $s->starts_at, 'ends_at' => $s->ends_at])->all();
            foreach (($neighbour[$empId] ?? collect()) as $n) {
                $list[] = ['date' => $n->date->toDateString(), 'starts_at' => substr($n->starts_at, 0, 5), 'ends_at' => substr($n->ends_at, 0, 5)];
            }
            foreach (WorkRules::violations($list, $cfg) as $v) {
                $out[] = $v + ['employee_id' => (int) $empId];
            }
            foreach ($group as $s) {
                if (in_array($s->date->toDateString(), $absent[$empId] ?? [], true)) {
                    $out[] = ['rule' => 'on_leave', 'date' => $s->date->toDateString(), 'message' => 'Employee is on approved leave.', 'employee_id' => (int) $empId];
                }
            }
        }
        $open = $shifts->whereNull('employee_id')->count();
        if ($open > 0) {
            $out[] = ['rule' => 'open_shifts', 'date' => $from, 'message' => "$open open shift(s) left.", 'severity' => 'info'];
        }

        return $out;
    }

    private const BLOCKING = ['overlap', 'rest', 'daily_hours', 'weekly_hours', 'weekly_rest', 'night', 'on_leave'];

    public function publish(Roster $roster, bool $acknowledge = false): Roster
    {
        if ($roster->status !== 'draft') {
            throw new DomainException('Already published.', 'invalid-state');
        }
        $blocking = collect($this->violations($roster))->filter(fn ($v) => in_array($v['rule'], self::BLOCKING, true))->values();
        if ($blocking->isNotEmpty() && ! $acknowledge) {
            throw new DomainException('The roster breaks labour rules ('.$blocking->pluck('rule')->unique()->implode(', ').'). Fix them or publish with acknowledgement.', 'labour-rules');
        }
        DB::transaction(function () use ($roster, $blocking) {
            foreach ($roster->shifts as $s) {
                $this->syncShift($s, null);
            }
            $roster->update(['status' => 'published', 'published_by' => $this->ctx->actor()?->id, 'published_at' => now(), 'warnings' => $blocking->all()]);
        });
        $this->bus->record('hr.roster_published', ['id' => $roster->id, 'week_start' => $roster->week_start->toDateString(), 'shifts' => $roster->shifts->count()], 'roster', $roster->id);

        return $roster->load('shifts.employee:id,number,first_name,last_name');
    }

    /** Mirror a roster shift into the HR shifts table (what overtime and attendance read). */
    private function syncShift(RosterShift $rs, ?int $previousEmployeeId): void
    {
        if ($previousEmployeeId) {
            Shift::where('employee_id', $previousEmployeeId)->whereDate('date', $rs->date)->where('starts_at', $rs->starts_at)->delete();
        }
        if ($rs->employee_id) {
            Shift::updateOrCreate(['employee_id' => $rs->employee_id, 'date' => $rs->date->toDateString(), 'starts_at' => $rs->starts_at], ['ends_at' => $rs->ends_at, 'role' => $rs->role]);
        }
    }

    // ---- swaps and the open-shift marketplace ---------------------------------

    public function openShifts(?int $employeeId = null)
    {
        $q = RosterShift::whereNull('employee_id')->whereHas('roster', fn ($r) => $r->where('status', 'published'))->where('date', '>=', now()->toDateString());
        if ($employeeId) {
            $dept = Employee::find($employeeId)?->department_id;
            $q->where(fn ($w) => $w->whereNull('department_id')->orWhere('department_id', $dept));
        }

        return $q->orderBy('date')->orderBy('starts_at')->get();
    }

    public function claim(Employee $me, int $rosterShiftId, ?string $note = null): ShiftRequest
    {
        $rs = RosterShift::findOrFail($rosterShiftId);
        if ($rs->employee_id || $rs->roster()->value('status') !== 'published') {
            throw new DomainException('This shift is not open.', 'invalid-state');
        }
        if (ShiftRequest::where('roster_shift_id', $rs->id)->where('requester_id', $me->id)->whereIn('status', ['pending', 'accepted'])->exists()) {
            throw new DomainException('You already requested this shift.', 'duplicate');
        }

        return ShiftRequest::create(['kind' => 'claim', 'roster_shift_id' => $rs->id, 'requester_id' => $me->id, 'note' => $note]);
    }

    public function requestSwap(Employee $me, int $rosterShiftId, int $counterpartId, ?int $targetShiftId, ?string $note = null): ShiftRequest
    {
        $rs = RosterShift::findOrFail($rosterShiftId);
        if ($rs->employee_id !== $me->id) {
            throw new DomainException('You can only swap your own shift.', 'forbidden', 403);
        }
        if ($rs->date->isPast() && ! $rs->date->isToday()) {
            throw new DomainException('This shift is in the past.', 'invalid-state');
        }
        if ($counterpartId === $me->id) {
            throw new DomainException('Pick a colleague.', 'invalid-state');
        }
        if ($targetShiftId) {
            $t = RosterShift::findOrFail($targetShiftId);
            if ($t->employee_id !== $counterpartId) {
                throw new DomainException('The exchanged shift does not belong to that colleague.', 'invalid-state');
            }
        }
        Employee::findOrFail($counterpartId);

        return ShiftRequest::create(['kind' => 'swap', 'roster_shift_id' => $rs->id, 'requester_id' => $me->id, 'counterpart_id' => $counterpartId, 'target_shift_id' => $targetShiftId, 'note' => $note]);
    }

    /** The colleague accepts or declines a swap proposal. */
    public function respond(Employee $me, ShiftRequest $req, bool $accept): ShiftRequest
    {
        if ($req->kind !== 'swap' || $req->status !== 'pending' || $req->counterpart_id !== $me->id) {
            throw new DomainException('Nothing to answer.', 'invalid-state');
        }
        $req->update(['status' => $accept ? 'accepted' : 'rejected']);

        return $req;
    }

    public function cancel(Employee $me, ShiftRequest $req): ShiftRequest
    {
        if ($req->requester_id !== $me->id || ! in_array($req->status, ['pending', 'accepted'], true)) {
            throw new DomainException('Cannot cancel.', 'invalid-state');
        }
        $req->update(['status' => 'cancelled']);

        return $req;
    }

    /** GAPI: a manager (workforce.manage) withdraws an open request on the requester's behalf; the reason is kept in the event log. */
    public function managerCancel(ShiftRequest $req, string $reason): ShiftRequest
    {
        if (! in_array($req->status, ['pending', 'accepted'], true)) {
            throw new DomainException('This request is already closed.', 'invalid-state');
        }
        return DB::transaction(function () use ($req, $reason) {
            $prev = $req->status;
            $req->update(['status' => 'cancelled', 'decided_by' => $this->ctx->actor()?->id, 'decided_at' => now()]);
            $this->bus->record('hr.shift_request_cancelled', ['id' => $req->id, 'kind' => $req->kind, 'by' => 'manager', 'previous_status' => $prev, 'reason' => $reason], 'shift_request', $req->id);

            return $req;
        });
    }

    /** Manager decision. Claims need no colleague step; swaps need the colleague to have accepted. */
    public function decide(ShiftRequest $req, bool $approve, bool $acknowledge = false): ShiftRequest
    {
        $ready = $req->kind === 'claim' ? $req->status === 'pending' : $req->status === 'accepted';
        if (! $ready) {
            throw new DomainException($req->kind === 'swap' && $req->status === 'pending' ? 'The colleague has not accepted yet.' : 'This request is already decided.', 'invalid-state');
        }
        $actor = $this->ctx->actor();
        if ($approve && $actor && Employee::where('user_id', $actor->id)->whereIn('id', array_filter([$req->requester_id, $req->counterpart_id]))->exists()) {
            throw new DomainException('You cannot approve a request you are part of.', 'sod');
        }

        return DB::transaction(function () use ($req, $approve, $acknowledge, $actor) {
            if ($approve) {
                $this->apply($req, $acknowledge);
            }
            $req->update(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $actor?->id, 'decided_at' => now()]);
            if ($approve && $req->kind === 'claim') {
                ShiftRequest::where('roster_shift_id', $req->roster_shift_id)->where('id', '!=', $req->id)->whereIn('status', ['pending', 'accepted'])
                    ->update(['status' => 'rejected', 'decided_by' => $actor?->id, 'decided_at' => now(), 'note' => 'Shift given to another employee']);
            }
            $this->bus->record('hr.shift_request_'.($approve ? 'approved' : 'rejected'), ['id' => $req->id, 'kind' => $req->kind], 'shift_request', $req->id);

            return $req;
        });
    }

    private function apply(ShiftRequest $req, bool $acknowledge): void
    {
        $cfg = $this->ruleConfig();
        $moves = [];
        $a = RosterShift::findOrFail($req->roster_shift_id);
        if ($req->kind === 'claim') {
            if ($a->employee_id) {
                throw new DomainException('The shift is no longer open.', 'invalid-state');
            }
            $moves[] = [$a, $req->requester_id];
        } else {
            $moves[] = [$a, $req->counterpart_id];
            if ($req->target_shift_id) {
                $moves[] = [RosterShift::findOrFail($req->target_shift_id), $req->requester_id];
            }
        }
        foreach ($moves as [$rs, $newEmp]) {
            $others = RosterShift::where('employee_id', $newEmp)->whereBetween('date', [$rs->date->copy()->subDays(8)->toDateString(), $rs->date->copy()->addDays(8)->toDateString()])
                ->whereNotIn('id', array_map(fn ($m) => $m[0]->id, $moves))->get()
                ->map(fn ($s) => ['date' => $s->date->toDateString(), 'starts_at' => $s->starts_at, 'ends_at' => $s->ends_at])->all();
            $swappedIn = collect($moves)->filter(fn ($m) => $m[1] === $newEmp)->map(fn ($m) => ['date' => $m[0]->date->toDateString(), 'starts_at' => $m[0]->starts_at, 'ends_at' => $m[0]->ends_at])->all();
            $viol = WorkRules::violations([...$others, ...$swappedIn], $cfg);
            $leave = $this->absences([$newEmp], $rs->date->toDateString(), $rs->date->toDateString());
            if ((! empty($leave[$newEmp])) && ! $acknowledge) {
                throw new DomainException('The employee is on approved leave that day.', 'on-leave');
            }
            if ($viol && ! $acknowledge) {
                throw new DomainException('This change breaks labour rules ('.collect($viol)->pluck('rule')->unique()->implode(', ').').', 'labour-rules');
            }
        }
        foreach ($moves as [$rs, $newEmp]) {
            $old = $rs->employee_id;
            $rs->update(['employee_id' => $newEmp]);
            $this->syncShift($rs, $old);
        }
    }

    // ---- helpers ------------------------------------------------------------------

    public function hoursPerWeek(int $employeeId): int
    {
        return (int) (Contract::where('employee_id', $employeeId)->where('status', 'active')->orderByDesc('start_date')->value('hours_per_week') ?: 40);
    }

    /** @return array<int, array<int, string>> employee_id => approved leave dates inside the range */
    public function absences(array $employeeIds, string $from, string $to): array
    {
        if (! $employeeIds) {
            return [];
        }
        $out = [];
        $leaves = LeaveRequest::whereIn('employee_id', $employeeIds)->where('status', 'approved')->where('start_date', '<=', $to)->where('end_date', '>=', $from)->get();
        foreach ($leaves as $l) {
            for ($d = $l->start_date->copy(); $d->lte($l->end_date); $d->addDay()) {
                $out[$l->employee_id][] = $d->toDateString();
            }
        }

        return $out;
    }
}
