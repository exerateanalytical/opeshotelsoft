<?php

namespace App\Modules\Reservations\Experience\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** A service a guest can add to an upcoming stay from the manage-booking page (airport pickup, breakfast, spa...). */
class ExperienceService extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
