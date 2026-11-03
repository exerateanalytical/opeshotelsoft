<?php

namespace App\Modules\Reservations\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class RoomBlock extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['arrival' => 'date', 'departure' => 'date', 'cutoff_date' => 'date'];
    }

    public function allocations()
    {
        return $this->hasMany(RoomBlockAllocation::class);
    }
}
