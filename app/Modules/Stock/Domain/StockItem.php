<?php

namespace App\Modules\Stock\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class StockItem extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'avg_cost' => 'float', 'reorder_level' => 'float', 'reorder_quantity' => 'float', 'purchase_factor' => 'float', 'vat_rate' => 'float'];
    }
}
