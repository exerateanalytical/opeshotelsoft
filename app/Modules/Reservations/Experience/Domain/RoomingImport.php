<?php

namespace App\Modules\Reservations\Experience\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class RoomingImport extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['errors' => 'array'];
    }
}
