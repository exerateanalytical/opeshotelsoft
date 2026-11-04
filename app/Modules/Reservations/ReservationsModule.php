<?php

namespace App\Modules\Reservations;

use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Reservations\Experience\Application\AutomateCommand;
use App\Modules\Reservations\Revenue\Console\RevenueCommands;
use App\Modules\Reservations\Experience\Application\PurchaseService;
use App\Platform\Events\EventBus;
use App\Platform\Events\StoredEvent;
use Illuminate\Console\Application as Artisan;

class ReservationsModule
{
    public static function subscribe(EventBus $bus): void
    {
        Artisan::starting(fn (Artisan $a) => $a->resolveCommands(array_merge([AutomateCommand::class], RevenueCommands::all())));

        // R-B: what the guest bought before arriving (early check-in, late check-out, services) lands on the folio at check-in.
        $bus->subscribe('frontoffice.guest_checked_in', 'experience.post_purchases', function (StoredEvent $e) {
            if ($r = Reservation::find($e->payload['id'] ?? 0)) {
                app(PurchaseService::class)->postPending($r);
            }
        });
    }
}
