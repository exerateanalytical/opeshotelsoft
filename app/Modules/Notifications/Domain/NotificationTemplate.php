<?php

namespace App\Modules\Notifications\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class NotificationTemplate extends Model
{
    use BelongsToHotel;

    protected $fillable = ['event', 'channel', 'locale', 'category', 'subject', 'body', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
