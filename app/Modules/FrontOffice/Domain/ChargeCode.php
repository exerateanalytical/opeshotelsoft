<?php

namespace App\Modules\FrontOffice\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class ChargeCode extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['tax_codes' => 'array', 'active' => 'boolean'];
    }
}
