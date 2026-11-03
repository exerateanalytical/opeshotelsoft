<?php

namespace App\Modules\Stock\Control\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class ItemControl extends Model
{
    use BelongsToHotel;

    protected $table = 'item_controls';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['tracks_lots' => 'boolean'];
    }

}
