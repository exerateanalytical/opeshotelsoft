<?php

namespace App\Modules\Channels\Domain;

use Illuminate\Database\Eloquent\Model;

class ChannelReservation extends Model
{
    protected $table = 'channel_reservations';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
