<?php

namespace App\Modules\Stock\Operations\Domain;

use Illuminate\Database\Eloquent\Model;

class StocktakeLine extends Model
{
    protected $table = 'stocktake_lines';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['expected' => 'float', 'count_1' => 'float', 'count_2' => 'float', 'recount_required' => 'boolean'];
    }
}
