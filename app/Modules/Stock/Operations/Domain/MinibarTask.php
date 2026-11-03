<?php

namespace App\Modules\Stock\Operations\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class MinibarTask extends Model
{
    use BelongsToHotel;

    protected $table = 'minibar_tasks';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'lines' => 'array', 'done_at' => 'datetime'];
    }
}
