<?php

namespace App\Modules\Insights\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class Digest extends Model
{
    use BelongsToHotel;

    protected $table = 'insight_digests';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'date' => 'date:Y-m-d', 'sent_at' => 'datetime'];
    }
}
