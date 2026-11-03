<?php

namespace App\Modules\HR\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    use BelongsToHotel;

    protected $table = 'employees';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['date_of_birth' => 'date', 'hire_date' => 'date', 'termination_date' => 'date', 'allowances' => 'array', 'leave_days_per_month' => 'float'];
    }

    protected $appends = ['full_name'];

    public function getFullNameAttribute(): string
    {
        return $this->first_name.' '.$this->last_name;
    }

    public function contracts()
    {
        return $this->hasMany(Contract::class)->orderByDesc('start_date');
    }

    public function manager()
    {
        return $this->belongsTo(Employee::class, 'reports_to');
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }
}
