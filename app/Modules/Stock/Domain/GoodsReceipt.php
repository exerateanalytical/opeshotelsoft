<?php

namespace App\Modules\Stock\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class GoodsReceipt extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'invoiced' => 'boolean'];
    }

    public function lines()
    {
        return $this->hasMany(GoodsReceiptLine::class);
    }
}
