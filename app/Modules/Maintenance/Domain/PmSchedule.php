<?php

namespace App\Modules\Maintenance\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class PmSchedule extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['next_due' => 'date', 'active' => 'boolean'];
    }
}
