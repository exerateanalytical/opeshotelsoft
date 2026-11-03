<?php

namespace App\Modules\HR\Workforce\Application;

use App\Modules\HR\Domain\Attendance;
use App\Modules\HR\Domain\Contract;
use App\Modules\HR\Domain\Department;
use App\Modules\HR\Domain\Employee;
use App\Modules\HR\Workforce\Domain\DeptRule;
use App\Modules\HR\Workforce\Domain\RosterShift;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Labour cost against revenue, by department and day.
 * Cost: hourly rate (contract base salary / paid hours in a month) x loaded factor (employer charges from the latest payroll).
 * Planned cost comes from published rosters, actual cost from closed attendances.
 * Revenue: folio charge lines (net of tax, reversals included) of the department's revenue keys, plus POS checks settled outside the folio.
 */
class LabourCostService
{
    public function __construct(private TenantContext $ctx) {}

    public function loadedFactor(): float
    {
        $hotelId = $this->ctx->requireHotelId();
        $run = DB::table('payroll_runs')->where('hotel_id', $hotelId)->orderByDesc('period')->first(['id']);
        if (! $run) {
            return 1.0;
        }
        $t = DB::table('payslips')->where('payroll_run_id', $run->id)->selectRaw('sum(gross) as g, sum(employer_social) as s, sum(other_employer) as o')->first();

        return $t && $t->g > 0 ? round(($t->g + $t->s + $t->o) / $t->g, 4) : 1.0;
    }

    /** @return array<int, float> employee_id => hourly cost (loaded) */
    private function rates(array $employeeIds, float $factor): array
    {
        $out = [];
        foreach (Contract::whereIn('employee_id', $employeeIds)->where('status', 'active')->orderBy('start_date')->get() as $c) {
            $monthlyHours = max(1, ($c->hours_per_week ?: 40) * 52 / 12);
            $out[$c->employee_id] = $c->base_salary / $monthlyHours * $factor; // later contracts overwrite earlier
        }

        return $out;
    }

    public function dashboard(string $from, string $to): array
    {
        $hotelId = $this->ctx->requireHotelId();
        $factor = $this->loadedFactor();
        $employees = Employee::get(['id', 'department_id'])->keyBy('id');
        $rates = $this->rates($employees->keys()->all(), $factor);
        $rows = []; // "date|dept" => [...]
        $add = function (string $date, ?int $dept, array $values) use (&$rows) {
            $k = $date.'|'.($dept ?? 0);
            $rows[$k] ??= ['date' => $date, 'department_id' => $dept, 'planned_cost' => 0, 'actual_cost' => 0, 'revenue' => 0, 'planned_hours' => 0.0, 'actual_hours' => 0.0];
            foreach ($values as $f => $v) {
                $rows[$k][$f] += $v;
            }
        };

        $planned = RosterShift::whereBetween('date', [$from, $to])->whereNotNull('employee_id')->whereHas('roster', fn ($r) => $r->where('status', 'published'))->get();
        foreach ($planned as $s) {
            $h = WorkRules::minutes(['date' => $s->date->toDateString(), 'starts_at' => $s->starts_at, 'ends_at' => $s->ends_at]) / 60;
            $dept = $employees[$s->employee_id]->department_id ?? $s->department_id;
            $add($s->date->toDateString(), $dept, ['planned_hours' => $h, 'planned_cost' => $h * ($rates[$s->employee_id] ?? 0)]);
        }
        foreach (Attendance::whereBetween('date', [$from, $to])->whereNotNull('clock_out')->get() as $a) {
            $h = $a->worked_minutes / 60;
            $dept = $employees[$a->employee_id]->department_id ?? null;
            $add($a->date->toDateString(), $dept, ['actual_hours' => $h, 'actual_cost' => $h * ($rates[$a->employee_id] ?? 0)]);
        }

        $rules = DeptRule::all();
        $keyToDept = [];
        foreach ($rules as $rule) {
            foreach ($rule->revenue_keys ?? [] as $k) {
                $keyToDept[strtolower($k)] = $rule->department_id;
            }
        }
        if ($keyToDept) {
            $folio = DB::table('folio_lines')->where('hotel_id', $hotelId)->whereIn('type', ['charge', 'reversal'])->whereBetween('business_date', [$from, $to])
                ->whereIn(DB::raw('lower(department)'), array_keys($keyToDept))
                ->groupBy('business_date', DB::raw('lower(department)'))
                ->selectRaw('business_date as d, lower(department) as dep, sum(amount - tax_amount) as total')->get();
            foreach ($folio as $f) {
                $add($f->d, $keyToDept[$f->dep], ['revenue' => (int) $f->total]);
            }
        }
        $posDepts = $rules->where('include_pos', true)->pluck('department_id');
        if ($posDepts->isNotEmpty()) {
            $pos = DB::table('pos_checks')->where('hotel_id', $hotelId)->where('status', 'settled')->where('settlement', 'payment')->whereBetween('business_date', [$from, $to])
                ->groupBy('business_date')->selectRaw('business_date as d, sum(total) as total')->get();
            foreach ($pos as $p) {
                $add($p->d, $posDepts->first(), ['revenue' => (int) $p->total]); // POS cash sales go to the first department flagged for POS
            }
        }

        $names = Department::pluck('name', 'id');
        $rows = collect($rows)->map(function ($r) use ($names) {
            $r['department'] = $names[$r['department_id']] ?? null;
            foreach (['planned_cost', 'actual_cost'] as $k) {
                $r[$k] = (int) round($r[$k]);
            }
            $r['planned_hours'] = round($r['planned_hours'], 1);
            $r['actual_hours'] = round($r['actual_hours'], 1);
            $basis = $r['actual_cost'] ?: $r['planned_cost'];
            $r['labour_pct'] = $r['revenue'] > 0 ? round($basis / $r['revenue'] * 100, 1) : null;

            return $r;
        })->sortBy([['date', 'asc'], ['department_id', 'asc']])->values();

        $byDept = $rows->groupBy('department_id')->map(function ($g, $dept) use ($names) {
            $rev = $g->sum('revenue');
            $basis = $g->sum('actual_cost') ?: $g->sum('planned_cost');

            return ['department_id' => $dept ?: null, 'department' => $names[$dept] ?? null, 'planned_cost' => $g->sum('planned_cost'), 'actual_cost' => $g->sum('actual_cost'),
                'revenue' => $rev, 'labour_pct' => $rev > 0 ? round($basis / $rev * 100, 1) : null];
        })->values();
        $rev = $rows->sum('revenue');
        $basis = $rows->sum('actual_cost') ?: $rows->sum('planned_cost');

        return ['from' => $from, 'to' => $to, 'loaded_factor' => $factor, 'rows' => $rows, 'departments' => $byDept,
            'totals' => ['planned_cost' => $rows->sum('planned_cost'), 'actual_cost' => $rows->sum('actual_cost'), 'revenue' => $rev, 'labour_pct' => $rev > 0 ? round($basis / $rev * 100, 1) : null]];
    }
}
