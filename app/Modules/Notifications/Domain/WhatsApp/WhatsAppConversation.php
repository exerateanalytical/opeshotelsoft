<?php

namespace App\Modules\Notifications\Domain\WhatsApp;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class WhatsAppConversation extends Model
{
    use BelongsToHotel;

    protected $table = 'whatsapp_conversations';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['last_inbound_at' => 'datetime', 'last_message_at' => 'datetime'];
    }

    public function messages()
    {
        return $this->hasMany(WhatsAppMessage::class, 'conversation_id')->orderBy('id');
    }

    /** Meta's customer-service window: free-form messages only within 24h of the guest's last message. */
    public function windowOpen(): bool
    {
        if ($this->channel === 'web') {
            return true; // reception chat from the member portal has no 24-hour rule
        }

        return $this->last_inbound_at !== null && $this->last_inbound_at->gt(now()->subHours(24));
    }
}
