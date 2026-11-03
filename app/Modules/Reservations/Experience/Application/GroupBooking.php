<?php

namespace App\Modules\Reservations\Experience\Application;

use App\Modules\FrontOffice\Application\FolioService;
use App\Modules\FrontOffice\Domain\Folio;
use App\Modules\FrontOffice\Domain\FolioRoutingRule;
use App\Modules\Guests\Domain\Guest;
use App\Modules\Reservations\Application\ReservationService;
use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Reservations\Experience\Domain\ExperienceSetting;
use App\Modules\Reservations\Revenue\Application\BookingQuoter;
use App\Platform\Domain\DomainException;
use App\Platform\Events\EventBus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Several rooms and several guests in one flow: one booking reference, one shared payer, all rooms or none.
 * Each room is priced through BookingQuoter (corporate and promo rules); when route_to_payer is on, a master folio
 * for the payer is opened and every room's charges are routed to it at check-in.
 */
class GroupBooking
{
    public function __construct(private ReservationService $reservations, private BookingQuoter $quoter, private FolioService $folios, private EventBus $bus) {}

    /**
     * @param  array{payer_guest_id?:int, payer?:array, arrival:string, departure:string, rooms:array<int,array>, corporate_code?:string, channel?:string, route_to_payer?:bool, notes?:string}  $d
     */
    public function book(array $d): array
    {
        return DB::transaction(function () use ($d) {
            $payer = $this->guest($d['payer_guest_id'] ?? null, $d['payer'] ?? null)
                ?? throw new DomainException('A payer is required for a group booking.', 'payer-required');
            $ref = strtoupper(Str::random(8));
            $settings = ExperienceSetting::forCurrentHotel();
            $created = [];

            foreach ($d['rooms'] as $i => $room) {
                $guest = $this->guest($room['guest_id'] ?? null, $room['guest'] ?? null) ?? $payer;
                $in = [
                    'room_type_id' => $room['room_type_id'], 'rate_plan_id' => $room['rate_plan_id'],
                    'arrival' => $room['arrival'] ?? $d['arrival'], 'departure' => $room['departure'] ?? $d['departure'],
                    'adults' => $room['adults'] ?? 1, 'children' => $room['children'] ?? 0,
                    'corporate_code' => $room['corporate_code'] ?? $d['corporate_code'] ?? null, 'promo_code' => $room['promo_code'] ?? null,
                ];
                try {
                    $quote = $this->quoter->quote($in);
                    $r = $this->reservations->create(array_merge([
                        'guest_id' => $guest->id, 'arrival' => $in['arrival'], 'departure' => $in['departure'],
                        'adults' => $in['adults'], 'children' => $in['children'], 'booking_ref' => $ref, 'channel' => $d['channel'] ?? 'direct',
                        'special_requests' => $room['special_requests'] ?? null, 'notes' => $d['notes'] ?? null,
                    ], $quote['fields'], ['room_type_id' => $in['room_type_id']]));
                } catch (DomainException $e) {
                    throw new DomainException('Room '.($i + 1).': '.$e->getMessage(), $e->problem, $e->status);
                }
                $updates = ['payer_guest_id' => $payer->id, 'room_prefs' => $room['room_prefs'] ?? null];
                if ($r->deposit_required > 0) {
                    $updates += ['status' => 'tentative', 'guarantee' => 'deposit', 'deposit_deadline' => now()->addHours($settings->deposit_deadline_hours)];
                }
                $r->update($updates);
                $created[] = $r->refresh();
            }

            $master = null;
            if ($d['route_to_payer'] ?? true) {
                $master = $this->folios->open('master', "Booking $ref / ".$payer->full_name, $created[0], $payer->id);
                foreach ($created as $r) {
                    FolioRoutingRule::create(['reservation_id' => $r->id, 'match_type' => 'all', 'target_folio_id' => $master->id, 'created_by' => auth()->id()]);
                }
            }
            $this->bus->record('reservation.group_booked', ['booking_ref' => $ref, 'payer_guest_id' => $payer->id, 'reservation_ids' => collect($created)->pluck('id')->all(), 'master_folio_id' => $master?->id], 'reservation', $created[0]->id);

            return $this->summary($ref);
        });
    }

    public function summary(string $ref): array
    {
        $rows = Reservation::with('guest:id,first_name,last_name,email', 'roomType:id,code,name', 'room:id,number')->where('booking_ref', $ref)->orderBy('id')->get();
        abort_if($rows->isEmpty(), 404, 'Booking not found.');
        $payer = Guest::find($rows[0]->payer_guest_id ?? $rows[0]->guest_id);

        return [
            'booking_ref' => $ref, 'payer' => $payer?->only(['id', 'first_name', 'last_name', 'email', 'phone']),
            'rooms' => $rows->map(fn (Reservation $r) => [
                'id' => $r->id, 'number' => $r->number, 'status' => $r->status, 'guest' => $r->guest?->only(['id', 'first_name', 'last_name']),
                'room_type' => $r->roomType?->only(['id', 'code', 'name']), 'room' => $r->room?->number, 'arrival' => $r->arrival->toDateString(),
                'departure' => $r->departure->toDateString(), 'adults' => $r->adults, 'children' => $r->children, 'total_amount' => (int) $r->total_amount,
                'deposit_required' => (int) $r->deposit_required, 'deposit_paid' => (int) $r->deposit_paid,
            ])->all(),
            'total' => (int) $rows->sum('total_amount'), 'deposit_required' => (int) $rows->sum('deposit_required'), 'deposit_paid' => (int) $rows->sum('deposit_paid'),
            'master_folio' => Folio::where('kind', 'master')->whereIn('reservation_id', $rows->pluck('id'))->first()?->only(['id', 'number', 'balance']),
        ];
    }

    private function guest(?int $id, ?array $data): ?Guest
    {
        if ($id) {
            return Guest::findOrFail($id);
        }
        if (! $data) {
            return null;
        }
        $existing = ! empty($data['email']) ? Guest::where('email', $data['email'])->first() : null;

        return $existing ?? Guest::create(array_intersect_key($data, array_flip(['first_name', 'last_name', 'email', 'phone', 'country', 'nationality'])));
    }
}
