<?php

namespace App\Modules\FrontOffice\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class GuestRegisterEntry extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['arrival' => 'date', 'departure' => 'date', 'date_of_birth' => 'date', 'id_expiry' => 'date', 'checked_out_at' => 'datetime'];
    }
}
