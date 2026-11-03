<?php

namespace App\Modules\HR\Workforce\Application;

use App\Modules\HR\Domain\Employee;
use App\Modules\HR\Domain\LeaveRequest;
use App\Modules\HR\Domain\LeaveType;
use App\Modules\HR\Workforce\Domain\LeaveLedger;
use App\Modules\HR\Workforce\Domain\LeavePolicy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Leave policies per leave type: monthly accrual, seniority days, balance cap, year-end carry-over with expiry.
 * Additive to the existing leave flow: the ledger is its own table, existing leave types and requests are untouched.
 * Balance = ledger entries inside the current window − approved leave days taken inside the same window.
 * The window opens at the latest carry-over entry (a year-end baseline) and is unbounded before the first one.
 */
class LeavePolicyService
{
    /** Cameroon Labour Code: extra leave days by seniority, applied on the work anniversary. */
    public const DEFAULT_SENIORITY = [['years' => 5, 'extra_days' => 2], ['years' => 10, 'extra_days' => 4], ['years' => 15, 'extra_days' => 6], ['years' => 20, 'extra_days' => 8]];

    public function policies()
    {
        return LeavePolicy::orderBy('leave_type_id')->get();
    }

    public function savePolicy(int $leaveTypeId, array $data): LeavePolicy
    {
        LeaveType::findOrFail($leaveTypeId);

        return LeavePolicy::updateOrCreate(['leave_type_id' => $leaveTypeId], $data);
    }

    private function windowStart(int $employeeId, int $typeId, string $asOf): ?Carbon
    {
        $d = LeaveLedger::where('employee_id', $employeeId)->where('leave_type_id', $typeId)->where('kind', 'carry_over')->where('entry_date', '<=', $asOf)->max('entry_date');

        return $d ? Carbon::parse($d) : null;
    }

    public function balance(int $employeeId, int $typeId, ?string $asOf = null): float
    {
        $asOf ??= now()->toDateString();
        $start = $this->windowStart($employeeId, $typeId, $asOf);
        $ledger = LeaveLedger::where('employee_id', $employeeId)->where('leave_type_id', $typeId)->where('entry_date', '<=', $asOf)
            ->when($start, fn ($q) => $q->where('entry_date', '>=', $start->toDateString()))->sum('days');

        return round((float) $ledger - $this->taken($employeeId, $typeId, $start?->toDateString(), $asOf), 2);
    }

    private function taken(int $employeeId, int $typeId, ?string $from, string $to): float
    {
        return (float) LeaveRequest::where('employee_id', $employeeId)->where('leave_type_id', $typeId)->where('status', 'approved')
            ->when($from, fn ($q) => $q->where('start_date', '>=', $from))->where('start_date', '<=', $to)->sum('days');
    }

    public function balances(int $employeeId, ?string $asOf = null): array
    {
        $asOf ??= now()->toDateString();

        return LeavePolicy::with([])->get()->map(function ($p) use ($employeeId, $asOf) {
            $type = LeaveType::find($p->leave_type_id);

            return ['leave_type_id' => $p->leave_type_id, 'code' => $type?->code, 'name' => $type?->name, 'balance' => $this->balance($employeeId, $p->leave_type_id, $asOf)];
        })->values()->all();
    }

    /** Idempotent: running a month twice never doubles the accrual. */
    public function accrue(string $month): array
    {
        $end = Carbon::parse($month.'-01')->endOfMonth();
        $created = 0;
        $employees = Employee::whereNull('termination_date')->orWhere('termination_date', '>=', $end->toDateString())->get()
            ->filter(fn ($e) => $e->hire_date->lte($end));
        foreach (LeavePolicy::all() as $policy) {
            foreach ($employees as $e) {
                $created += (int) $this->accrueOne($policy, $e, $month, $end);
            }
        }

        return ['month' => $month, 'entries' => $created];
    }

