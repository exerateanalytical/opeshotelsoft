<?php

namespace App\Modules\Reservations\Http;

use App\Modules\Property\Domain\RoomType;
use App\Modules\Reservations\Application\Inventory;
use App\Modules\Reservations\Application\Pricing;
use App\Modules\Reservations\Domain\RatePlan;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Domain\DomainException;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Availability, Rates and Inventory (ARI). */
class AriController
{
    public function __construct(private TenantContext $ctx) {}

    /** GET rates?from=&to=&rate_plan_id=&room_type_id= */
    public function rates(Request $request, Pricing $pricing)
    {
        abort_unless($request->user()->can('rates.view') || $request->user()->can('rates.manage'), 403);
        $data = $request->validate([
            'from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from'],
            'rate_plan_id' => ['nullable', 'integer'], 'room_type_id' => ['nullable', 'integer'],
        ]);

        // Every night gets the price the booking engine would charge: a saved row ("override")
        // or the derived/base price ("base"). Tenant scoping applies via the models.
        $from = Carbon::parse($data['from']);
        $to = Carbon::parse($data['to']);
        if ($from->diffInDays($to) > 370) {
            throw new DomainException('Rates can be read for at most one year at a time.');
        }
        $plans = RatePlan::query()->when($data['rate_plan_id'] ?? null, fn ($q, $v) => $q->where('id', $v), fn ($q) => $q->where('active', true))->get();
        $types = RoomType::query()->when($data['room_type_id'] ?? null, fn ($q, $v) => $q->where('id', $v), fn ($q) => $q->where('active', true))->orderBy('sort')->get();

        $out = [];
        foreach ($plans as $plan) {
            foreach ($types as $type) {
                array_push($out, ...$pricing->effectiveRates($plan, $type, $from, $to));
            }
        }
        usort($out, fn ($a, $b) => strcmp(substr($a['date'], 0, 10), substr($b['date'], 0, 10)));

