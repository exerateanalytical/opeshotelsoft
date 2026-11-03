<?php

namespace App\Modules\GuestPortal\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** An in-stay order placed by the guest from the signed ordering page. */
class GuestOrder extends Model
{
    use BelongsToHotel;

    protected $table = 'guest_portal_orders';

    protected $guarded = ['id', 'group_id', 'hotel_id'];
}
