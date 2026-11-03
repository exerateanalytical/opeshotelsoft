<?php

namespace App\Modules\Accounting\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class FxRevaluation extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['details' => 'array', 'rates' => 'array', 'date' => 'date'];
    }
}
