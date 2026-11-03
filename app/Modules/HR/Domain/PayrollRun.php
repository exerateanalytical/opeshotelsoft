<?php

namespace App\Modules\HR\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class PayrollRun extends Model
{
    use BelongsToHotel;

    protected $table = 'payroll_runs';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime', 'paid_at' => 'datetime'];
    }

    public function payslips()
    {
        return $this->hasMany(Payslip::class);
    }
}
