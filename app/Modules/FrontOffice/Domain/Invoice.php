<?php

namespace App\Modules\FrontOffice\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['lines' => 'array', 'taxes' => 'array', 'issue_date' => 'date', 'due_date' => 'date'];
    }
}
