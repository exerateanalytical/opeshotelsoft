<?php

namespace App\Modules\Stock\Control\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class ItemUnit extends Model
{
    use BelongsToHotel;

    protected $table = 'item_units';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['factor' => 'float'];
    }

}
