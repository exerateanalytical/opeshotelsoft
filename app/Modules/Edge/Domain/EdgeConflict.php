<?php

namespace App\Modules\Edge\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** Audit of every sync decision that did not simply apply the incoming row. Append-only. */
class EdgeConflict extends Model
{
    use BelongsToHotel;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['incoming' => 'array', 'created_at' => 'datetime'];
}
