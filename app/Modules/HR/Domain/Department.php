<?php

namespace App\Modules\HR\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    use BelongsToHotel;

    protected $table = 'departments';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
