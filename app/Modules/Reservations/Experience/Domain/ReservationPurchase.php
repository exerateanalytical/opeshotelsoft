<?php

namespace App\Modules\Reservations\Experience\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** Something the guest bought for a stay: a service, early check-in or late check-out. Posted to the folio at check-in. */
class ReservationPurchase extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'group_id', 'hotel_id'];
}
