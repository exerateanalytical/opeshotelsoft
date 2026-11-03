<?php

namespace App\Modules\HR\Workforce\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class ClockEvent extends Model
{
    use BelongsToHotel;

    protected $table = 'workforce_clock_events';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'inside_geofence' => 'boolean', 'lat' => 'float', 'lng' => 'float'];
    }
}
