<?php

namespace App\Modules\HR\Domain;

use App\Platform\Tenancy\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;

class LeaveType extends Model
{
    use BelongsToGroup;

    protected $table = 'leave_types';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['paid' => 'boolean', 'accrues' => 'boolean'];
    }
}
