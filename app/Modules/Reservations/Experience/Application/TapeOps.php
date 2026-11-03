<?php

namespace App\Modules\Reservations\Experience\Application;

use App\Modules\Property\Domain\Room;
use App\Modules\Reservations\Application\Inventory;
use App\Modules\Reservations\Application\ReservationService;
use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Reservations\Domain\ReservationNight;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Domain\DomainException;
use App\Platform\Domain\NumberSequence;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Tape chart operations: move to another room, extend or shorten a stay, split a stay across two rooms and swap two rooms.
 * Every operation checks the target room for clashes (other stays, out-of-order work orders, housekeeping out of order)
 * and fails with a message that names the blocking stay, so the drag can be refused with a clear reason.
 * check() runs an operation and rolls it back, for a "can I drop here?" preview.
 */
class TapeOps
{
    public function __construct(private ReservationService $reservations, private Inventory $inventory, private EventBus $bus, private TenantContext $ctx) {}

    /** @return string[] human-readable reasons the room cannot host [from, to) */
    public function conflicts(Room $room, Carbon $from, Carbon $to, array $exclude = []): array
    {
        $out = [];
        if (! $room->isSellable()) {
            $out[] = "Room {$room->number} is ".str_replace('_', ' ', (string) $room->hk_status).'.';
        }
        $clashes = Reservation::with('guest:id,first_name,last_name')->where('room_id', $room->id)->whereNotIn('id', $exclude)
            ->whereIn('status', Reservation::ACTIVE)->where('arrival', '<', $to->toDateString())->where('departure', '>', $from->toDateString())->get();
        foreach ($clashes as $c) {
            $out[] = "Room {$room->number} is taken by {$c->number} ({$c->guest?->last_name}) from {$c->arrival->toDateString()} to {$c->departure->toDateString()}.";
        }
        $ooo = DB::table('work_orders')->where('hotel_id', $room->hotel_id)->where('room_id', $room->id)->where('room_out_of_order', true)
            ->whereNotIn('status', ['done', 'cancelled'])->where('created_at', '<', $to)
            ->where(fn ($q) => $q->whereNull('due_at')->orWhere('due_at', '>=', $from))->first(['number', 'title']);
        if ($ooo) {
            $out[] = "Room {$room->number} is out of order (work order {$ooo->number}).";
        }

        return $out;
    }

    private function refuse(array $reasons): never
    {
        throw new DomainException(implode(' ', $reasons), 'room-conflict', 409);
    }

    private function movable(Reservation $r, string $verb): void
    {
        if (! in_array($r->status, ['tentative', 'confirmed'], true)) {
            throw new DomainException("Reservation {$r->number} is {$r->status}; ".($r->status === 'checked_in' ? "use the front desk room move to $verb an in-house guest." : "it cannot be $verb."), 'invalid-state');
        }
    }

    public function move(Reservation $r, int $roomId): Reservation
    {
        return DB::transaction(function () use ($r, $roomId) {
            $r = Reservation::lockForUpdate()->findOrFail($r->id);
            $this->movable($r, 'moved');
            $room = Room::findOrFail($roomId);
            if ($room->room_type_id !== $r->room_type_id) {
                throw new DomainException("Room {$room->number} is not of the booked room type. Offer an upgrade instead of moving.", 'room-type-mismatch', 422);
            }
            if ($bad = $this->conflicts($room, $r->arrival, $r->departure, [$r->id])) {
                $this->refuse($bad);
            }
            $this->reservations->assignRoom($r, $room->id);
            $this->bus->record('reservation.room_moved', ['reservation_id' => $r->id, 'room_id' => $room->id, 'room_number' => $room->number], 'reservation', $r->id);

            return $r->refresh()->load('room');
        });
    }

    /** Change arrival and/or departure (arrival only before check-in). Re-prices through ReservationService::modify. */
    public function resize(Reservation $r, ?string $arrival, ?string $departure): Reservation
    {
        return DB::transaction(function () use ($r, $arrival, $departure) {
            $r = Reservation::lockForUpdate()->findOrFail($r->id);
            if (! in_array($r->status, ['tentative', 'confirmed', 'checked_in'], true)) {
                throw new DomainException("Reservation {$r->number} is {$r->status}; its dates cannot change.", 'invalid-state');
            }
            $from = Carbon::parse($arrival ?? $r->arrival)->startOfDay();
            $to = Carbon::parse($departure ?? $r->departure)->startOfDay();
            if ($to->lte($from)) {
                throw new DomainException('A stay needs at least one night.', 'invalid-dates', 422);
            }
            if ($r->status === 'checked_in' && ! $from->equalTo($r->arrival)) {
                throw new DomainException('The arrival date cannot change once the guest is in-house.', 'invalid-dates', 422);
            }
            if ($r->room_id && ! ($from->equalTo($r->arrival) && $to->equalTo($r->departure))) {
                if ($bad = $this->conflicts(Room::findOrFail($r->room_id), $from, $to, [$r->id])) {
                    $this->refuse($bad);
                }
            }
            $posted = $r->nights()->where('posted', true)->where(fn ($q) => $q->where('date', '<', $from->toDateString())->orWhere('date', '>=', $to->toDateString()))->exists();
            if ($posted) {
                throw new DomainException('Nights already charged to the folio cannot be shortened away.', 'nights-posted', 409);
            }

            return $this->reservations->modify($r, ['arrival' => $from->toDateString(), 'departure' => $to->toDateString(), 'enforce_restrictions' => false]);
        });
    }

