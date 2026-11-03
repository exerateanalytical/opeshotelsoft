<?php

namespace App\Modules\HR\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class OvertimeEntry extends Model
{
    use BelongsToHotel;

    protected $table = 'overtime_entries';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'decided_at' => 'datetime'];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
