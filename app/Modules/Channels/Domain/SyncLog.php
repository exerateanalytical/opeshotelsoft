<?php

namespace App\Modules\Channels\Domain;

use Illuminate\Database\Eloquent\Model;

class SyncLog extends Model
{
    protected $table = 'channel_sync_logs';

    protected $guarded = ['id'];
}
