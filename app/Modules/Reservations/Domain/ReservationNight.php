<?php

namespace App\Modules\Reservations\Domain;

use Illuminate\Database\Eloquent\Model;

class ReservationNight extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'posted' => 'boolean'];
    }
}
