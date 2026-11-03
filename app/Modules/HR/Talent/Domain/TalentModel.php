<?php

namespace App\Modules\HR\Talent\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** Base for hotel-scoped talent rows. */
abstract class TalentModel extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'group_id', 'hotel_id'];
}