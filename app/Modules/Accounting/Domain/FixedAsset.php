<?php

namespace App\Modules\Accounting\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class FixedAsset extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['acquired_on' => 'date', 'last_depreciated_on' => 'date', 'disposed_on' => 'date'];
    }

    public function netBookValue(): int
    {
        return (int) $this->cost - (int) $this->accumulated;
    }
}
