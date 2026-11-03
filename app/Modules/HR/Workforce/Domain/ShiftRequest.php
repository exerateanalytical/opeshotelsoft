<?php

namespace App\Modules\HR\Workforce\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class ShiftRequest extends Model
{
    use BelongsToHotel;

    protected $table = 'workforce_requests';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function shift()
    {
        return $this->belongsTo(RosterShift::class, 'roster_shift_id');
    }
}
