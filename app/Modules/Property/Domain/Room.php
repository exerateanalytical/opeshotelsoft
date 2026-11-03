<?php

namespace App\Modules\Property\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class Room extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    public const STATUSES = ['vacant_clean', 'vacant_dirty', 'occupied_clean', 'occupied_dirty', 'inspected', 'out_of_order', 'out_of_service'];

    protected function casts(): array
    {
        return ['occupied' => 'boolean', 'active' => 'boolean', 'smoking' => 'boolean'];
    }

    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }

    public function isSellable(): bool
    {
        return $this->active && ! in_array($this->hk_status, ['out_of_order', 'out_of_service'], true);
    }

    public function isReadyForArrival(): bool
    {
        return ! $this->occupied && in_array($this->hk_status, ['vacant_clean', 'inspected'], true);
    }
}
