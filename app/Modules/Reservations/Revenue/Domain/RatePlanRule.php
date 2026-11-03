<?php

namespace App\Modules\Reservations\Revenue\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class RatePlanRule extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['closed_arrival_days' => 'array'];
    }
}
