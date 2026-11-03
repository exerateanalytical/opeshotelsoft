<?php

namespace App\Modules\HR\Workforce\Domain;

use App\Platform\Tenancy\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;

class LeavePolicy extends Model
{
    use BelongsToGroup;

    protected $table = 'workforce_leave_policies';

    protected $guarded = ['id', 'group_id'];

    protected function casts(): array
    {
        return ['seniority_rules' => 'array', 'accrual_per_month' => 'float', 'carry_over_max' => 'float', 'max_balance' => 'float'];
    }
}