    /** Guest stays in the current room until $splitDate, then moves to $roomId. Returns [first part, second part]. */
    public function split(Reservation $r, string $splitDate, int $roomId): array
    {
        return DB::transaction(function () use ($r, $splitDate, $roomId) {
            $r = Reservation::lockForUpdate()->findOrFail($r->id);
            if (! in_array($r->status, ['tentative', 'confirmed', 'checked_in'], true)) {
                throw new DomainException("Reservation {$r->number} is {$r->status} and cannot be split.", 'invalid-state');
            }
            $d = Carbon::parse($splitDate)->startOfDay();
            if ($d->lte($r->arrival) || $d->gte($r->departure)) {
                throw new DomainException("The split date must fall inside the stay ({$r->arrival->toDateString()} to {$r->departure->toDateString()}), after the first night.", 'invalid-dates', 422);
            }
            if ($r->status === 'checked_in') {
                $today = Hotel::findOrFail($r->hotel_id)->business_date->copy()->startOfDay();
                if ($d->lte($today)) {
                    throw new DomainException('For an in-house guest the split date must be after today; use the front desk room move to change room now.', 'invalid-dates', 422);
                }
            }
            $room = Room::findOrFail($roomId);
            if ($room->id === $r->room_id) {
                throw new DomainException('Pick a different room for the second part of the stay.', 'same-room', 422);
            }
            if ($bad = $this->conflicts($room, $d, $r->departure, [$r->id])) {
                $this->refuse($bad);
            }
            $moving = ReservationNight::where('reservation_id', $r->id)->where('date', '>=', $d->toDateString())->orderBy('date')->get();
            if ($moving->contains('posted', true)) {
                throw new DomainException('Some nights after the split date are already charged to the folio.', 'nights-posted', 409);
            }
            if ($room->room_type_id !== $r->room_type_id) {
                $this->inventory->release($r->hotel_id, $r->room_type_id, $d, $r->departure);
                $this->inventory->take($r->hotel_id, $room->room_type_id, $d, $r->departure);
            }

            $child = $r->replicate(['number', 'room_id', 'room_type_id', 'arrival', 'total_amount', 'deposit_required', 'deposit_paid', 'deposit_deadline', 'deposit_reminded_at', 'status', 'checked_in_at', 'checked_out_at']);
            $child->fill([
                'number' => NumberSequence::next($r->hotel_id, 'reservation', 'R'), 'status' => $r->status === 'checked_in' ? 'confirmed' : $r->status,
                'room_id' => $room->id, 'room_type_id' => $room->room_type_id, 'arrival' => $d, 'departure' => $r->departure,
                'total_amount' => (int) $moving->sum('amount'), 'deposit_required' => 0, 'deposit_paid' => 0, 'split_from_id' => $r->id,
                'created_by' => $this->ctx->actor()?->id,
            ]);
            $child->save();
            ReservationNight::where('reservation_id', $r->id)->where('date', '>=', $d->toDateString())->update(['reservation_id' => $child->id]);
            $r->update(['departure' => $d, 'total_amount' => (int) ReservationNight::where('reservation_id', $r->id)->sum('amount')]);

            $this->bus->record('reservation.split', ['reservation_id' => $r->id, 'new_reservation_id' => $child->id, 'split_date' => $d->toDateString(), 'room_number' => $room->number], 'reservation', $r->id);
            $this->bus->record('reservation.modified', $this->reservations->payload($r->refresh()) + ['before' => []], 'reservation', $r->id);

            return [$r->load('nights'), $child->load('nights')];
        });
    }

    /** Exchange the rooms of two upcoming stays. Each room must suit the other stay's booked type and be free for its dates. */
    public function swap(Reservation $a, Reservation $b): array
    {
        return DB::transaction(function () use ($a, $b) {
            if ($a->id === $b->id) {
                throw new DomainException('Pick two different reservations to swap.', 'same-reservation', 422);
            }
            $a = Reservation::lockForUpdate()->findOrFail($a->id);
            $b = Reservation::lockForUpdate()->findOrFail($b->id);
            $this->movable($a, 'swapped');
            $this->movable($b, 'swapped');
            if (! $a->room_id || ! $b->room_id) {
                throw new DomainException('Both reservations need a room to swap; drop the stay on a free room instead.', 'room-required', 422);
            }
            $roomA = Room::findOrFail($a->room_id);
            $roomB = Room::findOrFail($b->room_id);
            if ($roomA->room_type_id !== $b->room_type_id || $roomB->room_type_id !== $a->room_type_id) {
                throw new DomainException("Rooms {$roomA->number} and {$roomB->number} are of different types; a swap needs the same booked type (use upgrade offers otherwise).", 'room-type-mismatch', 422);
            }
            $bad = array_merge(
                $this->conflicts($roomB, $a->arrival, $a->departure, [$a->id, $b->id]),
                $this->conflicts($roomA, $b->arrival, $b->departure, [$a->id, $b->id]),
            );
            if ($bad) {
                $this->refuse($bad);
            }
            $a->update(['room_id' => $roomB->id]);
            $b->update(['room_id' => $roomA->id]);
            $this->bus->record('reservation.room_swapped', ['a' => $a->id, 'b' => $b->id, 'a_room' => $roomB->number, 'b_room' => $roomA->number], 'reservation', $a->id);

            return [$a->refresh(), $b->refresh()];
        });
    }

    /** Dry run: apply an operation, then roll it back. @return array{ok:bool, error?:string, code?:string} */
    public function check(callable $operation): array
    {
        DB::beginTransaction();
        try {
            $operation();

            return ['ok' => true];
        } catch (DomainException $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'code' => $e->problem];
        } finally {
            DB::rollBack();
        }
    }
}
