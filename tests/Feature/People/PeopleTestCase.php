<?php

namespace Tests\Feature\People;

use App\Modules\HR\Domain\Department;
use App\Modules\HR\Domain\Employee;
use App\Models\User;
use Tests\TestCase;

abstract class PeopleTestCase extends TestCase
{
    private int $seq = 0;

    protected function department(string $code = 'HSK', string $name = 'Housekeeping'): Department
    {
        return Department::create(['code' => $code, 'name' => $name]);
    }

    protected function employee(array $extra = [], ?User $user = null): Employee
    {
        $this->seq++;

        return Employee::create($extra + [
            'number' => 'E'.str_pad((string) $this->seq, 4, '0', STR_PAD_LEFT).uniqid(), 'first_name' => 'Emp'.$this->seq, 'last_name' => 'Test',
            'hire_date' => now()->subYears(3)->toDateString(), 'base_salary' => 260_000, 'email' => "emp{$this->seq}@test.cm",
            'user_id' => $user?->id,
        ]);
    }
}
