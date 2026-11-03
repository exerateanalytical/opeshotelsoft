<?php

namespace App\Modules\HR\Workforce\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class WorkforceSettings extends Model
{
    use BelongsToHotel;

    protected $table = 'workforce_settings';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['geo_required' => 'boolean', 'photo_required' => 'boolean', 'pin_enabled' => 'boolean', 'rest_hours_min' => 'float', 'geo_lat' => 'float', 'geo_lng' => 'float'];
    }
}
