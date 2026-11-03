<?php

namespace App\Modules\Payments\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** A short signed pay link (and its QR) for a folio, POS check, city-ledger invoice or booking deposit. */
class PaymentLink extends Model
{
    use BelongsToHotel;

    protected $table = 'payment_links';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    public const TARGETS = ['folio', 'pos_check', 'invoice', 'reservation'];

    protected function casts(): array
    {
        return ['allow_partial' => 'boolean', 'expires_at' => 'datetime', 'paid_at' => 'datetime'];
    }

    public function intents()
    {
        return $this->hasMany(PaymentIntent::class)->orderBy('id');
    }

    public function balance(): int
    {
        return max(0, (int) $this->amount - (int) $this->amount_paid);
    }

    public function isPayable(): bool
    {
        return in_array($this->status, ['open', 'partially_paid'], true) && ! ($this->expires_at && $this->expires_at->isPast());
    }
}
