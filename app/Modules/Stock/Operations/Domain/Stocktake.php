<?php

namespace App\Modules\Stock\Operations\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class Stocktake extends Model
{
    use BelongsToHotel;

    protected $table = 'stocktakes';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'blind' => 'boolean', 'tolerance_pct' => 'float', 'tolerance_qty' => 'float', 'approved_at' => 'datetime'];
    }
}
