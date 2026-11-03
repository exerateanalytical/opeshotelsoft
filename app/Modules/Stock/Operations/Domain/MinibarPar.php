<?php

namespace App\Modules\Stock\Operations\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class MinibarPar extends Model
{
    use BelongsToHotel;

    protected $table = 'minibar_pars';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['par_qty' => 'float'];
    }
}
