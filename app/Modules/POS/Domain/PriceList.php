<?php

namespace App\Modules\POS\Domain;

use App\Platform\Tenancy\BelongsToHotel;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/** A price level (happy hour, late night...) active in a weekly time window. */
class PriceList extends Model
{
    use BelongsToHotel;

    protected $table = 'pos_price_lists';

    protected $guarded = ['id', 'group_id', 'hotel_id'];

    protected function casts(): array
    {
        return ['days' => 'array', 'active' => 'boolean', 'percent_off' => 'float', 'priority' => 'integer'];
    }

    public function items()
    {
        return $this->hasMany(PriceListItem::class, 'pos_price_list_id');
    }

    public function appliesAt(CarbonInterface $at): bool
    {
        if (! $this->active || ($this->days && ! in_array($at->isoWeekday(), array_map('intval', $this->days), true))) {
            return false;
        }
        $t = $at->format('H:i:s');
        $start = substr($this->starts_at, 0, 8);
        $end = substr($this->ends_at, 0, 8);

        return $start <= $end ? ($t >= $start && $t < $end) : ($t >= $start || $t < $end); // window may cross midnight
    }

    /** Price for an item under this list, or null when the list does not change it. */
    public function priceFor(MenuItem $item): ?int
    {
        $row = $this->items->firstWhere('pos_menu_item_id', $item->id);
        if ($row) {
            return (int) $row->price;
        }

        return $this->percent_off > 0 ? (int) round($item->price * (100 - $this->percent_off) / 100) : null;
    }

    /** Active list for an outlet at a moment: outlet-specific beats hotel-wide, then priority. */
    public static function activeFor(int $outletId, CarbonInterface $at): ?self
    {
        return self::with('items')->where('active', true)
            ->where(fn ($q) => $q->whereNull('outlet_id')->orWhere('outlet_id', $outletId))
            ->get()
            ->filter(fn (self $l) => $l->appliesAt($at))
            ->sortByDesc(fn (self $l) => [$l->outlet_id ? 1 : 0, $l->priority])
            ->first();
    }
}
