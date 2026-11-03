<?php

namespace App\Modules\Housekeeping\Domain;

use App\Modules\Property\Domain\Room;
use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class HkTask extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'checklist' => 'array', 'consumables' => 'array'];
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }
}
