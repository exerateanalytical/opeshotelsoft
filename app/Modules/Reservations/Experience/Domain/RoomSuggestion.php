<?php

namespace App\Modules\Reservations\Experience\Domain;

use App\Modules\Property\Domain\Room;
use App\Modules\Reservations\Domain\Reservation;
use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class RoomSuggestion extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['reasons' => 'array', 'decided_at' => 'datetime'];
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }
}
