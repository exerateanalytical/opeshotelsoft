<?php

namespace App\Modules\HR\Domain;

use Illuminate\Database\Eloquent\Model;

/** Country public holiday; platform-wide reference data. */
class PublicHoliday extends Model
{
    protected $table = 'public_holidays';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['date' => 'date', 'movable' => 'boolean'];
    }
}
