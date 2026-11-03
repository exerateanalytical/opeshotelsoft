<?php

namespace App\Modules\HR\Domain\Lifecycle;

use App\Modules\HR\Domain\Employee;
use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** Bonus or allowance feeding payroll as an earning (one-off for a period, or recurring). */
class PayElement extends Model
{
    use BelongsToHotel;

    protected $table = 'hr_pay_elements';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['taxable' => 'boolean', 'cnps' => 'boolean'];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function scopeForPeriod($q, string $period)
    {
        return $q->where('status', 'active')->where(fn ($w) => $w
            ->where(fn ($o) => $o->where('kind', 'one_off')->where('period', $period))
            ->orWhere(fn ($r) => $r->where('kind', 'recurring')->where('start_period', '<=', $period)
                ->where(fn ($e) => $e->whereNull('end_period')->orWhere('end_period', '>=', $period))));
    }
}
