<?php

namespace App\Modules\HR\Domain\Lifecycle;

use App\Modules\HR\Domain\Employee;
use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class CompensationChange extends Model
{
    use BelongsToHotel;

    protected $table = 'hr_compensation_changes';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['effective_date' => 'date', 'decided_at' => 'datetime', 'applied_at' => 'datetime'];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
