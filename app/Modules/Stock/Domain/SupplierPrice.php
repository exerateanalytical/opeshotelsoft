<?php

namespace App\Modules\Stock\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class SupplierPrice extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['valid_from' => 'date', 'valid_to' => 'date', 'min_quantity' => 'float', 'unit_price' => 'integer'];
    }
}
