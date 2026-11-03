<?php

namespace App\Modules\Reservations\Revenue\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class PricingEvent extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'impact_pct' => 'float'];
    }
}
