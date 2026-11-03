<?php

namespace App\Modules\Reservations\Revenue\Domain;

use App\Modules\Guests\Domain\Company;
use App\Modules\Reservations\Domain\RatePlan;
use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class CorporateContract extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    public function ratePlan()
    {
        return $this->belongsTo(RatePlan::class, 'rate_plan_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function allotments()
    {
        return $this->hasMany(CorporateAllotment::class);
    }

    protected function casts(): array
    {
        return ['valid_from' => 'date', 'valid_to' => 'date', 'last_room_availability' => 'boolean', 'active' => 'boolean'];
    }
}
