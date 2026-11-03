<?php

namespace App\Modules\Channels\Domain;

use Illuminate\Database\Eloquent\Model;

class RateMapping extends Model
{
    public $timestamps = false;

    protected $table = 'channel_rate_mappings';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['markup_percent' => 'float'];
    }
}
