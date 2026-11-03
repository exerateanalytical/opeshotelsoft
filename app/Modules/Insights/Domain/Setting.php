<?php

namespace App\Modules\Insights\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use BelongsToHotel;

    protected $table = 'insight_settings';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['rules' => 'array', 'digest_enabled' => 'boolean', 'digest_emails' => 'array', 'digest_whatsapp' => 'array'];
    }
}
