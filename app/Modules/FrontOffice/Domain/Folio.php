<?php

namespace App\Modules\FrontOffice\Domain;

use App\Modules\Reservations\Domain\Reservation;
use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class Folio extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['closed_at' => 'datetime'];
    }

    public function lines()
    {
        return $this->hasMany(FolioLine::class)->orderBy('id');
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }
}
