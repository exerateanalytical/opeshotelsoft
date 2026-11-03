<?php

namespace App\Modules\Stock\Control\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class StockLot extends Model
{
    use BelongsToHotel;

    protected $table = 'stock_lots';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['received_date' => 'date', 'expiry_date' => 'date', 'quantity_received' => 'float', 'quantity_remaining' => 'float', 'unit_cost' => 'float'];
    }

}
