<?php

namespace App\Modules\GuestPortal\Domain;

use Illuminate\Database\Eloquent\Model;

/** Pre-arrival data a guest submits from the public site (no tenant scope: always looked up by reservation). */
class OnlineCheckin extends Model
{
    protected $table = 'guest_portal_checkins';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'signed_at' => 'datetime', 'id_expiry' => 'date', 'birth_date' => 'date', 'card_accepted' => 'boolean'];
    }
}