        return response()->json($out);
    }

    /**
     * PUT rates — bulk update a date range (e.g. a season), optionally only some weekdays.
     * Emits rates.updated, which the Channel context pushes to OTAs/channel managers.
     */
    public function setRates(Request $request, EventBus $bus)
    {
        abort_unless($request->user()->can('rates.manage'), 403);
        $hotelId = $this->ctx->requireHotelId();
        $data = $request->validate([
            'rate_plan_id' => ['required', 'integer', Rule::exists('rate_plans', 'id')->where('hotel_id', $hotelId)],
            'room_type_ids' => ['required', 'array', 'min:1'],
            'room_type_ids.*' => ['integer', Rule::exists('room_types', 'id')->where('hotel_id', $hotelId)],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from', 'before:'.Carbon::parse($request->input('from', 'today'))->addDays(731)->toDateString()],
            'weekdays' => ['nullable', 'array'], 'weekdays.*' => ['integer', 'between:0,6'],
            'amount' => ['nullable', 'integer', 'min:0'],
            'extra_adult' => ['nullable', 'integer', 'min:0'],
            'extra_child' => ['nullable', 'integer', 'min:0'],
            'min_stay' => ['nullable', 'integer', 'between:1,30'],
            'max_stay' => ['nullable', 'integer', 'between:1,365'],
            'closed' => ['nullable', 'boolean'],
            'closed_to_arrival' => ['nullable', 'boolean'],
            'closed_to_departure' => ['nullable', 'boolean'],
        ]);

        $fields = array_filter(
            array_intersect_key($data, array_flip(['amount', 'extra_adult', 'extra_child', 'min_stay', 'max_stay', 'closed', 'closed_to_arrival', 'closed_to_departure'])),
            fn ($v) => $v !== null,
        );
        if (! $fields) {
            throw new DomainException('Nothing to update: send at least one of amount or a restriction.');
        }

        $count = DB::transaction(function () use ($data, $fields, $hotelId, $bus) {
            $n = 0;
            foreach (CarbonPeriod::create($data['from'], $data['to']) as $day) {
                if (! empty($data['weekdays']) && ! in_array($day->dayOfWeek, $data['weekdays'], true)) {
                    continue;
                }
                foreach ($data['room_type_ids'] as $typeId) {
                    $key = ['rate_plan_id' => $data['rate_plan_id'], 'room_type_id' => $typeId, 'date' => $day->toDateString()];
                    $existing = DB::table('rates')->where($key)->first();
                    if ($existing) {
                        DB::table('rates')->where('id', $existing->id)->update($fields + ['updated_at' => now()]);
                    } else {
                        $amount = $fields['amount'] ?? RoomType::find($typeId)->base_rate;
                        DB::table('rates')->insert($key + $fields + ['amount' => $amount, 'hotel_id' => $hotelId, 'created_at' => now(), 'updated_at' => now()]);
                    }
                    $n++;
                }
            }
            $bus->record('rates.updated', [
                'rate_plan_id' => $data['rate_plan_id'], 'room_type_ids' => $data['room_type_ids'],
                'from' => $data['from'], 'to' => $data['to'], 'weekdays' => $data['weekdays'] ?? null, 'fields' => $fields,
            ], 'rate_plan', $data['rate_plan_id']);

            return $n;
        });

        return response()->json(['updated' => $count]);
    }

    /** GET inventory?from=&to= — per room type per night. */
    public function inventory(Request $request, Inventory $inventory)
    {
        abort_unless($request->user()->can('rates.view') || $request->user()->can('reservations.view') || $request->user()->can('rates.manage'), 403);
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);
        $hotelId = $this->ctx->requireHotelId();

        return RoomType::where('active', true)->orderBy('sort')->get()->map(fn ($t) => [
            'room_type' => $t->only(['id', 'code', 'name']),
            'days' => array_values($inventory->grid($hotelId, $t->id, $data['from'], $data['to'])),
        ]);
    }

    /** PUT inventory — stop-sell / overbooking limits. Emits availability.updated. */
    public function setInventory(Request $request, Inventory $inventory, EventBus $bus)
    {
        abort_unless($request->user()->can('rates.manage'), 403);
        $hotelId = $this->ctx->requireHotelId();
        $data = $request->validate([
            'room_type_ids' => ['required', 'array'], 'room_type_ids.*' => ['integer', Rule::exists('room_types', 'id')->where('hotel_id', $hotelId)],
            'from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from'],
            'stop_sell' => ['nullable', 'boolean'], 'overbooking_limit' => ['nullable', 'integer', 'between:0,50'],
        ]);
        $values = array_filter(['stop_sell' => $data['stop_sell'] ?? null, 'overbooking_limit' => $data['overbooking_limit'] ?? null], fn ($v) => $v !== null);

        DB::transaction(function () use ($data, $values, $hotelId, $inventory, $bus) {
            foreach (CarbonPeriod::create($data['from'], $data['to']) as $day) {
                foreach ($data['room_type_ids'] as $typeId) {
                    $inventory->set($hotelId, $typeId, $day->toDateString(), $values);
                }
            }
            $bus->record('availability.updated', ['room_type_ids' => $data['room_type_ids'], 'from' => $data['from'], 'to' => $data['to']]);
        });

        return response()->json(['status' => 'ok']);
    }

    /**
     * GET availability?arrival=&departure=&adults=&children=&hotel_ids[]=
     * Searches the active hotel, or several hotels of the group (cross-property).
     */
    public function search(Request $request, Inventory $inventory, Pricing $pricing)
    {
        $data = $request->validate([
            'arrival' => ['required', 'date', 'after_or_equal:yesterday'],
            'departure' => ['required', 'date', 'after:arrival'],
            'adults' => ['sometimes', 'integer', 'between:1,12'],
            'children' => ['sometimes', 'integer', 'between:0,12'],
            'hotel_ids' => ['sometimes', 'array'], 'hotel_ids.*' => ['integer'],
            'rate_plan_id' => ['sometimes', 'integer'],
            'public_only' => ['sometimes', 'boolean'],
        ]);
        $arrival = Carbon::parse($data['arrival']);
        $departure = Carbon::parse($data['departure']);
        $hotelIds = $data['hotel_ids'] ?? [$this->ctx->requireHotelId()];

        $results = [];
        foreach (Hotel::whereIn('id', $hotelIds)->get() as $hotel) {
            $saved = [$this->ctx->groupId(), $this->ctx->hotelId(), $this->ctx->allowedHotelIds(), $this->ctx->actor()];
            $this->ctx->set($hotel->group_id, $hotel->id, $saved[2], $saved[3]);
            try {
                $offers = [];
                foreach (RoomType::where('active', true)->orderBy('sort')->get() as $type) {
                    $grid = $inventory->grid($hotel->id, $type->id, $arrival->toDateString(), $departure->copy()->subDay()->toDateString());
                    $available = min(array_column($grid, 'available') ?: [0]);
                    if ($available < 1) {
                        continue;
                    }
                    $plans = RatePlan::where('active', true)
                        ->when($data['public_only'] ?? false, fn ($q) => $q->where('public', true))
                        ->when($data['rate_plan_id'] ?? null, fn ($q, $v) => $q->where('id', $v))->get();
                    foreach ($plans as $plan) {
                        try {
                            $q = $pricing->quote($plan, $type, $arrival, $departure, $data['adults'] ?? 1, $data['children'] ?? 0);
                        } catch (DomainException) {
                            continue;
                        }
                        $offers[] = [
                            'room_type' => $type->only(['id', 'code', 'name', 'max_adults', 'max_children']),
                            'rate_plan' => $plan->only(['id', 'code', 'name', 'meal_plan', 'refundable', 'members_only']),
                            'available' => $available,
                            'currency' => $hotel->currency,
                            'total' => $q['total'],
                            'nights' => $q['nights'],
                        ];
                    }
                }
                $results[] = ['hotel' => $hotel->only(['id', 'code', 'name', 'city']), 'offers' => $offers];
            } finally {
                $this->ctx->set(...$saved);
            }
        }

        return response()->json(['data' => $results]);
    }
}
