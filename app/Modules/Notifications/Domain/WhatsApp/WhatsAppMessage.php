<?php

namespace App\Modules\Notifications\Domain\WhatsApp;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class WhatsAppMessage extends Model
{
    use BelongsToHotel;

    protected $table = 'whatsapp_messages';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['media' => 'array', 'dry_run' => 'boolean', 'delivered_at' => 'datetime', 'read_at' => 'datetime'];
    }

    public function conversation()
    {
        return $this->belongsTo(WhatsAppConversation::class, 'conversation_id');
    }
}
