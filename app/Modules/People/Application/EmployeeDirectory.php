<?php

namespace App\Modules\People\Application;

use App\Modules\HR\Application\HrService;
use App\Modules\HR\Domain\Employee;
use App\Platform\Domain\DomainException;

/**
 * Read-only view of HR employees for the People module. People stores only
 * employee ids; every lookup goes through here (tenant scope applies).
 */
class EmployeeDirectory
{
    public function __construct(private HrService $hr) {}

    public function get(int $id): Employee
    {
        $e = Employee::with(['department:id,name', 'manager:id,first_name,last_name,user_id'])->find($id);
        if (! $e) {
            throw new DomainException('Unknown employee.', 'people-unknown-employee');
        }

        return $e;
    }

    public function forUser(?int $userId): ?Employee
    {
        return $userId ? Employee::where('user_id', $userId)->first() : null;
    }

    /** @return int[] */
    public function idsInDepartment(int $departmentId): array
    {
        return Employee::where('department_id', $departmentId)->where('status', '!=', 'terminated')->pluck('id')->all();
    }

    /** @return array<int, array{id:int, name:string, department_id:?int, department:?string}> */
    public function summaries(array $ids): array
    {
        return Employee::with('department:id,name')->whereIn('id', array_unique(array_filter($ids)))->get()
            ->mapWithKeys(fn (Employee $e) => [$e->id => ['id' => $e->id, 'name' => $e->full_name, 'number' => $e->number,
                'department_id' => $e->department_id, 'department' => $e->department?->name]])->all();
    }

    public function leaveBalance(Employee $e): float
    {
        return $this->hr->leaveBalance($e);
    }

    public function positionTitle(Employee $e): ?string
    {
        return $e->position_id ? \App\Modules\HR\Domain\Position::find($e->position_id)?->title : null;
    }
}
