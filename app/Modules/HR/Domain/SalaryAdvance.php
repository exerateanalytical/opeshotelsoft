<?php

namespace App\Modules\HR\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class SalaryAdvance extends Model
{
    use BelongsToHotel;

    protected $table = 'salary_advances';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['granted_on' => 'date'];
    }
}
