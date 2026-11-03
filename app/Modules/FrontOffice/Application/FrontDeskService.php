<?php

namespace App\Modules\FrontOffice\Application;

use App\Modules\FrontOffice\Domain\Folio;
use App\Modules\FrontOffice\Domain\GuestRegisterEntry;
use App\Modules\Guests\Domain\Company;
use App\Modules\Property\Domain\Room;
use App\Modules\Reservations\Application\ReservationService;
use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Domain\DomainException;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class FrontDeskService
{
    public function __construct(
        private ReservationService $reservations,
        private FolioService $folios,
        private EventBus $bus,
        private TenantContext $ctx,
    ) {}

    /**
     * @param  array{room_id?:int, guest?:array, coming_from?:string, going_to?:string, purpose?:string, allow_dirty?:bool}  $data
     */
    public function checkIn(Reservation $r, array $data = []): Reservation
    {
        return DB::transaction(function () use ($r, $data) {
            $r = Reservation::lockForUpdate()->findOrFail($r->id);
            $r->assertStatus('confirmed', 'tentative');
            $hotel = Hotel::findOrFail($r->hotel_id);
            if ($r->arrival->gt($hotel->business_date)) {
                throw new DomainException("Arrival is {$r->arrival->toDateString()}; the hotel business date is {$hotel->business_date->toDateString()}. Use early check-in by changing the arrival date.");
            }

            if (! empty($data['room_id']) && $data['room_id'] !== $r->room_id) {
                $this->reservations->assignRoom($r, $data['room_id']);
                $r->refresh();
            }
            if (! $r->room_id) {
                throw new DomainException('Assign a room before check-in.', 'room-required');
            }
            $room = Room::lockForUpdate()->findOrFail($r->room_id);
            if ($room->occupied) {
                throw new DomainException("Room {$room->number} is still occupied.", 'room-occupied', 409);
            }
            if (! ($data['allow_dirty'] ?? false) && ! $room->isReadyForArrival()) {
                throw new DomainException("Room {$room->number} is {$room->hk_status}. Choose a clean room or confirm with allow_dirty.", 'room-not-ready');
            }

            $guest = $r->guest;
            if (! empty($data['guest'])) {
                $guest->update(array_intersect_key($data['guest'], array_flip([
                    'nationality', 'date_of_birth', 'id_type', 'id_number', 'id_expiry', 'id_issued_in', 'phone', 'email', 'address', 'profession',
                ])));
            }
            if (! $guest->id_number && ($hotel->settings['require_id_at_checkin'] ?? true)) {
                throw new DomainException('Guest ID document is required for the police register.', 'id-required');
            }

            $r->update(['status' => 'checked_in', 'checked_in_at' => now()]);
            $room->update(['occupied' => true, 'hk_status' => 'occupied_clean']);

            $folio = $this->folios->guestFolioFor($r);
            if ($r->company_id && $r->guarantee === 'company') {
                $folio->update(['company_id' => $r->company_id]);
            }

            GuestRegisterEntry::create([
                'reservation_id' => $r->id, 'guest_id' => $guest->id, 'room_number' => $room->number,
                'full_name' => $guest->full_name, 'nationality' => $guest->nationality, 'date_of_birth' => $guest->date_of_birth,
                'profession' => $guest->profession, 'id_type' => $guest->id_type, 'id_number' => $guest->id_number,
                'id_expiry' => $guest->id_expiry, 'coming_from' => $data['coming_from'] ?? null, 'going_to' => $data['going_to'] ?? null,
                'purpose' => $data['purpose'] ?? null, 'arrival' => $r->arrival, 'departure' => $r->departure,
            ]);

            $this->bus->record('frontoffice.guest_checked_in', $this->reservations->payload($r) + [
                'room_number' => $room->number, 'folio_id' => $folio->id,
            ], 'reservation', $r->id);

            return $r->load('room', 'guest', 'roomType');
        });
    }

    public function checkOut(Reservation $r, array $data = []): Reservation
    {
        return DB::transaction(function () use ($r, $data) {
            $r = Reservation::lockForUpdate()->findOrFail($r->id);
            $r->assertStatus('checked_in');
            $hotel = Hotel::findOrFail($r->hotel_id);

            if (! ($data['waive_fee'] ?? false)) {
                $quote = app(CheckoutFees::class)->quote($r);
                if ($quote['fee'] && ! $quote['fee_posted']) {
                    throw new DomainException("A {$quote['fee']['label']} fee of {$quote['fee']['amount']} applies. Post it or waive it before check-out.", 'checkout-fee-pending');
                }
            }

            $folios = Folio::where('reservation_id', $r->id)->where('status', 'open')->get();
            foreach ($folios as $folio) {
                if (! empty($data['city_ledger_company_id']) && $folio->balance > 0) {
                    $this->folios->transferToCityLedger($folio, Company::findOrFail($data['city_ledger_company_id']));
                }
                $this->folios->close($folio);
            }

            $early = $r->departure->gt($hotel->business_date);
            if ($early) {
                // Release unused future nights back to inventory.
                $this->reservations->modify($r, ['departure' => $hotel->business_date->copy()->addDay()->toDateString(), 'enforce_restrictions' => false]);
                $r->refresh();
            }

            $r->update(['status' => 'checked_out', 'checked_out_at' => now()]);
            $room = Room::findOrFail($r->room_id);
            $room->update(['occupied' => false, 'hk_status' => 'vacant_dirty']);
            GuestRegisterEntry::where('reservation_id', $r->id)->update(['checked_out_at' => now(), 'departure' => $hotel->business_date]);

            $this->bus->record('frontoffice.guest_checked_out', $this->reservations->payload($r) + [
                'room_number' => $room->number, 'early' => $early,
            ], 'reservation', $r->id);

            return $r->load('room', 'guest');
        });
    }

    public function moveRoom(Reservation $r, int $toRoomId, string $reason): Reservation
    {
        return DB::transaction(function () use ($r, $toRoomId, $reason) {
            $r = Reservation::lockForUpdate()->findOrFail($r->id);
            $r->assertStatus('checked_in');
            $from = Room::lockForUpdate()->findOrFail($r->room_id);
            $to = Room::lockForUpdate()->findOrFail($toRoomId);
            if ($to->occupied || ! $to->isSellable()) {
                throw new DomainException("Room {$to->number} is not available.", 'room-not-ready');
            }
            if ($to->room_type_id !== $r->room_type_id) {
                // Upgrade/downgrade keeps the booked rate unless re-rated explicitly.
                $r->forceFill(['room_type_id' => $to->room_type_id])->save();
            }
            $r->update(['room_id' => $to->id]);
            $from->update(['occupied' => false, 'hk_status' => 'vacant_dirty']);
            $to->update(['occupied' => true, 'hk_status' => 'occupied_clean']);
            GuestRegisterEntry::where('reservation_id', $r->id)->update(['room_number' => $to->number]);
            $this->bus->record('frontoffice.room_moved', ['reservation_id' => $r->id, 'from_room' => $from->number, 'to_room' => $to->number, 'reason' => $reason], 'reservation', $r->id);

            return $r->load('room');
        });
    }

    /** Walk-in: create a same-day reservation and check in at once. */
    public function walkIn(array $data): Reservation
    {
        return DB::transaction(function () use ($data) {
            $hotel = Hotel::findOrFail($this->ctx->requireHotelId());
            $r = $this->reservations->create([
                'guest_id' => $data['guest_id'], 'room_type_id' => $data['room_type_id'], 'rate_plan_id' => $data['rate_plan_id'],
                'arrival' => $hotel->business_date->toDateString(),
                'departure' => $hotel->business_date->copy()->addDays($data['nights'])->toDateString(),
                'adults' => $data['adults'] ?? 1, 'children' => $data['children'] ?? 0,
                'channel' => 'walk_in', 'room_id' => $data['room_id'],
            ]);

            return $this->checkIn($r, $data);
        });
    }
}
