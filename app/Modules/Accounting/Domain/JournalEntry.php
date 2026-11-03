<?php

namespace App\Modules\Accounting\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** Posted entries are immutable; only the `reversed` flag may change. */
class JournalEntry extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'reversed' => 'boolean'];
    }

    public function lines()
    {
        return $this->hasMany(JournalLine::class);
    }
}
