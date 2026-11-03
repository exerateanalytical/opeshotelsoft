<?php

namespace App\Modules\HR\Domain;

use Illuminate\Database\Eloquent\Model;

class Contract extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'probation_end' => 'date'];
    }
}
