<?php

namespace App\Modules\Payments\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** Money received without a started intent (unsolicited MoMo callback or statement line), matched to a pay link. */
class PaymentInbound extends Model
{
    use BelongsToHotel;

    protected $table = 'payment_inbound';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['candidates' => 'array', 'date' => 'date'];
    }

    public function link()
    {
        return $this->belongsTo(PaymentLink::class, 'payment_link_id');
    }
}
