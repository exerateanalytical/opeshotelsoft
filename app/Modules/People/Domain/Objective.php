<?php

namespace App\Modules\People\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class Objective extends Model
{
    use BelongsToHotel;

    protected $table = 'people_objectives';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['weight' => 'integer', 'self_score' => 'float', 'manager_score' => 'float'];
    }
}
