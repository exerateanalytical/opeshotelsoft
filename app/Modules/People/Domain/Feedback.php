<?php

namespace App\Modules\People\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class Feedback extends Model
{
    use BelongsToHotel;

    protected $table = 'people_feedback';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['rating' => 'integer', 'received_on' => 'date'];
    }
}
