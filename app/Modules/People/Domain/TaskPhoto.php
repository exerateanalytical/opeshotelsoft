<?php

namespace App\Modules\People\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class TaskPhoto extends Model
{
    use BelongsToHotel;

    protected $table = 'people_task_photos';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }
}
