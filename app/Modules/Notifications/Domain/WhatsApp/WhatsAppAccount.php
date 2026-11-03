<?php

namespace App\Modules\Notifications\Domain\WhatsApp;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class WhatsAppAccount extends Model
{
    use BelongsToHotel;

    protected $table = 'whatsapp_accounts';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
