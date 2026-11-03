<?php

namespace App\Modules\HR\Workforce\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class LeaveLedger extends Model
{
    use BelongsToHotel;

    protected $table = 'workforce_leave_ledger';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['entry_date' => 'date', 'expires_on' => 'date', 'days' => 'float'];
    }
}
