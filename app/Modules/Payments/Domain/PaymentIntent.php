<?php

namespace App\Modules\Payments\Domain;

use App\Modules\FrontOffice\Domain\Folio;
use App\Modules\Reservations\Domain\Reservation;
use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class PaymentIntent extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    public const METHODS = ['mtn_momo' => 'MTN', 'orange_money' => 'OM', 'card' => 'CARD'];

    protected function casts(): array
    {
        return ['meta' => 'array', 'expires_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function folio()
    {
        return $this->belongsTo(Folio::class);
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    public function refunds()
    {
        return $this->hasMany(PaymentRefund::class)->orderBy('id');
    }

    public function callbacks()
    {
        return $this->hasMany(PaymentCallback::class)->orderBy('id');
    }

    public function isFinal(): bool
    {
        return $this->status !== 'pending';
    }

    public function methodCode(): string
    {
        return $this->meta['method'] ?? self::METHODS[$this->provider];
    }

    public function link()
    {
        return $this->belongsTo(PaymentLink::class, 'payment_link_id');
    }
}
