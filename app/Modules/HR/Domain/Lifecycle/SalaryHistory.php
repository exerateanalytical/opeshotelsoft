<?php

namespace App\Modules\HR\Domain\Lifecycle;

use App\Platform\Tenancy\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;

/** Group-scoped so an employee's history follows them across hotels of the group. */
class SalaryHistory extends Model
{
    use BelongsToGroup;

    protected $table = 'hr_salary_history';

    protected $guarded = ['id', 'group_id'];

    protected function casts(): array
    {
        return ['effective_date' => 'date'];
    }
}
