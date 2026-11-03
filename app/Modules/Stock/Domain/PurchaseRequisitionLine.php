<?php

namespace App\Modules\Stock\Domain;

use Illuminate\Database\Eloquent\Model;

class PurchaseRequisitionLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'float'];
    }
}
