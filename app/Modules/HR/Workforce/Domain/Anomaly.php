<?php

namespace App\Modules\HR\Workforce\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class Anomaly extends Model
{
    use BelongsToHotel;

    protected $table = 'workforce_anomalies';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'detail' => 'array', 'reviewed_at' => 'datetime'];
    }
}
