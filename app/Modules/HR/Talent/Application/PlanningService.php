<?php

namespace App\Modules\HR\Talent\Application;

use App\Modules\HR\Domain\Department;
use App\Modules\HR\Domain\Employee;
use App\Modules\HR\Domain\Position;
use App\Modules\HR\Talent\Domain\HeadcountBudget;
use App\Modules\HR\Talent\Domain\JobPost;
use App\Modules\HR\Talent\Domain\KeyPosition;
use App\Modules\HR\Talent\Domain\Successor;
use App\Platform\Domain\DomainException;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Carbon;

/** Budgeted vs actual headcount per department and month, vacancies, and succession cover for key positions. */
class PlanningService
{
    public const READINESS = ['now', '1_2y', '3y_plus'];

    public function __construct(private TenantContext $ctx) {}

    public function setBudget(int $departmentId, string $month, int $budgeted): HeadcountBudget
    {
        Department::findOrFail($departmentId);

        return HeadcountBudget::updateOrCreate(['hotel_id' => $this->ctx->requireHotelId(), 'department_id' => $departmentId, 'month' => $month], ['budgeted' => $budgeted]);
    }

    /** Employees on the books at the end of a month, by department. */
    private function actualAt(Carbon $end, $employees): array
    {
        $by = [];
        foreach ($employees as $e) {
            if ($e->hire_date->lte($end) && (! $e->termination_date || $e->termination_date->gt($end))) {
                $by[$e->department_id] = ($by[$e->department_id] ?? 0) + 1;
            }
        }

        return $by;
    }

    public function headcount(string $from, string $to): array
    {
        $start = Carbon::createFromFormat('Y-m', $from)->startOfMonth();
        $end = Carbon::createFromFormat('Y-m', $to)->startOfMonth();
        if ($end->lt($start) || $start->diffInMonths($end) > 24) {
            throw new DomainException('Choose a period of at most 24 months.');
        }
        $employees = Employee::get();
        $budgets = HeadcountBudget::whereBetween('month', [$from, $to])->get()->groupBy('department_id');
        $openings = JobPost::where('status', 'open')->get()->groupBy('department_id');
        $months = [];
        for ($m = $start->copy(); $m->lte($end); $m->addMonth()) {
            $months[] = $m->format('Y-m');
        }
        $actuals = [];
        foreach ($months as $mo) {
            $actuals[$mo] = $this->actualAt(Carbon::createFromFormat('Y-m', $mo)->endOfMonth(), $employees);
        }
        $rows = Department::get()->map(function (Department $d) use ($months, $actuals, $budgets, $openings) {
            $cells = [];
            foreach ($months as $mo) {
                $actual = $actuals[$mo][$d->id] ?? 0;
                $b = ($budgets[$d->id] ?? collect())->firstWhere('month', $mo)?->budgeted;
                $cells[$mo] = ['budgeted' => $b, 'actual' => $actual, 'variance' => $b === null ? null : $actual - $b, 'vacancies' => $b === null ? 0 : max(0, $b - $actual)];
            }

            return ['department_id' => $d->id, 'department' => $d->name, 'open_posts' => (int) ($openings[$d->id] ?? collect())->sum('openings'), 'months' => $cells];
        })->values();

        return ['months' => $months, 'rows' => $rows->all()];
    }

    public function markKey(int $positionId, string $risk, ?string $notes): KeyPosition
    {
        Position::findOrFail($positionId);

        return KeyPosition::updateOrCreate(['hotel_id' => $this->ctx->requireHotelId(), 'position_id' => $positionId], ['risk' => $risk, 'notes' => $notes]);
    }

    public function addSuccessor(int $positionId, int $employeeId, string $readiness, ?string $notes): Successor
    {
        $e = Employee::findOrFail($employeeId);
        Position::findOrFail($positionId);
        if ($e->position_id === $positionId) {
            throw new DomainException('A successor cannot already hold the position.', 'invalid-successor');
        }
        if ($e->status === 'terminated') {
            throw new DomainException('This employee has left.', 'invalid-successor');
        }
        if (! KeyPosition::where('position_id', $positionId)->exists()) {
            $this->markKey($positionId, 'medium', null);
        }

        return Successor::updateOrCreate(['position_id' => $positionId, 'employee_id' => $employeeId], ['readiness' => $readiness, 'notes' => $notes]);
    }

    public function succession(): array
    {
        $positions = Position::get()->keyBy('id');
        $succ = Successor::get()->groupBy('position_id');
        $emps = Employee::get()->keyBy('id');

        return KeyPosition::get()->map(function (KeyPosition $k) use ($positions, $succ, $emps) {
            $list = ($succ[$k->position_id] ?? collect())->filter(fn ($s) => ($emps[$s->employee_id]->status ?? 'terminated') !== 'terminated');
            $ready = $list->where('readiness', 'now')->count();

            return [
                'position_id' => $k->position_id, 'position' => $positions[$k->position_id]->title ?? '#'.$k->position_id, 'risk' => $k->risk, 'notes' => $k->notes,
                'incumbents' => $emps->where('position_id', $k->position_id)->where('status', '!=', 'terminated')->map->full_name->values()->all(),
                'successors' => $list->map(fn ($s) => ['id' => $s->id, 'employee_id' => $s->employee_id, 'name' => $emps[$s->employee_id]->full_name, 'readiness' => $s->readiness, 'notes' => $s->notes])->values()->all(),
                'ready_now' => $ready, 'covered' => $ready > 0, 'at_risk' => $ready === 0 && $k->risk === 'high',
            ];
        })->values()->all();
    }
}
