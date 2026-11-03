<?php

namespace App\Modules\Reservations\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** A date range on a rate plan with either a fixed nightly rate or a percentage modifier. */
class RatePlanSeason extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'modifier_percent' => 'float', 'rate' => 'integer'];
    }

    /** Apply this season to a base nightly amount. */
    public function apply(int $base): int
    {
        if ($this->rate !== null) {
            return (int) $this->rate;
        }

        return (int) round($base * (1 + ($this->modifier_percent ?? 0) / 100));
    }
}
