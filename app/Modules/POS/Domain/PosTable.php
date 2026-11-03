<?php

namespace App\Modules\POS\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class PosTable extends Model
{
    use BelongsToHotel;

    protected $table = 'pos_tables';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'seats' => 'integer', 'pos_x' => 'integer', 'pos_y' => 'integer'];
    }
}
