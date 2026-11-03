<?php

namespace App\Modules\Stock\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class PurchaseRequisition extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['needed_by' => 'date', 'approved_at' => 'datetime'];
    }

    public function lines()
    {
        return $this->hasMany(PurchaseRequisitionLine::class);
    }
}
