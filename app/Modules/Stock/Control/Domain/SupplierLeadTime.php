<?php

namespace App\Modules\Stock\Control\Domain;

use App\Platform\Tenancy\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;

class SupplierLeadTime extends Model
{
    use BelongsToGroup;

    protected $table = 'supplier_lead_times';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return [];
    }

}
