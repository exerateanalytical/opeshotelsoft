<?php

namespace App\Modules\Reservations\Revenue\Application;

use App\Models\User;
use App\Modules\Property\Domain\RoomType;
use App\Modules\Reservations\Application\Inventory;
use App\Modules\Reservations\Application\Pricing;
use App\Modules\Reservations\Domain\RatePlan;
use App\Modules\Reservations\Revenue\Domain\CompetitorRate;
use App\Modules\Reservations\Revenue\Domain\PricingEvent;
use App\Modules\Reservations\Revenue\Domain\RateSuggestion;
use App\Platform\Domain\DomainException;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds rate suggestions from occupancy, pickup pace, weekday, local events and competitor rates.
 * Generating NEVER changes a rate: a manager accepts (writes the rate) or rejects each suggestion.
 */
class SuggestionService
{
    public function __construct(
        private SuggestionEngine $engine, private RevenueConfig $config, private Inventory $inventory,
        private Pricing $pricing, private EventBus $bus, private TenantContext $ctx,
    ) {}

    /** @return Collection<int, RateSuggestion> the new pending suggestions */
    public function generate(string $from, string $to, ?int $ratePlanId = null, ?int $roomTypeId = null): Collection
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();
        if ($end->lt($start) || $start->diffInDays($end) > 90) {
            throw new DomainException('Choose a period of at most 90 days.');
        }
        $hotelId = $this->ctx->requireHotelId();
        $s = $this->config->get()->toArray();
        $plans = RatePlan::where('active', true)->whereNull('parent_rate_plan_id')->whereNull('company_id')
            ->when($ratePlanId, fn ($q, $v) => $q->where('id', $v))->get();
        $types = RoomType::where('active', true)->when($roomTypeId, fn ($q, $v) => $q->where('id', $v))->get();
        $events = PricingEvent::where('start_date', '<=', $to)->where('end_date', '>=', $from)->get();
        $competitors = CompetitorRate::whereBetween('date', [$from, $to])->get();

