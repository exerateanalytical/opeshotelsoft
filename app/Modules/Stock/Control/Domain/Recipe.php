<?php

namespace App\Modules\Stock\Control\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class Recipe extends Model
{
    use BelongsToHotel;

    protected $table = 'recipes';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'yield_qty' => 'float', 'plate_cost' => 'float', 'target_margin_pct' => 'float', 'sell_vat_rate' => 'float', 'costed_at' => 'datetime'];
    }

    public function lines()
    {
        return $this->hasMany(RecipeLine::class)->orderBy('sort')->orderBy('id');
    }
}
