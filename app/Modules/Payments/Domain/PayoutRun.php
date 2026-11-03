<?php

namespace App\Modules\Payments\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** Salary payout of one approved payroll run. */
class PayoutRun extends Model
{
    use BelongsToHotel;

    protected $table = 'payout_runs';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    public function items()
    {
        return $this->hasMany(PayoutItem::class)->orderBy('id');
    }
}
