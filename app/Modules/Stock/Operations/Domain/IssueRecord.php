<?php

namespace App\Modules\Stock\Operations\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class IssueRecord extends Model
{
    use BelongsToHotel;

    protected $table = 'issue_records';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['issued_on' => 'date', 'due_back_on' => 'date', 'returned_on' => 'date', 'quantity' => 'float', 'returned_quantity' => 'float'];
    }
}
