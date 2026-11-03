<?php

namespace App\Modules\POS\Domain;

use Illuminate\Database\Eloquent\Model;

class ItemComponent extends Model
{
    public $timestamps = false;

    protected $table = 'pos_item_components';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'float'];
    }
}
