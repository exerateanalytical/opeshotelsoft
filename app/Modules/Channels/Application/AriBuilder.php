<?php

namespace App\Modules\Channels\Application;

use App\Modules\Channels\Domain\Connection;
use App\Modules\Property\Domain\RoomType;
use App\Modules\Reservations\Application\Inventory;
use App\Modules\Reservations\Domain\RatePlan;
use App\Modules\Tenancy\Domain\Hotel;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

/** Builds channel ARI rows from our inventory and rate grid, applying mappings and markups. */
class AriBuilder
{
    public function __construct(private Inventory $inventory) {}

    public function rows(Connection $c, string $from, string $to, ?array $roomTypeIds = null): array
    {
        $hotel = Hotel::findOrFail($c->hotel_id);
        $rows = [];
        $rooms = $c->roomMappings()->when($roomTypeIds, fn ($q) => $q->whereIn('room_type_id', $roomTypeIds))->get();
        $rates = $c->rateMappings()->get();

        foreach ($rooms as $rm) {
            $type = RoomType::find($rm->room_type_id);
            if (! $type) {
                continue;
            }
            $grid = $this->inventory->grid($hotel->id, $type->id, $from, $to);
            $rateRows = DB::table('rates')->where('room_type_id', $type->id)->whereIn('rate_plan_id', $rates->pluck('rate_plan_id'))
                ->whereBetween('date', [$from, $to])->get()->groupBy('rate_plan_id')
                ->map(fn ($g) => $g->keyBy(fn ($r) => substr($r->date, 0, 10)));

            foreach (CarbonPeriod::create($from, $to) as $day) {
                $d = $day->toDateString();
                $available = $grid[$d]['available'];
                if ($rm->max_allotment !== null) {
                    $available = min($available, $rm->max_allotment);
                }
                if ($c->push_availability) {
                    $rows[] = ['room' => $rm->external_room_id, 'rate' => null, 'date' => $d, 'availability' => $available, 'currency' => $hotel->currency, 'closed' => $grid[$d]['stop_sell']];
                }
                if (! $c->push_rates) {
                    continue;
                }
                foreach ($rates as $map) {
                    $plan = RatePlan::find($map->rate_plan_id);
                    if (! $plan || ! $plan->active) {
                        continue;
                    }
                    $r = $rateRows[$map->rate_plan_id][$d] ?? null;
                    $base = $r->amount ?? $type->base_rate;
                    $rows[] = [
                        'room' => $rm->external_room_id, 'rate' => $map->external_rate_id, 'date' => $d,
                        'amount' => (int) round($base * (1 + $map->markup_percent / 100)), 'currency' => $hotel->currency,
                        'min_stay' => $r->min_stay ?? 1, 'closed' => (bool) ($r->closed ?? false) || $grid[$d]['stop_sell'],
                        'cta' => (bool) ($r->closed_to_arrival ?? false), 'ctd' => (bool) ($r->closed_to_departure ?? false),
                    ];
                }
            }
        }

        return $rows;
    }
}
