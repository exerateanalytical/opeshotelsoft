<?php

namespace App\Modules\ServiceRequests\Domain;

use Illuminate\Database\Eloquent\Model;

/** Per-user (not per-hotel) voice settings. */
class VoicePref extends Model
{
    protected $table = 'service_voice_prefs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }
}
