<?php

namespace App\Modules\Property\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class RoomType extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['amenities' => 'array', 'active' => 'boolean'];
    }

    public function rooms()
    {
        return $this->hasMany(Room::class);
    }
}
