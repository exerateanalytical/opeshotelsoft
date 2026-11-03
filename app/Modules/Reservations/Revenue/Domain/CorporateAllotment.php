<?php

namespace App\Modules\Reservations\Revenue\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class CorporateAllotment extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    public function block()
    {
        return $this->belongsTo(\App\Modules\Reservations\Domain\RoomBlock::class, 'room_block_id');
    }

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date'];
    }
}
