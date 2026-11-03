<?php

namespace App\Modules\People\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class DisciplinaryAction extends Model
{
    use BelongsToHotel;

    protected $table = 'people_disciplinary_actions';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['incident_date' => 'date', 'suspension_start' => 'date', 'suspension_end' => 'date', 'acknowledged_at' => 'datetime'];
    }

    public function documents()
    {
        return $this->hasMany(DisciplinaryDocument::class, 'action_id');
    }
}
