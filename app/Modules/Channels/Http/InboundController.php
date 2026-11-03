<?php

namespace App\Modules\Channels\Http;

use App\Modules\Channels\Application\BookingImporter;
use App\Modules\Channels\Application\ChannelGateway;
use App\Modules\Channels\Domain\Connection;
use App\Modules\Channels\Domain\SyncLog;
use App\Modules\Property\Domain\RoomType;
use App\Modules\Reservations\Application\Inventory;
use App\Modules\Reservations\Application\Pricing;
use App\Modules\Reservations\Domain\RatePlan;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Domain\DomainException;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Public (unauthenticated) channel endpoints, secured per connection:
 *  - inbound booking notifications (signature-verified),
 *  - metasearch live-price pulls (Tripadvisor).
 */
class InboundController
{
    public function __construct(private TenantContext $ctx) {}

    public function receive(Request $request, string $uuid, ChannelGateway $gateway, BookingImporter $importer)
    {
        $c = $this->ctx->withoutScope(fn () => Connection::where('uuid', $uuid)->first());
        abort_unless($c && $c->receive_reservations && $c->status !== 'suspended', 404);
        $driver = $gateway->driver($c);

        if (! $driver->verify($request, $c)) {
            $this->ctx->set($c->group_id, $c->hotel_id, [$c->hotel_id]);
            SyncLog::create(['channel_connection_id' => $c->id, 'direction' => 'inbound', 'kind' => 'reservation', 'status' => 'rejected', 'error' => 'Invalid signature', 'request' => mb_substr($request->getContent(), 0, 20_000)]);
            abort(401, 'Invalid signature');
        }

        try {
            $bookings = $driver->parseBookings($request->getContent(), (string) $request->header('Content-Type'));
        } catch (Throwable $e) {
            abort(400, 'Unreadable payload: '.$e->getMessage());
        }
        $results = $importer->import($c, $bookings);
        $ack = $driver->acknowledge($bookings);

        return isset($ack['xml'])
            ? response($ack['xml'], 200, ['Content-Type' => 'application/xml'])
            : response()->json($ack + ['results' => $results]);
    }

    /** Metasearch price pull: ?checkin=&checkout=&adults= */
    public function meta(Request $request, string $uuid, Inventory $inventory, Pricing $pricing)
    {
        $c = $this->ctx->withoutScope(fn () => Connection::where('uuid', $uuid)->where('provider', 'tripadvisor')->first());
        abort_unless($c && $c->isLive(), 404);
        $data = $request->validate(['checkin' => ['required', 'date'], 'checkout' => ['required', 'date', 'after:checkin'], 'adults' => ['sometimes', 'integer', 'between:1,8']]);
        $this->ctx->set($c->group_id, $c->hotel_id, [$c->hotel_id]);
        $hotel = Hotel::findOrFail($c->hotel_id);
        $arrival = Carbon::parse($data['checkin']);
        $departure = Carbon::parse($data['checkout']);

        $offers = [];
        foreach ($c->roomMappings as $rm) {
            $type = RoomType::find($rm->room_type_id);
            $grid = $inventory->grid($hotel->id, $type->id, $arrival->toDateString(), $departure->copy()->subDay()->toDateString());
            if (min(array_column($grid, 'available') ?: [0]) < 1) {
                continue;
            }
            foreach ($c->rateMappings as $map) {
                try {
                    $q = $pricing->quote(RatePlan::findOrFail($map->rate_plan_id), $type, $arrival, $departure, $data['adults'] ?? 2);
                } catch (DomainException) {
                    continue;
                }
                $offers[] = [
                    'room_type_code' => $rm->external_room_id, 'rate_code' => $map->external_rate_id, 'room_name' => $type->name,
                    'price' => (int) round($q['total'] * (1 + $map->markup_percent / 100)), 'currency' => $hotel->currency,
                    'url' => url("/book/{$hotel->code}?arrival={$data['checkin']}&departure={$data['checkout']}&room={$type->id}"),
                ];
            }
        }

        return ['hotel_id' => $c->property_code, 'checkin' => $data['checkin'], 'checkout' => $data['checkout'], 'offers' => $offers];
    }
}
