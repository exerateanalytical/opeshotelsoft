<?php

namespace App\Modules\Housekeeping\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class LostFoundItem extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['found_on' => 'date', 'returned_at' => 'datetime', 'disposed_at' => 'datetime'];
    }
}