        return DB::transaction(function () use ($start, $end, $hotelId, $s, $plans, $types, $events, $competitors) {
            $created = collect();
            foreach ($types as $type) {
                $grid = $this->inventory->grid($hotelId, $type->id, $start->toDateString(), $end->toDateString());
                $pace = $this->pace($type->id, $start, $end);
                foreach ($plans as $plan) {
                    foreach (CarbonPeriod::create($start, $end) as $day) {
                        $date = $day->toDateString();
                        if ($day->lt(now()->startOfDay())) {
                            continue;
                        }
                        $g = $grid[$date];
                        $sellable = max(1, $g['capacity'] - $g['out_of_order']);
                        $current = $this->pricing->effectiveRates($plan, $type, $day->copy(), $day->copy())[0]['amount'];
                        $comp = $competitors->filter(fn ($c) => $c->date->toDateString() === $date && (! $c->room_type_id || $c->room_type_id === $type->id));
                        $result = $this->engine->evaluate($s, [
                            'current' => (int) $current,
                            'occupancy_pct' => round(($g['sold'] + $g['blocked']) / $sellable * 100, 1),
                            'pace_pct' => round(($pace[$date] ?? 0) / $sellable * 100, 1),
                            'dow' => $day->dayOfWeek,
                            'events' => $events->filter(fn ($e) => $e->start_date->toDateString() <= $date && $e->end_date->toDateString() >= $date)
                                ->map(fn ($e) => ['name' => $e->name, 'impact_pct' => $e->impact_pct])->values()->all(),
                            'competitor_avg' => $comp->isNotEmpty() ? (int) round($comp->avg('rate')) : null,
                        ]);

                        // A fresh run replaces the previous pending suggestion for the same cell.
                        RateSuggestion::where(['rate_plan_id' => $plan->id, 'room_type_id' => $type->id, 'date' => $date, 'status' => 'pending'])->update(['status' => 'superseded']);
                        if ($result['suggested'] === (int) $current) {
                            continue;
                        }
                        $created->push(RateSuggestion::create([
                            'rate_plan_id' => $plan->id, 'room_type_id' => $type->id, 'date' => $date,
                            'current_rate' => $current, 'suggested_rate' => $result['suggested'],
                            'change_pct' => $result['change_pct'], 'reasons' => $result['reasons'],
                        ])->refresh());
                    }
                }
            }
            $this->bus->record('revenue.suggestions.generated', ['count' => $created->count(), 'from' => $start->toDateString(), 'to' => $end->toDateString()]);

            return $created;
        });
    }

    /** Accept: writes the daily rate (optionally edited by the manager). The only place a suggestion changes a price. */
    public function accept(RateSuggestion $sug, ?User $by = null, ?int $rate = null, ?string $note = null): RateSuggestion
    {
        return DB::transaction(function () use ($sug, $by, $rate, $note) {
            $sug = RateSuggestion::lockForUpdate()->findOrFail($sug->id);
            if ($sug->status !== 'pending') {
                throw new DomainException("This suggestion is already {$sug->status}.", 'already-decided', 409);
            }
            $amount = $rate ?? $sug->suggested_rate;
            if ($amount < 1) {
                throw new DomainException('The rate must be positive.');
            }
            $date = $sug->date->toDateString();
            $key = ['rate_plan_id' => $sug->rate_plan_id, 'room_type_id' => $sug->room_type_id, 'date' => $date];
            $existing = DB::table('rates')->where($key)->first();
            if ($existing) {
                DB::table('rates')->where('id', $existing->id)->update(['amount' => $amount, 'updated_at' => now()]);
            } else {
                $base = $this->pricing->effectiveRates(RatePlan::findOrFail($sug->rate_plan_id), RoomType::findOrFail($sug->room_type_id), $sug->date->copy(), $sug->date->copy())[0];
                DB::table('rates')->insert($key + [
                    'hotel_id' => $sug->hotel_id, 'amount' => $amount, 'extra_adult' => $base['extra_adult'], 'extra_child' => $base['extra_child'],
                    'min_stay' => $base['min_stay'] ?? 1, 'max_stay' => $base['max_stay'], 'closed' => (bool) $base['closed'],
                    'closed_to_arrival' => (bool) $base['closed_to_arrival'], 'closed_to_departure' => (bool) $base['closed_to_departure'],
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $sug->update(['status' => 'accepted', 'decided_by' => $by?->id, 'decided_at' => now(), 'decision_note' => $note]);
            // Same event the manual rate editor emits, so channels push the new price.
            $this->bus->record('rates.updated', [
                'rate_plan_id' => $sug->rate_plan_id, 'room_type_ids' => [$sug->room_type_id], 'from' => $date, 'to' => $date,
                'weekdays' => null, 'fields' => ['amount' => $amount],
            ], 'rate_plan', $sug->rate_plan_id);
            $this->bus->record('revenue.suggestion.accepted', ['id' => $sug->id, 'from' => $sug->current_rate, 'to' => $amount, 'date' => $date], 'rate_suggestion', $sug->id);

            return $sug;
        });
    }

    public function reject(RateSuggestion $sug, ?User $by = null, ?string $note = null): RateSuggestion
    {
        return DB::transaction(function () use ($sug, $by, $note) {
            $sug = RateSuggestion::lockForUpdate()->findOrFail($sug->id);
            if ($sug->status !== 'pending') {
                throw new DomainException("This suggestion is already {$sug->status}.", 'already-decided', 409);
            }
            $sug->update(['status' => 'rejected', 'decided_by' => $by?->id, 'decided_at' => now(), 'decision_note' => $note]);
            $this->bus->record('revenue.suggestion.rejected', ['id' => $sug->id, 'date' => $sug->date->toDateString()], 'rate_suggestion', $sug->id);

            return $sug;
        });
    }

    /** Room nights picked up in the last 7 days, per stay night, for a room type. @return array<string,int> */
    public function pace(int $roomTypeId, Carbon $from, Carbon $to): array
    {
        return DB::table('reservation_nights as n')->join('reservations as r', 'r.id', '=', 'n.reservation_id')
            ->where('r.hotel_id', $this->ctx->requireHotelId())->where('r.room_type_id', $roomTypeId)
            ->whereIn('r.status', ['tentative', 'confirmed', 'checked_in'])
            ->where('r.created_at', '>=', now()->subDays(7))
            ->whereBetween('n.date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('n.date as d, count(*) as c')->groupBy('n.date')->get()
            ->mapWithKeys(fn ($r) => [substr($r->d, 0, 10) => (int) $r->c])->all();
    }
}
