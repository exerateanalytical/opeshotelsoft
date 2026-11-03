<?php

namespace App\Modules\Stock\Domain;

use Illuminate\Database\Eloquent\Model;

class GoodsReceiptLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'float', 'returned_quantity' => 'float', 'received_quantity' => 'float', 'vat_rate' => 'float', 'expected' => 'float', 'counted' => 'float'];
    }
}
