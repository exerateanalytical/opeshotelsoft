<?php

namespace App\Modules\Insights\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class Alert extends Model
{
    use BelongsToHotel;

    protected $table = 'insight_alerts';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['evidence' => 'array', 'occurred_at' => 'datetime', 'acknowledged_at' => 'datetime', 'resolved_at' => 'datetime', 'business_date' => 'date:Y-m-d'];
    }
}
