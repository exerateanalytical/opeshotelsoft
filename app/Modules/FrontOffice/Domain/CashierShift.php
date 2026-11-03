<?php

namespace App\Modules\FrontOffice\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class CashierShift extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['expected' => 'array', 'counted' => 'array', 'opened_at' => 'datetime', 'closed_at' => 'datetime', 'business_date' => 'date'];
    }
}
