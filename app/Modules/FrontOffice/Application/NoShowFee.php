<?php

namespace App\Modules\FrontOffice\Application;

use App\Modules\FrontOffice\Domain\FolioLine;
use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Reservations\Experience\Domain\ExperienceSetting;
use App\Modules\Tenancy\Domain\Hotel;

/**
 * Posts the no-show fee (charge code CXL) to the guest folio once a reservation has been marked no-show.
 * The amount is the fee ReservationService::markNoShow stored from the rate plan's cancellation policy
 * (reservations.cancellation_fee). Off by default (experience_settings.no_show_fee). Idempotent: a reservation
 * is charged once, whichever of night audit or the hourly automation runs first or runs again.
 */
class NoShowFee
{
    public function __construct(private FolioService $folios) {}

    public function post(Reservation $r): ?FolioLine
    {
        $r->refresh();
        if ($r->status !== 'no_show' || (int) $r->cancellation_fee <= 0) {
            return null;
        }
        if (! ExperienceSetting::forHotel(Hotel::findOrFail($r->hotel_id))->no_show_fee) {
            return null;
        }
        if (FolioLine::where('source_type', 'no_show_fee')->where('source_id', (string) $r->id)->where('type', 'charge')->where('reversed', false)->exists()) {
            return null;
        }

        return $this->folios->charge($this->folios->guestFolioFor($r), 'CXL', (int) $r->cancellation_fee, [
            'description' => "No-show {$r->number}", 'source_type' => 'no_show_fee', 'source_id' => $r->id,
        ]);
    }
}
