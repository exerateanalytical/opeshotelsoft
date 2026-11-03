<?php

namespace App\Modules\People\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class DisciplinaryDocument extends Model
{
    use BelongsToHotel;

    protected $table = 'people_disciplinary_documents';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }
}
