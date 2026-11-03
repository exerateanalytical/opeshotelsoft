<?php

namespace App\Modules\Loyalty\Domain;

use App\Platform\Tenancy\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;

class Promotion extends Model
{
    use BelongsToGroup;

    protected $table = 'loyalty_promotions';

    protected $guarded = ['id', 'group_id'];

    protected function casts(): array
    {
        return ['multiplier' => 'float', 'bonus_points' => 'integer', 'starts_on' => 'date', 'ends_on' => 'date', 'outlet_ids' => 'array', 'active' => 'boolean'];
    }

    /** Active on the date, and (for a multiplier) applicable to the outlet; null outlet = stay earn. */
    public function appliesTo(string $date, ?int $outletId): bool
    {
        if (! $this->activeOn($date)) {
            return false;
        }

        return empty($this->outlet_ids) || ($outletId !== null && in_array($outletId, array_map('intval', $this->outlet_ids), true));
    }

    public function activeOn(string $date): bool
    {
        return $this->active && ! ($this->starts_on && $this->starts_on->toDateString() > $date) && ! ($this->ends_on && $this->ends_on->toDateString() < $date);
    }
}
