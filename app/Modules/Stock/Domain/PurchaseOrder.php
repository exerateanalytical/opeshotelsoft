<?php

namespace App\Modules\Stock\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrder extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['order_date' => 'date', 'expected_date' => 'date', 'approved_at' => 'datetime'];
    }

    public function lines()
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }
}
