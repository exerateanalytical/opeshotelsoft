<?php

namespace App\Modules\Reservations\Application;

use App\Modules\Property\Domain\RoomType;
use App\Modules\Reservations\Domain\RatePlan;
use App\Modules\Reservations\Domain\RatePlanSeason;
use App\Platform\Domain\DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Prices a stay night by night and enforces restrictions:
 * closed, closed-to-arrival, closed-to-departure, min/max stay.
 * Derived rate plans take the parent rate and apply a percentage.
 */
class Pricing
{
    /** @return array{nights: array<int, array{date:string, amount:int}>, total:int} */
    public function quote(RatePlan $plan, RoomType $type, Carbon $arrival, Carbon $departure, int $adults = 1, int $children = 0, bool $enforce = true): array
    {
        $count = (int) $arrival->diffInDays($departure);
        if ($count < 1) {
            throw new DomainException('Departure must be after arrival.');
        }
        if ($adults > $type->max_adults || $children > $type->max_children) {
            throw new DomainException("Room type {$type->code} holds at most {$type->max_adults} adults and {$type->max_children} children.");
        }

        $source = $plan->parent_rate_plan_id ? $plan->parent : $plan;
        $rows = DB::table('rates')->where('rate_plan_id', $plan->id)->where('room_type_id', $type->id)
            ->whereBetween('date', [$arrival->toDateString(), $departure->toDateString()])->get()
            ->keyBy(fn ($r) => substr($r->date, 0, 10));
        $parentRows = $source->id !== $plan->id
            ? DB::table('rates')->where('rate_plan_id', $source->id)->where('room_type_id', $type->id)
                ->whereBetween('date', [$arrival->toDateString(), $departure->toDateString()])->get()
                ->keyBy(fn ($r) => substr($r->date, 0, 10))
            : collect();

        $seasons = $this->seasons($plan, $type, $arrival, $departure);

        $arrivalRow = $rows[$arrival->toDateString()] ?? $parentRows[$arrival->toDateString()] ?? null;
        $departureRow = $rows[$departure->toDateString()] ?? $parentRows[$departure->toDateString()] ?? null;
        if ($enforce) {
            if ($arrivalRow?->closed_to_arrival) {
                throw new DomainException('This rate is closed to arrival on '.$arrival->toDateString().'.', 'restricted');
            }
            if ($departureRow?->closed_to_departure) {
                throw new DomainException('This rate is closed to departure on '.$departure->toDateString().'.', 'restricted');
            }
            if ($arrivalRow && $count < $arrivalRow->min_stay) {
                throw new DomainException("Minimum stay is {$arrivalRow->min_stay} nights for this arrival date.", 'restricted');
            }
            if ($arrivalRow?->max_stay && $count > $arrivalRow->max_stay) {
                throw new DomainException("Maximum stay is {$arrivalRow->max_stay} nights for this arrival date.", 'restricted');
            }
        }

        $nights = [];
        $total = 0;
        for ($d = $arrival->copy(); $d->lt($departure); $d->addDay()) {
            $key = $d->toDateString();
            $own = $rows[$key] ?? null;
            $parent = $parentRows[$key] ?? null;

            if ($enforce && (($own?->closed) || ($parent?->closed && ! $own))) {
                throw new DomainException("This rate is closed on $key.", 'restricted');
            }

            if ($own) {
                $base = $own->amount;
                $extraA = $own->extra_adult;
                $extraC = $own->extra_child;
            } else {
                $base = $parent->amount ?? $type->base_rate;
                if ($plan->parent_rate_plan_id) {
                    $base = (int) round($base * (1 + $plan->derived_adjust_percent / 100));
                }
                $base = $this->applySeason($seasons, $key, (int) $base);
                $extraA = $parent->extra_adult ?? 0;
                $extraC = $parent->extra_child ?? 0;
            }

            $amount = $base + max(0, $adults - 2) * $extraA + $children * $extraC;
            if ($amount <= 0) {
                throw new DomainException("No rate is loaded for {$type->code} on $key.", 'no-rate');
            }
            $nights[] = ['date' => $key, 'amount' => (int) $amount];
            $total += (int) $amount;
        }

        return ['nights' => $nights, 'total' => $total];
    }

    /**
     * Effective nightly base price (no extra persons) for each date in [from, to], computed
     * exactly as quote() does: the plan's own saved row ("override"), otherwise the parent's
     * row or the room type base_rate, adjusted by derived_adjust_percent for derived plans ("base").
     *
     * @return array<int, array<string, mixed>>
     */
    public function effectiveRates(RatePlan $plan, RoomType $type, Carbon $from, Carbon $to): array
    {
        $parentId = $plan->parent_rate_plan_id ?: null;
        $rows = DB::table('rates')->where('room_type_id', $type->id)
            ->whereIn('rate_plan_id', array_filter([$plan->id, $parentId]))
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])->get()
            ->groupBy('rate_plan_id')->map(fn ($g) => $g->keyBy(fn ($r) => substr($r->date, 0, 10)));
        $own = $rows[$plan->id] ?? collect();
        $parentRows = $parentId ? ($rows[$parentId] ?? collect()) : collect();

        $seasons = $this->seasons($plan, $type, $from, $to);
        $out = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $key = $d->toDateString();
            if ($row = $own[$key] ?? null) {
                $out[] = (array) $row + ['source' => 'override'];

                continue;
            }
            $parent = $parentRows[$key] ?? null;
            $amount = $parent->amount ?? $type->base_rate;
            if ($parentId) {
                $amount = (int) round($amount * (1 + $plan->derived_adjust_percent / 100));
            }
            $amount = $this->applySeason($seasons, $key, (int) $amount);
            $out[] = [
                'id' => null, 'hotel_id' => $type->hotel_id, 'rate_plan_id' => $plan->id, 'room_type_id' => $type->id, 'date' => $key,
                'amount' => (int) $amount, 'extra_adult' => $parent->extra_adult ?? 0, 'extra_child' => $parent->extra_child ?? 0,
                'min_stay' => $parent->min_stay ?? null, 'max_stay' => $parent->max_stay ?? null,
                'closed' => (bool) ($parent->closed ?? false), 'closed_to_arrival' => (bool) ($parent->closed_to_arrival ?? false),
                'closed_to_departure' => (bool) ($parent->closed_to_departure ?? false), 'source' => 'base',
            ];
        }

        return $out;
    }

    /**
     * Seasons of this plan overlapping [from, to], most specific first (room-type seasons
     * before all-type ones, then the latest start). Explicit daily rate rows still win.
     */
    public function seasons(RatePlan $plan, RoomType $type, Carbon $from, Carbon $to)
    {
        return RatePlanSeason::where('rate_plan_id', $plan->id)
            ->where(fn ($q) => $q->whereNull('room_type_id')->orWhere('room_type_id', $type->id))
            ->where('start_date', '<=', $to->toDateString())->where('end_date', '>=', $from->toDateString())
            ->orderByRaw('room_type_id IS NULL')->orderByDesc('start_date')->get();
    }

    private function applySeason($seasons, string $date, int $base): int
    {
        $season = $seasons->first(fn ($s) => $s->start_date->toDateString() <= $date && $s->end_date->toDateString() >= $date);

        return $season ? $season->apply($base) : $base;
    }
}
