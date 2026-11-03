<?php

namespace App\Modules\Channels;

use App\Modules\Channels\Application\PushAri;
use App\Modules\Channels\Domain\Connection;
use App\Platform\Events\EventBus;
use App\Platform\Events\StoredEvent;
use Illuminate\Support\Carbon;

/**
 * Keeps every live channel in sync: any change to rates, restrictions or
 * inventory (bookings, cancellations, out-of-order rooms) queues an ARI push
 * for the affected dates.
 */
class ChannelsModule
{
    public static function subscribe(EventBus $bus): void
    {
        $push = function (StoredEvent $e, string $from, string $to, ?array $roomTypes = null) {
            foreach (Connection::whereIn('status', ['testing', 'active'])->get() as $c) {
                $horizon = now()->addDays($c->sync_days)->toDateString();
                $f = max($from, now()->toDateString());
                $t = min($to, $horizon);
                if ($f <= $t) {
                    PushAri::dispatch($c->id, $f, $t, $roomTypes)->afterCommit();
                }
            }
        };

        foreach (['rates.updated', 'availability.updated'] as $type) {
            $bus->subscribe($type, 'channels.ari.'.$type, fn (StoredEvent $e) => $push($e, $e->payload['from'], $e->payload['to'], $e->payload['room_type_ids'] ?? null));
        }

        foreach (['reservation.created', 'reservation.modified', 'reservation.cancelled', 'reservation.no_show', 'frontoffice.guest_checked_out'] as $type) {
            $bus->subscribe($type, 'channels.inventory.'.$type, function (StoredEvent $e) use ($push) {
                $p = $e->payload;
                $types = array_values(array_unique(array_filter([$p['room_type_id'] ?? null, $p['before']['room_type_id'] ?? null])));
                $from = min($p['arrival'], Carbon::parse($p['before']['arrival'] ?? $p['arrival'])->toDateString());
                $to = max($p['departure'], Carbon::parse($p['before']['departure'] ?? $p['departure'])->toDateString());
                $push($e, $from, $to, $types ?: null);
            });
        }
    }
}
