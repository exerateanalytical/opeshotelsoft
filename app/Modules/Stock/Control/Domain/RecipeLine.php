<?php

namespace App\Modules\Stock\Control\Domain;

use Illuminate\Database\Eloquent\Model;

class RecipeLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'float', 'yield_pct' => 'float'];
    }
}
