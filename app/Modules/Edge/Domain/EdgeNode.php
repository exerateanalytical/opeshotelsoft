<?php

namespace App\Modules\Edge\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** A registered hotel edge server (cloud side). */
class EdgeNode extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected $hidden = ['secret'];

    protected $casts = ['secret' => 'encrypted', 'last_push_at' => 'datetime', 'last_pull_at' => 'datetime'];
}
