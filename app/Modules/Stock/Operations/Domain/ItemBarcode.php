<?php

namespace App\Modules\Stock\Operations\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class ItemBarcode extends Model
{
    use BelongsToHotel;

    protected $table = 'item_barcodes';

    protected $guarded = ['id', 'group_id', 'hotel_id'];
}
