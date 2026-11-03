<?php

namespace App\Modules\Notifications\Domain\WhatsApp;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class WhatsAppQuickReply extends Model
{
    use BelongsToHotel;

    protected $table = 'whatsapp_quick_replies';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return [];
    }
}
