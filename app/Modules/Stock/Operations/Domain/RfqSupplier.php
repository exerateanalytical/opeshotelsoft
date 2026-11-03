<?php

namespace App\Modules\Stock\Operations\Domain;

use Illuminate\Database\Eloquent\Model;

class RfqSupplier extends Model
{
    protected $table = 'rfq_suppliers';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }
}
