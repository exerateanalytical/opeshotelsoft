<?php

namespace App\Modules\POS\Domain;

use App\Modules\Property\Domain\Outlet;
use App\Platform\Domain\DomainException;
use App\Platform\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class Check extends Model
{
    use BelongsToHotel;

    protected $table = 'pos_checks';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['business_date' => 'date', 'settled_at' => 'datetime'];
    }

    public function lines()
    {
        return $this->hasMany(CheckLine::class, 'pos_check_id')->orderBy('id');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class, 'pos_check_id');
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function table()
    {
        return $this->belongsTo(PosTable::class, 'pos_table_id');
    }

    public function assertOpen(): void
    {
        if ($this->status !== 'open') {
            throw new DomainException("Check {$this->number} is {$this->status}.", 'check-closed');
        }
    }

    public function recalculate(): void
    {
        $subtotal = (int) $this->lines()->where('voided', false)->sum('amount');
        $comp = (int) $this->lines()->where('voided', false)->where('comp_status', 'approved')->sum('amount');
        $this->update(['subtotal' => $subtotal, 'comp_total' => $comp, 'total' => max(0, $subtotal - $comp - $this->discount)]);
    }
}
