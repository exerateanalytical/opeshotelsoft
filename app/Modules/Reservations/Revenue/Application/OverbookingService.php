<?php

namespace App\Modules\Reservations\Revenue\Application;

use App\Models\User;
use App\Modules\Property\Domain\RoomType;
use App\Modules\Reservations\Application\Inventory;
use App\Modules\Reservations\Application\ReservationService;
use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Reservations\Revenue\Domain\OverbookingPolicy;
use App\Modules\Reservations\Revenue\Domain\WalkRecord;
use App\Platform\Domain\DomainException;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

/**
 * Overbooking limits per room type and the walk policy.
 * The limit written to room_type_inventory.overbooking_limit (already honoured by Inventory::take)
 * can never exceed the policy cap: min(max_rooms, floor(rooms x max_percent / 100)) over the caps that are set.
 * No policy (or both caps 0) means no overbooking.
 */
class OverbookingService
{
    public function __construct(
        private Inventory $inventory, private ReservationService $reservations, private RevenueConfig $config,
        private EventBus $bus, private TenantContext $ctx,
    ) {}

    public function cap(int $roomTypeId): int
    {
        $p = OverbookingPolicy::where('room_type_id', $roomTypeId)->first();
        if (! $p) {
            return 0;
        }
        $caps = [];
        if ($p->max_rooms > 0) {
            $caps[] = (int) $p->max_rooms;
        }
        if ($p->max_percent > 0) {
            $caps[] = (int) floor($this->inventory->capacity($roomTypeId) * $p->max_percent / 100);
        }

        return $caps ? min($caps) : 0;
    }

    /** Set the limit on a date range; returns the limit actually applied (clamped to the cap). */
    public function apply(int $roomTypeId, string $from, string $to, int $limit): int
    {
        RoomType::findOrFail($roomTypeId);
        $applied = max(0, min($limit, $this->cap($roomTypeId)));
        $hotelId = $this->ctx->requireHotelId();
        DB::transaction(function () use ($roomTypeId, $from, $to, $applied, $hotelId) {
            foreach (CarbonPeriod::create($from, $to) as $day) {
                $this->inventory->set($hotelId, $roomTypeId, $day->toDateString(), ['overbooking_limit' => $applied]);
            }
        });
        $this->bus->record('revenue.overbooking.applied', ['room_type_id' => $roomTypeId, 'from' => $from, 'to' => $to, 'limit' => $applied, 'requested' => $limit]);

        return $applied;
    }

    /** Dates where bookings exceed physical rooms: these guests may have to be walked. */
    public function exposure(string $from, string $to): array
    {
        $hotelId = $this->ctx->requireHotelId();
        $out = [];
        foreach (RoomType::where('active', true)->orderBy('sort')->get() as $type) {
            foreach ($this->inventory->grid($hotelId, $type->id, $from, $to) as $g) {
                $rooms = $g['capacity'] - $g['out_of_order'];
                $over = $g['sold'] + $g['blocked'] - $rooms;
                if ($over > 0) {
                    $out[] = ['room_type_id' => $type->id, 'room_type' => $type->name, 'date' => $g['date'], 'rooms' => $rooms, 'booked' => $g['sold'] + $g['blocked'], 'over' => $over];
                }
            }
        }

        return $out;
    }

    /**
     * Walk a guest to a partner hotel per the documented policy: the reservation is cancelled without a fee
     * (inventory released) and the walk, compensation and partner are recorded.
     */
    public function walk(Reservation $r, ?User $by = null, ?string $partner = null, ?int $compensation = null, ?string $note = null): WalkRecord
    {
        $s = $this->config->get();

        return DB::transaction(function () use ($r, $by, $partner, $compensation, $note, $s) {
            if ($r->status !== 'confirmed') {
                throw new DomainException('Only a confirmed arrival can be walked.');
            }
            $this->reservations->cancel($r, 'Walked: no room available', waiveFee: true);
            $walk = WalkRecord::create([
                'reservation_id' => $r->id, 'partner_hotel' => $partner ?? $s->walk_partner_hotel,
                'compensation' => $compensation ?? $s->walk_compensation, 'note' => $note, 'walked_by' => $by?->id,
            ]);
            $this->bus->record('reservation.walked', ['reservation_id' => $r->id, 'partner_hotel' => $walk->partner_hotel, 'compensation' => $walk->compensation], 'reservation', $r->id);

            return $walk;
        });
    }
}
