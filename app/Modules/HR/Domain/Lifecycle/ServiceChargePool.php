<?php

namespace App\Modules\HR\Domain\Lifecycle;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class ServiceChargePool extends Model
{
    use BelongsToHotel;

    protected $table = 'hr_service_charge_pools';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['shares' => 'array'];
    }
}
