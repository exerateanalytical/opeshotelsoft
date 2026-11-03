<?php

namespace App\Modules\ServiceRequests\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class VoiceEvent extends Model
{
    use BelongsToHotel;

    protected $table = 'service_voice_events';

    public $timestamps = false;

    protected static function booted(): void
    {
        static::creating(fn (self $e) => $e->created_at ??= now());
    }

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'created_at' => 'datetime'];
    }
}
