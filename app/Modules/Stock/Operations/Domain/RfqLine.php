<?php

namespace App\Modules\Stock\Operations\Domain;

use Illuminate\Database\Eloquent\Model;

class RfqLine extends Model
{
    protected $table = 'rfq_lines';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'float'];
    }
}
