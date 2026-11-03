<?php

namespace App\Modules\Payments\Domain;

use Illuminate\Database\Eloquent\Model;

/** Tenancy flows through the payout run (always load items via a scoped PayoutRun). */
class PayoutItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['paid_at' => 'datetime'];
    }

    public function run()
    {
        return $this->belongsTo(PayoutRun::class, 'payout_run_id');
    }
}
