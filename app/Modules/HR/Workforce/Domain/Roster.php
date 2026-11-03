<?php

namespace App\Modules\HR\Workforce\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class Roster extends Model
{
    use BelongsToHotel;

    protected $table = 'workforce_rosters';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['week_start' => 'date', 'forecast' => 'array', 'warnings' => 'array', 'published_at' => 'datetime'];
    }

    public function shifts()
    {
        return $this->hasMany(RosterShift::class);
    }
}
