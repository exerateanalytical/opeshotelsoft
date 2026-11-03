<?php

namespace App\Modules\HR\Workforce\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class Pin extends Model
{
    use BelongsToHotel;

    protected $table = 'workforce_pins';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['locked_until' => 'datetime'];
    }
}
