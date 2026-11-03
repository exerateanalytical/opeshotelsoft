<?php

namespace App\Modules\Stock\Domain;

use Illuminate\Database\Eloquent\Model;

class PurchaseOrderLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'float', 'received_quantity' => 'float', 'vat_rate' => 'float', 'expected' => 'float', 'counted' => 'float'];
    }
}
