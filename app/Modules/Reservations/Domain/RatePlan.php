<?php

namespace App\Modules\Reservations\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class RatePlan extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return [
            'refundable' => 'boolean', 'deposit_required' => 'boolean', 'members_only' => 'boolean',
            'public' => 'boolean', 'active' => 'boolean',
            'penalty_percent' => 'float', 'deposit_percent' => 'float', 'derived_adjust_percent' => 'float',
        ];
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_rate_plan_id');
    }
}
