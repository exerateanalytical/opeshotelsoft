<?php

namespace App\Modules\Notifications\Application\Console;

use App\Modules\Notifications\Application\Notifier;
use App\Modules\Notifications\Domain\OutboxMessage;
use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Console\Command;

/** Sends pre-arrival messages for reservations arriving the day after each hotel's business date. Idempotent. */
class SendPreArrivalCommand extends Command
{
    protected $signature = 'notifications:pre-arrival';

    protected $description = 'Queue pre-arrival messages for guests arriving tomorrow';

    public function handle(TenantContext $ctx, Notifier $notifier): int
    {
        $count = 0;
        $hotels = $ctx->withoutScope(fn () => Hotel::where('status', 'active')->get());
        $saved = [$ctx->groupId(), $ctx->hotelId(), $ctx->allowedHotelIds(), $ctx->actor()];
        try {
            foreach ($hotels as $hotel) {
                $ctx->forHotel($hotel);
                $tomorrow = ($hotel->business_date ? \Illuminate\Support\Carbon::parse($hotel->business_date) : now())->addDay()->toDateString();
                $reservations = Reservation::with('guest')->whereDate('arrival', $tomorrow)->where('status', 'confirmed')->get();
                foreach ($reservations as $r) {
                    $done = OutboxMessage::where(['event' => 'reservation.pre_arrival', 'ref_type' => 'reservation', 'ref_id' => $r->id])->exists();
                    if ($done) {
                        continue;
                    }
                    $notifier->notify('reservation.pre_arrival', ['guest_id' => $r->guest_id], [
                        'number' => $r->number, 'arrival' => $r->arrival->toDateString(), 'departure' => $r->departure->toDateString(),
                    ], ['type' => 'reservation', 'id' => $r->id]);
                    $count++;
                }
            }
        } finally {
            $ctx->set(...$saved);
        }
        $this->info("Queued pre-arrival messages for $count reservation(s).");

        return self::SUCCESS;
    }
}
