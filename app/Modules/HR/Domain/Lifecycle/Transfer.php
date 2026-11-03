<?php

namespace App\Modules\HR\Domain\Lifecycle;

use App\Platform\Tenancy\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;

/** Move between hotels of the same group (group-scoped: both hotels see it). */
class Transfer extends Model
{
    use BelongsToGroup;

    protected $table = 'hr_transfers';

    protected $guarded = ['id', 'group_id'];

    protected function casts(): array
    {
        return ['effective_date' => 'date', 'seniority_date' => 'date', 'decided_at' => 'datetime', 'completed_at' => 'datetime', 'leave_balance' => 'float'];
    }
}
