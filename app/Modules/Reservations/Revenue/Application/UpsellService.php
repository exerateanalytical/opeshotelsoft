<?php

namespace App\Modules\Reservations\Revenue\Application;

use App\Models\User;
use App\Modules\FrontOffice\Application\FolioService;
use App\Modules\Property\Domain\RoomType;
use App\Modules\Reservations\Application\Inventory;
use App\Modules\Reservations\Application\Pricing;
use App\Modules\Reservations\Application\ReservationService;
use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Reservations\Revenue\Domain\UpsellOffer;
use App\Platform\Domain\DomainException;
use App\Platform\Events\EventBus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Room-upgrade offers at booking, pre-arrival and check-in. The price is the per-night
 * difference between the booked room type and the offered one on the same rate plan,
 * less the stage discount from the revenue settings.
 *
 * Posting rules on acceptance:
 *  - not yet arrived: every night of the reservation goes up by the offer price (night audit posts it);
 *  - checked in: the remaining nights are charged on the guest folio now (ROOM, source "upsell") and the
 *    posted/unposted night amounts stay as they are, so the audit cannot charge the difference twice.
 * In both cases reservation.total_amount increases by the offer total and inventory moves to the new type.
 */
class UpsellService
{
    public const STAGES = ['booking', 'pre_arrival', 'check_in'];

    public function __construct(
        private Pricing $pricing, private Inventory $inventory, private RevenueConfig $config,
        private ReservationService $reservations, private FolioService $folios, private EventBus $bus,
    ) {}

    /**
     * Create (or refresh) the open offers of a stage. Older open offers of the reservation are expired.
     *
     * @return Collection<int, UpsellOffer>
     */
    public function offer(Reservation $r, string $stage, string $channel = 'staff'): Collection
    {
        if (! in_array($stage, self::STAGES, true)) {
            throw new DomainException('Unknown upsell stage.');
        }
        $allowed = $stage === 'check_in' ? ['confirmed', 'checked_in'] : ['tentative', 'confirmed'];
        if (! in_array($r->status, $allowed, true)) {
            throw new DomainException("No upgrade can be offered while the reservation is {$r->status}.");
        }

        return DB::transaction(function () use ($r, $stage, $channel) {
            UpsellOffer::where('reservation_id', $r->id)->where('status', 'offered')->update(['status' => 'expired']);

            $discount = (float) ($this->config->get()->upsell_discount_pct[$stage] ?? 0);
            $from = RoomType::findOrFail($r->room_type_id);
            $dates = $this->coveredDates($r);
            if (! $dates) {
                return collect();
            }
            $first = Carbon::parse($dates[0]);
            $last = Carbon::parse(end($dates))->addDay();

            $out = collect();
            foreach (RoomType::where('active', true)->where('base_rate', '>', $from->base_rate)->orderBy('base_rate')->get() as $to) {
                if ($r->adults > $to->max_adults || $r->children > $to->max_children) {
                    continue;
                }
                if ($this->minAvailable($to->id, $first, $last) < 1) {
                    continue;
                }
                try {
                    $plan = $r->ratePlan;
                    $qFrom = collect($this->pricing->quote($plan, $from, $first, $last, $r->adults, $r->children, false)['nights'])->pluck('amount', 'date');
                    $qTo = collect($this->pricing->quote($plan, $to, $first, $last, $r->adults, $r->children, false)['nights'])->pluck('amount', 'date');
                } catch (DomainException) {
                    continue; // no rate loaded for the higher room type on these nights
                }
                $diff = collect($dates)->sum(fn ($d) => max(0, ($qTo[$d] ?? 0) - ($qFrom[$d] ?? 0)));
                $perNight = (int) floor($diff / count($dates) * (1 - $discount / 100));
                if ($perNight < 1) {
                    continue;
                }
                $out->push(UpsellOffer::create([
                    'reservation_id' => $r->id, 'from_room_type_id' => $from->id, 'to_room_type_id' => $to->id, 'stage' => $stage,
                    'price_per_night' => $perNight, 'nights' => count($dates), 'total' => $perNight * count($dates), 'channel' => $channel,
                ]));
            }
            $this->bus->record('upsell.offered', ['reservation_id' => $r->id, 'stage' => $stage, 'offers' => $out->count()], 'reservation', $r->id);

            return $out;
        });
    }

    /** Pre-arrival run: offers for every confirmed arrival in `$daysAhead` days. @return int reservations offered to */
    public function offerPreArrival(int $daysAhead = 3): int
    {
        $n = 0;
        foreach (Reservation::where('status', 'confirmed')->whereDate('arrival', now()->addDays($daysAhead)->toDateString())->get() as $r) {
            if ($this->offer($r, 'pre_arrival', 'system')->isNotEmpty()) {
                $n++;
            }
        }

        return $n;
    }

