<?php

namespace App\Modules\Loyalty\Domain;

use Illuminate\Database\Eloquent\Model;

class Tier extends Model
{
    protected $table = 'loyalty_tiers';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['benefits' => 'array', 'earn_multiplier' => 'float', 'active' => 'boolean'];
    }
}
