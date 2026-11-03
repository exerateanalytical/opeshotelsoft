<?php

namespace App\Modules\Notifications\Domain;

use App\Platform\Tenancy\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;

class NotificationPreference extends Model
{
    use BelongsToGroup;

    protected $fillable = ['guest_id', 'email', 'sms', 'whatsapp', 'marketing'];

    protected function casts(): array
    {
        return ['email' => 'boolean', 'sms' => 'boolean', 'whatsapp' => 'boolean', 'marketing' => 'boolean'];
    }
}
