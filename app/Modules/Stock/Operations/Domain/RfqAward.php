<?php

namespace App\Modules\Stock\Operations\Domain;

use Illuminate\Database\Eloquent\Model;

class RfqAward extends Model
{
    protected $table = 'rfq_awards';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'float'];
    }
}
