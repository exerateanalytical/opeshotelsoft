<?php

namespace App\Modules\Stock\Operations\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class MinibarConsumption extends Model
{
    use BelongsToHotel;

    protected $table = 'minibar_consumptions';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['quantity' => 'float'];
    }
}
