<?php

namespace App\Modules\HR\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class EmployeeDocument extends Model
{
    use BelongsToHotel;

    protected $table = 'employee_documents';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['expires_on' => 'date'];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
