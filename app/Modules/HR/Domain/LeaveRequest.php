<?php

namespace App\Modules\HR\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class LeaveRequest extends Model
{
    use BelongsToHotel;

    protected $table = 'leave_requests';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'days' => 'float'];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
