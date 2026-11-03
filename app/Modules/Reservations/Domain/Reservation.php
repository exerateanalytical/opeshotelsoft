<?php

namespace App\Modules\Reservations\Domain;

use App\Modules\Guests\Domain\Company;
use App\Modules\Guests\Domain\Guest;
use App\Modules\Property\Domain\Room;
use App\Modules\Property\Domain\RoomType;
use App\Platform\Domain\DomainException;
use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Reservation extends Model
{
    use BelongsToHotel, LogsActivity;

    public const ACTIVE = ['tentative', 'confirmed', 'checked_in'];

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return [
            'arrival' => 'date', 'departure' => 'date',
            'cancelled_at' => 'datetime', 'checked_in_at' => 'datetime', 'checked_out_at' => 'datetime',
            'room_prefs' => 'array', 'deposit_deadline' => 'datetime', 'deposit_reminded_at' => 'datetime',
        ];
    }

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function ratePlan()
    {
        return $this->belongsTo(RatePlan::class);
    }

    public function nights()
    {
        return $this->hasMany(ReservationNight::class)->orderBy('date');
    }

    public function nightCount(): int
    {
        return (int) $this->arrival->diffInDays($this->departure);
    }

    public function assertStatus(string ...$allowed): void
    {
        if (! in_array($this->status, $allowed, true)) {
            throw new DomainException("Reservation {$this->number} is {$this->status}; expected ".implode(' or ', $allowed).'.', 'invalid-state');
        }
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['status', 'arrival', 'departure', 'room_id', 'room_type_id', 'rate_plan_id', 'total_amount'])->logOnlyDirty();
    }
}
