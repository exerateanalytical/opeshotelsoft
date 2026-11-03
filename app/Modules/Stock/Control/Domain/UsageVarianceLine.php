<?php

namespace App\Modules\Stock\Control\Domain;

use Illuminate\Database\Eloquent\Model;

class UsageVarianceLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['theoretical' => 'float', 'actual' => 'float', 'variance_qty' => 'float', 'unexplained_qty' => 'float'];
    }
}
