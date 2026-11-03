<?php

namespace App\Modules\FrontOffice\Domain;

use Illuminate\Database\Eloquent\Model;

/** Append-only folio posting. Never updated except the `reversed` flag. */
class FolioLine extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['business_date' => 'date', 'tax_breakdown' => 'array', 'reversed' => 'boolean', 'quantity' => 'float'];
    }

    public function folio()
    {
        return $this->belongsTo(Folio::class);
    }
}
