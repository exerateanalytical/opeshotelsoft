<?php

namespace App\Modules\Loyalty\Domain;

use Illuminate\Database\Eloquent\Model;

class Card extends Model
{
    protected $table = 'loyalty_cards';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['issued_at' => 'datetime'];
    }
}
