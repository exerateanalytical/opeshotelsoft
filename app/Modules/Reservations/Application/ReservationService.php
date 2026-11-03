<?php

namespace App\Modules\Reservations\Application;

use App\Modules\Guests\Domain\Guest;
use App\Modules\Property\Domain\Room;
use App\Modules\Property\Domain\RoomType;
use App\Modules\Reservations\Domain\RatePlan;
use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Reservations\Domain\RoomBlock;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Domain\DomainException;
use App\Platform\Domain\NumberSequence;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Application service for the Reservation aggregate. */
class ReservationService
{
    public function __construct(
        private Inventory $inventory,
        private Pricing $pricing,
        private EventBus $bus,
        private TenantContext $ctx,
    ) {}

    /**
     * @param  array{guest_id:int, room_type_id:int, rate_plan_id:int, arrival:string, departure:string, adults?:int, children?:int,
     *               company_id?:int|null, agent_id?:int|null, room_block_id?:int|null, channel?:string, source?:string|null,
     *               external_ref?:string|null, booking_ref?:string|null, status?:string, guarantee?:string|null,
     *               special_requests?:string|null, notes?:string|null, eta?:string|null, room_id?:int|null,
     *               amount_override?:int|null, enforce_restrictions?:bool}  $data
     */
    public function create(array $data): Reservation
    {
        return DB::transaction(function () use ($data) {
            $hotelId = $this->ctx->requireHotelId();
            $guest = Guest::findOrFail($data['guest_id']);
            if ($guest->blacklisted) {
                throw new DomainException('This guest is blacklisted: '.($guest->blacklist_reason ?: 'see profile').'.', 'guest-blacklisted');
            }
            $type = RoomType::findOrFail($data['room_type_id']);
            $plan = RatePlan::findOrFail($data['rate_plan_id']);
            if (! $plan->active) {
                throw new DomainException("Rate plan {$plan->code} is inactive.");
            }
            if ($plan->members_only && ! DB::table('loyalty_memberships')->where('guest_id', $guest->id)->where('status', 'active')->exists()) {
                throw new DomainException("Rate plan {$plan->code} is reserved for loyalty members.", 'members-only');
            }
            $arrival = Carbon::parse($data['arrival'])->startOfDay();
            $departure = Carbon::parse($data['departure'])->startOfDay();
            $adults = $data['adults'] ?? 1;
            $children = $data['children'] ?? 0;

            $block = null;
            if (! empty($data['room_block_id'])) {
                $block = RoomBlock::findOrFail($data['room_block_id']);
                $this->pickUpFromBlock($block, $type->id, $arrival, $departure);
            }

            $quote = $this->pricing->quote($plan, $type, $arrival, $departure, $adults, $children, $data['enforce_restrictions'] ?? true);
            if (isset($data['amount_override'])) {
                // External channels send the price the guest was charged; keep it per night.
                $per = intdiv($data['amount_override'], count($quote['nights']));
                foreach ($quote['nights'] as $i => $n) {
                    $quote['nights'][$i]['amount'] = $per;
                }
                $quote['nights'][count($quote['nights']) - 1]['amount'] += $data['amount_override'] - $per * count($quote['nights']);
                $quote['total'] = $data['amount_override'];
            }

            $this->inventory->take($hotelId, $type->id, $arrival, $departure, fromBlock: (bool) $block);

            $deposit = $plan->deposit_required ? (int) round($quote['total'] * $plan->deposit_percent / 100) : 0;

            $reservation = Reservation::create([
                'number' => NumberSequence::next($hotelId, 'reservation', 'R'),
                'booking_ref' => $data['booking_ref'] ?? strtoupper(Str::random(8)),
                'status' => $data['status'] ?? 'confirmed',
                'guest_id' => $guest->id,
                'company_id' => $data['company_id'] ?? null,
                'agent_id' => $data['agent_id'] ?? null,
                'room_block_id' => $block?->id,
                'room_type_id' => $type->id,
                'rate_plan_id' => $plan->id,
                'arrival' => $arrival,
                'departure' => $departure,
                'adults' => $adults,
                'children' => $children,
                'channel' => $data['channel'] ?? 'direct',
                'source' => $data['source'] ?? null,
                'external_ref' => $data['external_ref'] ?? null,
                'currency' => $this->ctx->hotelId() ? Hotel::find($hotelId)->currency : 'XAF',
                'total_amount' => $quote['total'],
                'deposit_required' => $deposit,
                'guarantee' => $data['guarantee'] ?? null,
                'eta' => $data['eta'] ?? null,
                'special_requests' => $data['special_requests'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $this->ctx->actor()?->id,
            ]);
            $reservation->nights()->createMany($quote['nights']);

            if (! empty($data['room_id'])) {
                $this->assignRoom($reservation, (int) $data['room_id']);
            }

            $this->bus->record('reservation.created', $this->payload($reservation), 'reservation', $reservation->id);

            return $reservation->load('nights', 'guest', 'roomType', 'ratePlan');
        });
    }

    /** Change dates, room type, rate plan or occupancy; re-prices and moves inventory. */
    public function modify(Reservation $r, array $changes): Reservation
    {
        return DB::transaction(function () use ($r, $changes) {
            $r = Reservation::lockForUpdate()->findOrFail($r->id);
            $r->assertStatus('tentative', 'confirmed', 'checked_in');

            $arrival = Carbon::parse($changes['arrival'] ?? $r->arrival)->startOfDay();
            $departure = Carbon::parse($changes['departure'] ?? $r->departure)->startOfDay();
            if ($r->status === 'checked_in' && ! $arrival->equalTo($r->arrival)) {
                throw new DomainException('Arrival date cannot change after check-in.');
            }
            $type = RoomType::findOrFail($changes['room_type_id'] ?? $r->room_type_id);
            $plan = RatePlan::findOrFail($changes['rate_plan_id'] ?? $r->rate_plan_id);
            $adults = $changes['adults'] ?? $r->adults;
            $children = $changes['children'] ?? $r->children;

            $inventoryChanged = ! $arrival->equalTo($r->arrival) || ! $departure->equalTo($r->departure) || $type->id !== $r->room_type_id;
            if ($inventoryChanged) {
                $this->inventory->release($r->hotel_id, $r->room_type_id, $r->arrival, $r->departure);
                $this->inventory->take($r->hotel_id, $type->id, $arrival, $departure);
            }

            $posted = $r->nights()->where('posted', true)->get()->keyBy(fn ($n) => $n->date->toDateString());
            $quote = $this->pricing->quote($plan, $type, $arrival, $departure, $adults, $children, $changes['enforce_restrictions'] ?? true);
            $r->nights()->where('posted', false)->delete();
            $total = 0;
            foreach ($quote['nights'] as $n) {
                if (isset($posted[$n['date']])) {
                    $total += $posted[$n['date']]->amount;

                    continue;
                }
                $r->nights()->create($n);
                $total += $n['amount'];
            }

            $before = $r->only(['arrival', 'departure', 'room_type_id', 'rate_plan_id', 'adults', 'children', 'total_amount']);
            $r->fill([
                'arrival' => $arrival, 'departure' => $departure, 'room_type_id' => $type->id, 'rate_plan_id' => $plan->id,
                'adults' => $adults, 'children' => $children, 'total_amount' => $total,
            ] + array_intersect_key($changes, array_flip(['special_requests', 'notes', 'eta', 'guarantee', 'company_id', 'agent_id'])));
            if ($type->id !== $before['room_type_id'] && $r->status !== 'checked_in') {
                $r->room_id = null;
            }
            $r->save();

            $this->bus->record('reservation.modified', $this->payload($r) + ['before' => $before], 'reservation', $r->id);

            return $r->load('nights', 'guest', 'roomType', 'ratePlan');
        });
    }

    public function cancel(Reservation $r, ?string $reason = null, ?bool $waiveFee = false): Reservation
    {
        return DB::transaction(function () use ($r, $reason, $waiveFee) {
            $r = Reservation::lockForUpdate()->findOrFail($r->id);
            $r->assertStatus('tentative', 'confirmed');

            $fee = $waiveFee ? 0 : $this->cancellationFee($r);
            $this->inventory->release($r->hotel_id, $r->room_type_id, $r->arrival, $r->departure);
            $r->update([
                'status' => 'cancelled', 'cancelled_at' => now(), 'cancellation_reason' => $reason,
                'cancellation_fee' => $fee, 'room_id' => null,
            ]);
            $this->bus->record('reservation.cancelled', $this->payload($r) + ['fee' => $fee, 'reason' => $reason], 'reservation', $r->id);

            return $r;
        });
    }

    public function markNoShow(Reservation $r): Reservation
    {
        return DB::transaction(function () use ($r) {
            $r = Reservation::lockForUpdate()->findOrFail($r->id);
            $r->assertStatus('confirmed', 'tentative');
            $fee = $r->ratePlan->cancellation_penalty === 'none' ? 0 : (int) ($r->nights()->first()?->amount ?? 0);
            $this->inventory->release($r->hotel_id, $r->room_type_id, $r->arrival, $r->departure);
            $r->update(['status' => 'no_show', 'cancellation_fee' => $fee, 'room_id' => null]);
            $this->bus->record('reservation.no_show', $this->payload($r) + ['fee' => $fee], 'reservation', $r->id);

            return $r;
        });
    }

    public function assignRoom(Reservation $r, int $roomId): Reservation
    {
        $room = Room::findOrFail($roomId);
        if ($room->room_type_id !== $r->room_type_id) {
            throw new DomainException("Room {$room->number} is not of the booked room type. Change the room type first (upgrade).");
        }
        if (! $room->isSellable()) {
            throw new DomainException("Room {$room->number} is {$room->hk_status}.");
        }
        $clash = Reservation::where('room_id', $room->id)->where('id', '!=', $r->id)
            ->whereIn('status', Reservation::ACTIVE)
            ->where('arrival', '<', $r->departure)->where('departure', '>', $r->arrival)->first();
        if ($clash) {
            throw new DomainException("Room {$room->number} is already assigned to reservation {$clash->number} for these dates.", 'room-conflict', 409);
        }
        $r->update(['room_id' => $room->id]);
        $this->bus->record('reservation.room_assigned', ['reservation_id' => $r->id, 'room_id' => $room->id, 'room_number' => $room->number], 'reservation', $r->id);

        return $r;
    }

    public function cancellationFee(Reservation $r): int
    {
        $plan = $r->ratePlan;
        $hoursBefore = now()->diffInHours($r->arrival->copy()->setTimeFromTimeString('14:00'), false);
        if ($plan->refundable && $hoursBefore >= $plan->cancellation_hours) {
            return 0;
        }

        return match ($plan->refundable ? $plan->cancellation_penalty : 'full') {
            'none' => 0,
            'first_night' => (int) ($r->nights()->first()?->amount ?? 0),
            'percent' => (int) round($r->total_amount * $plan->penalty_percent / 100),
            default => (int) $r->total_amount,
        };
    }

    private function pickUpFromBlock(RoomBlock $block, int $roomTypeId, Carbon $arrival, Carbon $departure): void
    {
        if (! in_array($block->status, ['tentative', 'definite'], true)) {
            throw new DomainException("Block {$block->code} is {$block->status}.");
        }
        foreach ($this->inventory->nights($arrival, $departure) as $d) {
            $alloc = $block->allocations()->where('room_type_id', $roomTypeId)->where('date', $d)->lockForUpdate()->first();
            if (! $alloc || $alloc->picked_up >= $alloc->rooms) {
                throw new DomainException("Block {$block->code} has no rooms of this type left on $d.", 'not-available', 409);
            }
            $alloc->increment('picked_up');
        }
    }

    public function payload(Reservation $r): array
    {
        return [
            'id' => $r->id, 'number' => $r->number, 'booking_ref' => $r->booking_ref, 'status' => $r->status,
            'guest_id' => $r->guest_id, 'room_type_id' => $r->room_type_id, 'rate_plan_id' => $r->rate_plan_id,
            'room_id' => $r->room_id, 'arrival' => $r->arrival->toDateString(), 'departure' => $r->departure->toDateString(),
            'adults' => $r->adults, 'children' => $r->children, 'channel' => $r->channel, 'external_ref' => $r->external_ref,
            'currency' => $r->currency, 'total_amount' => $r->total_amount, 'company_id' => $r->company_id,
        ];
    }
}
