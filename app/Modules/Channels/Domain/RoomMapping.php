<?php

namespace App\Modules\Channels\Domain;

use Illuminate\Database\Eloquent\Model;

class RoomMapping extends Model
{
    public $timestamps = false;

    protected $table = 'channel_room_mappings';

    protected $guarded = ['id'];
}
