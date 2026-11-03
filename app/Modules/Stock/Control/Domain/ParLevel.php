<?php

namespace App\Modules\Stock\Control\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class ParLevel extends Model
{
    use BelongsToHotel;

    protected $table = 'par_levels';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['par_level' => 'float', 'min_level' => 'float'];
    }

}
