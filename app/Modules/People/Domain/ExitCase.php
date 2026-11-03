<?php

namespace App\Modules\People\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class ExitCase extends Model
{
    use BelongsToHotel;

    protected $table = 'people_exits';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['gross_misconduct' => 'boolean', 'notice_worked' => 'boolean', 'is_executive' => 'boolean', 'settlement' => 'array', 'settlement_total' => 'integer', 'notice_date' => 'date', 'last_day' => 'date', 'approved_at' => 'datetime'];
    }
}
