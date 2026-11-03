<?php

namespace App\Modules\Reservations\Domain;

use App\Modules\Guests\Domain\Guest;
use App\Modules\Property\Domain\RoomType;
use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class WaitlistEntry extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['arrival' => 'date', 'departure' => 'date', 'offered_at' => 'datetime'];
    }

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }

    public function ratePlan()
    {
        return $this->belongsTo(RatePlan::class);
    }
}
