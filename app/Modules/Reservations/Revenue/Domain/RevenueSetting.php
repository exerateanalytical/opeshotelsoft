<?php

namespace App\Modules\Reservations\Revenue\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class RevenueSetting extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['weekend_days' => 'array', 'upsell_discount_pct' => 'array', 'high_occupancy_uplift' => 'float', 'low_occupancy_discount' => 'float', 'pace_uplift' => 'float', 'weekend_uplift' => 'float', 'competitor_undercut_pct' => 'float', 'max_change_pct' => 'float'];
    }
}
