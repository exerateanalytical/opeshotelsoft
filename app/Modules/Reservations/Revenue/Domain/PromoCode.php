<?php

namespace App\Modules\Reservations\Revenue\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class PromoCode extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['book_from' => 'date', 'book_to' => 'date', 'stay_from' => 'date', 'stay_to' => 'date', 'room_type_ids' => 'array', 'rate_plan_ids' => 'array', 'active' => 'boolean', 'value' => 'float'];
    }
}
