<?php

namespace App\Modules\Channels\Application;

use App\Modules\Channels\Domain\ChannelReservation;
use App\Modules\Channels\Domain\Connection;
use App\Modules\Channels\Domain\SyncLog;
use App\Modules\Guests\Domain\Guest;
use App\Modules\Reservations\Application\ReservationService;
use App\Modules\Reservations\Domain\Reservation;
use App\Platform\Domain\DomainException;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Turns normalized channel bookings into reservations. Idempotent per
 * (connection, external_ref, version): replays are ignored. A booking the
 * channel already sold is accepted even if restrictions changed meanwhile
 * (channels guarantee the sale), but never beyond physical inventory —
 * those go to the overbooking queue for the front office to resolve.
 */
class BookingImporter
{
    public function __construct(private ReservationService $reservations, private EventBus $bus, private TenantContext $ctx) {}

    /** @return array<int, array{external_ref:string, result:string, reservation_id?:int, error?:string}> */
    public function import(Connection $c, array $bookings): array
    {
        $this->ctx->set($c->group_id, $c->hotel_id, [$c->hotel_id], $this->ctx->actor());
        $results = [];
        foreach ($bookings as $b) {
            try {
                $results[] = DB::transaction(fn () => $this->one($c, $b));
            } catch (Throwable $e) {
                $results[] = ['external_ref' => $b['external_ref'], 'result' => 'rejected', 'error' => $e->getMessage()];
                $this->bus->record('channel.booking_rejected', ['connection_id' => $c->id, 'provider' => $c->provider, 'external_ref' => $b['external_ref'], 'error' => $e->getMessage()]);
            }
        }
        SyncLog::create([
            'channel_connection_id' => $c->id, 'direction' => 'inbound', 'kind' => 'reservation',
            'status' => collect($results)->contains('result', 'rejected') ? 'rejected' : 'processed',
            'request' => json_encode($bookings), 'response' => json_encode($results),
        ]);

        return $results;
    }

    private function one(Connection $c, array $b): array
    {
        $existing = ChannelReservation::where('channel_connection_id', $c->id)->where('external_ref', $b['external_ref'])->lockForUpdate()->first();
        if ($existing && $b['version'] !== '' && $existing->version === $b['version'] && $existing->external_status === $b['status']) {
            return ['external_ref' => $b['external_ref'], 'result' => 'duplicate', 'reservation_id' => $existing->reservation_id];
        }

        $roomTypeId = $c->roomMappings()->where('external_room_id', $b['room'])->value('room_type_id')
            ?? throw new DomainException("Unmapped channel room {$b['room']}.", 'unmapped-room');
        $ratePlanId = $c->rateMappings()->where('external_rate_id', $b['rate'])->value('rate_plan_id')
            ?? $c->rateMappings()->value('rate_plan_id')
            ?? throw new DomainException("Unmapped channel rate {$b['rate']}.", 'unmapped-rate');

        $reservation = $existing?->reservation_id ? Reservation::find($existing->reservation_id) : null;

        if ($b['status'] === 'cancelled') {
            if ($reservation && in_array($reservation->status, ['confirmed', 'tentative'], true)) {
                // The channel applies its own penalty; we do not charge a second time.
                $this->reservations->cancel($reservation, 'Cancelled on '.$c->provider, waiveFee: true);
            }
            $result = 'cancelled';
        } elseif ($reservation) {
            $this->reservations->modify($reservation, [
                'arrival' => $b['arrival'], 'departure' => $b['departure'], 'room_type_id' => $roomTypeId, 'rate_plan_id' => $ratePlanId,
                'adults' => $b['adults'], 'children' => $b['children'], 'enforce_restrictions' => false,
            ]);
            $result = 'modified';
        } else {
            $guest = $this->guest($b['guest']);
            $reservation = $this->reservations->create([
                'guest_id' => $guest->id, 'room_type_id' => $roomTypeId, 'rate_plan_id' => $ratePlanId,
                'arrival' => $b['arrival'], 'departure' => $b['departure'], 'adults' => $b['adults'], 'children' => $b['children'],
                'channel' => in_array($c->provider, ['booking_com', 'expedia'], true) ? $c->provider : 'channel_manager',
                'source' => $b['source'] ?? $c->provider, 'external_ref' => $b['external_ref'],
                'amount_override' => $b['total'] > 0 ? $b['total'] : null, 'special_requests' => $b['notes'] ?? null,
                'guarantee' => 'card', 'enforce_restrictions' => false,
            ]);
            $result = 'created';
        }

        ChannelReservation::updateOrCreate(
            ['channel_connection_id' => $c->id, 'external_ref' => $b['external_ref']],
            ['external_status' => $b['status'], 'version' => $b['version'], 'reservation_id' => $reservation?->id, 'payload' => $b],
        );
        $this->bus->record('channel.booking_'.$result, ['connection_id' => $c->id, 'provider' => $c->provider, 'external_ref' => $b['external_ref'], 'reservation_id' => $reservation?->id]);

        return ['external_ref' => $b['external_ref'], 'result' => $result, 'reservation_id' => $reservation?->id];
    }

    private function guest(array $g): Guest
    {
        $found = ! empty($g['email']) ? Guest::where('email', $g['email'])->first() : null;

        return $found ?? Guest::create([
            'first_name' => $g['first_name'] ?: 'Guest', 'last_name' => $g['last_name'] ?: 'OTA',
            'email' => $g['email'] ?? null, 'phone' => $g['phone'] ?? null,
            'country' => isset($g['country']) ? strtoupper(substr($g['country'], 0, 2)) : null,
        ]);
    }
}
