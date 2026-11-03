<?php

namespace App\Modules\Notifications\Domain\WhatsApp;

use App\Platform\Tenancy\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;

class WhatsAppOptOut extends Model
{
    use BelongsToGroup;

    protected $table = 'whatsapp_opt_outs';

    protected $guarded = ['id', 'group_id'];

    protected function casts(): array
    {
        return ['opted_out_at' => 'datetime'];
    }
}
