<?php

namespace App\Modules\HR\Talent\Domain;

class EmployeeSkill extends TalentModel
{
    protected $table = 'tal_employee_skills';

    protected function casts(): array
    {
        return ['assessed_on' => 'date'];
    }
}