    private function accrueOne(LeavePolicy $policy, Employee $e, string $month, Carbon $end): bool
    {
        $made = false;
        $key = ['employee_id' => $e->id, 'leave_type_id' => $policy->leave_type_id];
        if (! LeaveLedger::where($key)->where('kind', 'accrual')->where('period', $month)->exists()) {
            $days = (float) $policy->accrual_per_month;
            if ($policy->max_balance !== null) {
                $days = min($days, (float) $policy->max_balance - $this->balance($e->id, $policy->leave_type_id, $end->toDateString()));
            }
            if ($days > 0) {
                LeaveLedger::create($key + ['kind' => 'accrual', 'period' => $month, 'entry_date' => $end->toDateString(), 'days' => $days]);
                $made = true;
            }
        }
        // seniority supplement, once a year in the anniversary month
        if ($e->hire_date->month === $end->month && $e->hire_date->year < $end->year) {
            $years = $e->hire_date->diffInYears($end);
            $rules = $policy->seniority_rules ?: [];
            $extra = collect($rules)->filter(fn ($r) => $years >= $r['years'])->max('extra_days');
            $period = (string) $end->year;
            if ($extra && ! LeaveLedger::where($key)->where('kind', 'seniority')->where('period', $period)->exists()) {
                LeaveLedger::create($key + ['kind' => 'seniority', 'period' => $period, 'entry_date' => $end->toDateString(), 'days' => (float) $extra, 'note' => "$years years of service"]);
                $made = true;
            }
        }

        return $made;
    }

    /**
     * Close the year: balance above the carry-over cap lapses on 31 Dec; the carried part opens the new year on 1 Jan.
     * Every employee gets a carry_over entry (even 0) so the new window starts clean. Idempotent.
     */
    public function yearEnd(int $year): array
    {
        $dec31 = Carbon::create($year, 12, 31);
        $jan1 = Carbon::create($year + 1, 1, 1);
        $carried = 0.0;
        $lapsed = 0.0;
        DB::transaction(function () use ($year, $dec31, $jan1, &$carried, &$lapsed) {
            $employees = Employee::whereNull('termination_date')->orWhere('termination_date', '>', $dec31->toDateString())->get()->filter(fn ($e) => $e->hire_date->lte($dec31));
            foreach (LeavePolicy::all() as $p) {
                foreach ($employees as $e) {
                    $key = ['employee_id' => $e->id, 'leave_type_id' => $p->leave_type_id];
                    if (LeaveLedger::where($key)->where('kind', 'carry_over')->where('period', (string) ($year + 1))->exists()) {
                        continue;
                    }
                    $closing = max(0.0, $this->balance($e->id, $p->leave_type_id, $dec31->toDateString()));
                    $carry = min($closing, (float) $p->carry_over_max);
                    $forfeit = round($closing - $carry, 2);
                    if ($forfeit > 0) {
                        LeaveLedger::create($key + ['kind' => 'expiry', 'period' => $year.'-end', 'entry_date' => $dec31->toDateString(), 'days' => -$forfeit, 'note' => 'Above carry-over cap']);
                    }
                    LeaveLedger::create($key + ['kind' => 'carry_over', 'period' => (string) ($year + 1), 'entry_date' => $jan1->toDateString(), 'days' => $carry,
                        'expires_on' => $p->carry_over_expiry_months && $carry > 0 ? $jan1->copy()->addMonths($p->carry_over_expiry_months)->subDay()->toDateString() : null]);
                    $carried += $carry;
                    $lapsed += $forfeit;
                }
            }
        });

        return ['year' => $year, 'carried_days' => round($carried, 2), 'lapsed_days' => round($lapsed, 2)];
    }

    /** Carried days not used by their expiry date lapse. Idempotent. */
    public function expireCarried(?string $asOf = null): int
    {
        $asOf ??= now()->toDateString();
        $n = 0;
        foreach (LeaveLedger::where('kind', 'carry_over')->where('days', '>', 0)->whereNotNull('expires_on')->where('expires_on', '<', $asOf)->get() as $c) {
            $period = $c->period.'-carry';
            $key = ['employee_id' => $c->employee_id, 'leave_type_id' => $c->leave_type_id];
            if (LeaveLedger::where($key)->where('kind', 'expiry')->where('period', $period)->exists()) {
                continue;
            }
            $usedOfCarry = $this->taken($c->employee_id, $c->leave_type_id, $c->entry_date->toDateString(), $c->expires_on->toDateString());
            $unused = round(max(0.0, (float) $c->days - $usedOfCarry), 2);
            LeaveLedger::create($key + ['kind' => 'expiry', 'period' => $period, 'entry_date' => $c->expires_on->toDateString(), 'days' => -$unused, 'note' => 'Carried days expired']);
            $n++;
        }

        return $n;
    }

    public function adjust(int $employeeId, int $typeId, float $days, string $note): LeaveLedger
    {
        return LeaveLedger::create(['employee_id' => $employeeId, 'leave_type_id' => $typeId, 'kind' => 'adjustment', 'period' => now()->format('Y-m-d H:i:s').'#'.random_int(100, 999), 'entry_date' => now()->toDateString(), 'days' => $days, 'note' => $note]);
    }
}
