<?php

namespace App\Modules\Reservations\Revenue\Application;

use App\Modules\Guests\Domain\Company;
use App\Modules\Property\Domain\RoomType;
use App\Modules\Reservations\Application\Inventory;
use App\Modules\Reservations\Application\ReservationService;
use App\Modules\Reservations\Domain\RatePlan;
use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Reservations\Domain\RoomBlock;
use App\Modules\Reservations\Revenue\Domain\CorporateAllotment;
use App\Modules\Reservations\Revenue\Domain\CorporateContract;
use App\Platform\Domain\DomainException;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Corporate negotiated rates. A contract ties a company to a (private) rate plan.
 * An allotment is a room block (reused: same pick-up, cut-off and inventory holding as group blocks)
 * created for the contract; unsold rooms flow back to general stock `release_days` before each night.
 *
 * Last-room availability (LRA): a contract with the flag keeps its negotiated rate bookable while any
 * physical room is left, ignoring closed / closed-to-arrival / min-stay restrictions on the rate.
 * Without the flag the rate follows the normal restrictions and only allotment or general stock.
 */
class CorporateService
{
    public function __construct(
        private Inventory $inventory, private ReservationService $reservations, private EventBus $bus, private TenantContext $ctx,
    ) {}

    /**
     * Create a contract. Pass `rate_plan_id` of an existing plan, or `parent_rate_plan_id` + `discount_percent`
     * to create the negotiated plan as a derived plan of the parent.
     */
    public function createContract(array $d): CorporateContract
    {
        return DB::transaction(function () use ($d) {
            $company = Company::findOrFail($d['company_id']);
            if (! empty($d['rate_plan_id'])) {
                $plan = RatePlan::findOrFail($d['rate_plan_id']);
                $plan->update(['company_id' => $company->id, 'public' => false]);
            } else {
                $parent = RatePlan::findOrFail($d['parent_rate_plan_id']);
                $plan = RatePlan::create([
                    'code' => strtoupper(substr('C'.$d['code'], 0, 16)), 'name' => 'Corporate '.$company->name, 'meal_plan' => $parent->meal_plan,
                    'refundable' => $parent->refundable, 'cancellation_hours' => $parent->cancellation_hours,
                    'cancellation_penalty' => $parent->cancellation_penalty, 'penalty_percent' => $parent->penalty_percent,
                    'company_id' => $company->id, 'parent_rate_plan_id' => $parent->id,
                    'derived_adjust_percent' => -abs((float) ($d['discount_percent'] ?? 0)), 'public' => false,
                ]);
            }
            $contract = CorporateContract::create([
                'company_id' => $company->id, 'rate_plan_id' => $plan->id, 'code' => strtoupper($d['code']), 'name' => $d['name'],
                'valid_from' => $d['valid_from'], 'valid_to' => $d['valid_to'], 'last_room_availability' => $d['last_room_availability'] ?? false,
                'notes' => $d['notes'] ?? null,
            ]);
            $this->bus->record('corporate.contract.created', $contract->only(['id', 'code', 'company_id', 'rate_plan_id']), 'corporate_contract', $contract->id);

            return $contract;
        });
    }

    /** Hold rooms for a contract on a date range (inclusive nights). */
    public function addAllotment(CorporateContract $c, int $roomTypeId, int $rooms, string $start, string $end, int $releaseDays = 0): CorporateAllotment
    {
        return DB::transaction(function () use ($c, $roomTypeId, $rooms, $start, $end, $releaseDays) {
            $hotelId = $this->ctx->requireHotelId();
            $a = Carbon::parse($start)->startOfDay();
            $z = Carbon::parse($end)->startOfDay();
            if ($z->lt($a) || $a->lt(Carbon::parse($c->valid_from)) || $z->gt(Carbon::parse($c->valid_to))) {
                throw new DomainException('The allotment must fall inside the contract dates.');
            }
            $block = RoomBlock::create([
                'code' => 'ALT'.$c->id.'-'.substr(uniqid(), -5), 'name' => 'Allotment '.$c->name, 'company_id' => $c->company_id, 'rate_plan_id' => $c->rate_plan_id,
                'arrival' => $a, 'departure' => $z->copy()->addDay(), 'status' => 'definite',
            ]);
            foreach ($this->inventory->nights($a, $z->copy()->addDay()) as $d) {
                $free = $this->inventory->grid($hotelId, $roomTypeId, $d, $d)[$d]['available'];
                if ($free < $rooms) {
                    throw new DomainException("Only $free rooms of this type are free on $d.", 'not-available', 409);
                }
                $block->allocations()->create(['room_type_id' => $roomTypeId, 'date' => $d, 'rooms' => $rooms]);
                $this->inventory->adjust($hotelId, $roomTypeId, $d, 'blocked', $rooms);
            }

            return CorporateAllotment::create([
                'corporate_contract_id' => $c->id, 'room_block_id' => $block->id, 'room_type_id' => $roomTypeId, 'rooms' => $rooms,
                'start_date' => $a, 'end_date' => $z, 'release_days' => $releaseDays,
            ]);
        });
    }

