<?php

namespace App\Modules\POS\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class MenuItem extends Model
{
    use BelongsToHotel;

    protected $table = 'pos_menu_items';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function components()
    {
        return $this->hasMany(ItemComponent::class, 'pos_menu_item_id');
    }
}
