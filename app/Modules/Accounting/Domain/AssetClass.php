<?php

namespace App\Modules\Accounting\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** Fixed-asset class: SYSCOHADA class-2 asset account, class-28 depreciation account, class-68 expense. */
class AssetClass extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'group_id', 'hotel_id'];
}
