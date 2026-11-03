<?php

namespace App\Modules\HR\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class Shift extends Model
{
    use BelongsToHotel;

    protected $table = 'shifts';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
