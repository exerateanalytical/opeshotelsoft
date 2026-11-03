<?php

namespace App\Modules\HR\Workforce\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class DeptRule extends Model
{
    use BelongsToHotel;

    protected $table = 'workforce_dept_rules';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['revenue_keys' => 'array', 'include_pos' => 'boolean'];
    }
}
