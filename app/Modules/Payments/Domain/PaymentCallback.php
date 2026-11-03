<?php

namespace App\Modules\Payments\Domain;

use Illuminate\Database\Eloquent\Model;

/** Raw callback log (not tenant-scoped: callbacks arrive before the hotel is known). */
class PaymentCallback extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'signature_valid' => 'boolean'];
    }
}
