<?php

namespace App\Modules\Documents\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** One issued (numbered, QR-verifiable) document. Never deleted: voided or replaced instead. */
class IssuedDocument extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['issued_at' => 'datetime', 'status_at' => 'datetime', 'amount' => 'integer', 'copy_count' => 'integer'];
    }

    public function reprints()
    {
        return $this->hasMany(IssuedDocumentReprint::class)->orderBy('id');
    }
}
