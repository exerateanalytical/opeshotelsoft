<?php

namespace App\Modules\POS\Domain;

use Illuminate\Database\Eloquent\Model;

class CheckLine extends Model
{
    protected $table = 'pos_check_lines';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['modifiers' => 'array', 'voided' => 'boolean', 'fired_at' => 'datetime', 'quantity' => 'float', 'comped_at' => 'datetime'];
    }
}
