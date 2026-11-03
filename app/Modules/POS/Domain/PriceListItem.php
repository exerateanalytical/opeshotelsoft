<?php

namespace App\Modules\POS\Domain;

use Illuminate\Database\Eloquent\Model;

class PriceListItem extends Model
{
    protected $table = 'pos_price_list_items';

    protected $guarded = ['id'];

    public $timestamps = false;
}
