<?php

namespace App\Modules\ServiceRequests\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use BelongsToHotel;

    protected $table = 'service_request_settings';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['sla' => 'array', 'voice_messages' => 'boolean'];
    }
}
