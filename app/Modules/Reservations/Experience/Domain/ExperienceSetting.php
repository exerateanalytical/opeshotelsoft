<?php

namespace App\Modules\Reservations\Experience\Domain;

use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** Per-hotel rules for deposits, no-show automation and guest self-service. One row per hotel, created on first read. */
class ExperienceSetting extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['auto_release' => 'boolean', 'auto_no_show' => 'boolean', 'guest_modify' => 'boolean'];
    }

    public static function forCurrentHotel(): self
    {
        return static::firstOrCreate([])->refresh();
    }

    public static function forHotel(Hotel $hotel): self
    {
        return static::withoutGlobalScopes()->firstOrCreate(['hotel_id' => $hotel->id], ['group_id' => $hotel->group_id])->refresh();
    }
}
