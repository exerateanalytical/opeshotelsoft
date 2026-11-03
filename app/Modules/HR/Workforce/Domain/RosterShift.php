<?php

namespace App\Modules\HR\Workforce\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class RosterShift extends Model
{
    use BelongsToHotel;

    protected $table = 'workforce_roster_shifts';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function roster()
    {
        return $this->belongsTo(Roster::class);
    }

    public function employee()
    {
        return $this->belongsTo(\App\Modules\HR\Domain\Employee::class);
    }
}