    /** Give back unsold allotment rooms whose release date has passed. @return int rooms returned to general stock */
    public function releaseDue(?Carbon $today = null): int
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $hotelId = $this->ctx->requireHotelId();
        $released = 0;
        DB::transaction(function () use ($today, $hotelId, &$released) {
            foreach (CorporateAllotment::with('block.allocations')->get() as $al) {
                foreach ($al->block->allocations as $alloc) {
                    $unsold = $alloc->rooms - $alloc->picked_up;
                    if ($unsold > 0 && Carbon::parse($alloc->date)->subDays($al->release_days)->lte($today)) {
                        $alloc->update(['rooms' => $alloc->picked_up]);
                        $this->inventory->adjust($hotelId, $alloc->room_type_id, substr($alloc->date, 0, 10), 'blocked', -$unsold);
                        $released += $unsold;
                    }
                }
            }
        });
        if ($released) {
            $this->bus->record('corporate.allotments.released', ['rooms' => $released, 'as_of' => $today->toDateString()]);
        }

        return $released;
    }

    public function find(string $code, ?Carbon $on = null): ?CorporateContract
    {
        $on = ($on ?? now())->copy()->startOfDay();

        return CorporateContract::where('code', strtoupper(trim($code)))->where('active', true)->first();
    }

    /**
     * Can this contract book the room type for the stay? source: "allotment" (a held room), "general" or null.
     *
     * @return array{bookable:bool, source:?string, room_block_id:?int, allotment_left:int, general_left:int, enforce_restrictions:bool}
     */
    public function availability(CorporateContract $c, int $roomTypeId, Carbon $arrival, Carbon $departure): array
    {
        $none = ['bookable' => false, 'source' => null, 'room_block_id' => null, 'allotment_left' => 0, 'general_left' => 0, 'enforce_restrictions' => ! $c->last_room_availability];
        if (! $c->active || $arrival->lt($c->valid_from) || $departure->copy()->subDay()->gt($c->valid_to)) {
            return $none;
        }
        $nights = $this->inventory->nights($arrival, $departure);
        $grid = $this->inventory->grid($this->ctx->requireHotelId(), $roomTypeId, $arrival->toDateString(), $departure->copy()->subDay()->toDateString());
        $general = min(array_column($grid, 'available') ?: [0]);

        $block = null;
        $left = 0;
        foreach (CorporateAllotment::where('corporate_contract_id', $c->id)->where('room_type_id', $roomTypeId)->with('block.allocations')->get() as $al) {
            $per = collect($nights)->map(function ($d) use ($al) {
                $row = $al->block->allocations->first(fn ($x) => substr($x->date, 0, 10) === $d);

                return $row ? $row->rooms - $row->picked_up : 0;
            });
            if ($per->min() > 0) {
                $block = $al->room_block_id;
                $left = (int) $per->min();
                break;
            }
        }

        $source = $block ? 'allotment' : ($general >= 1 ? 'general' : null);

        return ['bookable' => $source !== null, 'source' => $source, 'room_block_id' => $block, 'allotment_left' => $left, 'general_left' => $general] + ['enforce_restrictions' => ! $c->last_room_availability];
    }

    /** Extra fields for ReservationService::create when booking under this contract. */
    public function bookingFields(CorporateContract $c, int $roomTypeId, Carbon $arrival, Carbon $departure): array
    {
        $a = $this->availability($c, $roomTypeId, $arrival, $departure);
        if (! $a['bookable']) {
            throw new DomainException('No room is available under this corporate contract for these dates.', 'not-available', 409);
        }

        return [
            'company_id' => $c->company_id, 'rate_plan_id' => $c->rate_plan_id, 'room_block_id' => $a['room_block_id'],
            'enforce_restrictions' => $a['enforce_restrictions'], 'source' => 'corporate:'.$c->code,
        ];
    }

    public function book(CorporateContract $c, array $data): Reservation
    {
        $fields = $this->bookingFields($c, $data['room_type_id'], Carbon::parse($data['arrival']), Carbon::parse($data['departure']));

        return $this->reservations->create($fields + $data);
    }
}