    public function accept(UpsellOffer $offer, ?User $by = null, ?int $roomId = null): UpsellOffer
    {
        return DB::transaction(function () use ($offer, $by, $roomId) {
            $offer = UpsellOffer::lockForUpdate()->findOrFail($offer->id);
            if ($offer->status !== 'offered') {
                throw new DomainException("This offer is already {$offer->status}.", 'already-decided', 409);
            }
            $r = Reservation::lockForUpdate()->findOrFail($offer->reservation_id);
            if (! in_array($r->status, ['tentative', 'confirmed', 'checked_in'], true) || $r->room_type_id !== $offer->from_room_type_id) {
                $offer->update(['status' => 'expired']);
                throw new DomainException('This offer is no longer valid for the reservation.', 'offer-expired', 409);
            }
            $checkedIn = $r->status === 'checked_in';
            if ($checkedIn && ! $roomId) {
                throw new DomainException('Choose the new room for the checked-in guest.');
            }

            $dates = $this->coveredDates($r);
            if (count($dates) !== $offer->nights) {
                $offer->update(['status' => 'expired']);
                throw new DomainException('The stay changed since the offer was made. Make a new offer.', 'offer-expired', 409);
            }
            $first = Carbon::parse($dates[0]);
            $last = Carbon::parse(end($dates))->addDay();

            $this->inventory->release($r->hotel_id, $offer->from_room_type_id, $first, $last);
            $this->inventory->take($r->hotel_id, $offer->to_room_type_id, $first, $last); // throws not-available (409) if sold meanwhile

            $before = ['room_type_id' => $r->room_type_id, 'room_id' => $r->room_id, 'total_amount' => $r->total_amount];
            $r->update(['room_type_id' => $offer->to_room_type_id, 'room_id' => $checkedIn ? $r->room_id : null, 'total_amount' => $r->total_amount + $offer->total]);

            $line = null;
            if ($checkedIn) {
                $r->update(['room_id' => null]);
                $this->reservations->assignRoom($r->refresh(), $roomId);
                $line = $this->folios->charge($this->folios->guestFolioFor($r), 'ROOM', $offer->price_per_night, [
                    'quantity' => $offer->nights, 'nights' => $offer->nights, 'description' => 'Room upgrade ('.$offer->nights.' nights)',
                    'source_type' => 'upsell', 'source_id' => $offer->id,
                ]);
            } else {
                foreach ($r->nights()->whereIn('date', $dates)->get() as $night) {
                    $night->update(['amount' => $night->amount + $offer->price_per_night]);
                }
                if ($roomId) {
                    $this->reservations->assignRoom($r->refresh(), $roomId);
                }
            }

            $offer->update(['status' => 'accepted', 'decided_at' => now(), 'decided_by' => $by?->id, 'folio_line_id' => $line?->id]);
            UpsellOffer::where('reservation_id', $r->id)->where('status', 'offered')->update(['status' => 'expired']);
            $this->bus->record('reservation.upgraded', $this->reservations->payload($r->refresh()) + [
                'offer_id' => $offer->id, 'stage' => $offer->stage, 'from_room_type_id' => $offer->from_room_type_id,
                'to_room_type_id' => $offer->to_room_type_id, 'price_per_night' => $offer->price_per_night, 'total' => $offer->total,
                'before' => $before, 'folio_line_id' => $line?->id,
            ], 'reservation', $r->id);

            return $offer->refresh();
        });
    }

    public function decline(UpsellOffer $offer, ?User $by = null): UpsellOffer
    {
        if ($offer->status !== 'offered') {
            throw new DomainException("This offer is already {$offer->status}.", 'already-decided', 409);
        }
        $offer->update(['status' => 'declined', 'decided_at' => now(), 'decided_by' => $by?->id]);
        $this->bus->record('upsell.declined', ['offer_id' => $offer->id, 'reservation_id' => $offer->reservation_id], 'reservation', $offer->reservation_id);

        return $offer;
    }

    /** Nights an upgrade would cover: unposted nights from today on (checked in) or every night (not yet arrived). @return string[] */
    private function coveredDates(Reservation $r): array
    {
        $q = $r->nights()->orderBy('date');
        if ($r->status === 'checked_in') {
            $q->where('posted', false)->where('date', '>=', now()->toDateString());
        }

        return $q->get()->map(fn ($n) => $n->date->toDateString())->all();
    }

    private function minAvailable(int $typeId, Carbon $from, Carbon $to): int
    {
        $grid = $this->inventory->grid(0, $typeId, $from->toDateString(), $to->copy()->subDay()->toDateString());

        return min(array_column($grid, 'available') ?: [0]);
    }
}
