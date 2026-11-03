<?php

namespace App\Modules\Reservations\Experience\Application;

use App\Modules\GuestPortal\Application\GuestLinks;
use App\Modules\Notifications\Application\Notifier;
use App\Modules\Reservations\Application\ReservationService;
use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Reservations\Experience\Domain\ExperienceSetting;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Domain\DomainException;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Carbon;

/**
 * Deposit and no-show automation, run hourly for every active hotel (command experience:automate).
 * - reminder: once, `deposit_reminder_hours` before the deposit deadline, through Notifications with a pay link.
 * - release: at the deadline an unpaid deposit booking is cancelled without fee and its inventory returns to sale
 *   (the normal cancellation message tells the guest).
 * - no-show: bookings whose arrival date has passed without check-in are marked no-show (night audit does the same;
 *   this catches hotels that audit late).
 * The deadline is reservations.deposit_deadline, or creation time plus the hotel's deposit_deadline_hours.
 */
class DepositAutomation
{
    public function __construct(private ReservationService $reservations, private Notifier $notifier, private GuestLinks $links, private TenantContext $ctx) {}

    /** @return array{reminded:int, released:int, no_shows:int} */
    public function run(?Carbon $now = null): array
    {
        $now ??= now();
        $total = ['reminded' => 0, 'released' => 0, 'no_shows' => 0];
        $hotels = $this->ctx->withoutScope(fn () => Hotel::where('status', 'active')->get());
        $saved = [$this->ctx->groupId(), $this->ctx->hotelId(), $this->ctx->allowedHotelIds(), $this->ctx->actor()];
        try {
            foreach ($hotels as $hotel) {
                $this->ctx->forHotel($hotel);
                foreach ($this->runHotel($hotel, $now) as $k => $v) {
                    $total[$k] += $v;
                }
            }
        } finally {
            $this->ctx->set(...$saved);
        }

        return $total;
    }

    private function runHotel(Hotel $hotel, Carbon $now): array
    {
        $s = ExperienceSetting::forHotel($hotel);
        $out = ['reminded' => 0, 'released' => 0, 'no_shows' => 0];

        $unpaid = Reservation::with('guest')->whereIn('status', ['tentative', 'confirmed'])->where('guarantee', 'deposit')
            ->where('deposit_required', '>', 0)->whereColumn('deposit_paid', '<', 'deposit_required')->get();
        foreach ($unpaid as $r) {
            $deadline = $r->deposit_deadline ?? $r->created_at->copy()->addHours($s->deposit_deadline_hours);
            if ($s->auto_release && $now->gte($deadline)) {
                try {
                    $this->reservations->cancel($r, 'Deposit not received by the deadline', true);
                    $out['released']++;
                } catch (DomainException) {
                    // changed under us; next run decides
                }
            } elseif (! $r->deposit_reminded_at && $now->gte($deadline->copy()->subHours($s->deposit_reminder_hours))) {
                $due = (int) $r->deposit_required - (int) $r->deposit_paid;
                $this->notifier->notify('reservation.deposit_reminder', ['guest_id' => $r->payer_guest_id ?? $r->guest_id], [
                    'number' => $r->number, 'amount_due' => number_format($due, 0, ',', ' '), 'currency' => $r->currency,
                    'deadline' => $deadline->copy()->timezone($hotel->timezone ?? 'Africa/Douala')->format('Y-m-d H:i'), 'pay_url' => $this->links->manage($r, 'fr', '#pay'),
                ], ['type' => 'reservation', 'id' => $r->id]);
                $r->forceFill(['deposit_reminded_at' => $now])->save();
                $out['reminded']++;
            }
        }

        if ($s->auto_no_show && $hotel->business_date) {
            foreach (Reservation::whereIn('status', ['tentative', 'confirmed'])->whereDate('arrival', '<', $hotel->business_date->toDateString())->get() as $r) {
                try {
                    $this->reservations->markNoShow($r);
                    $out['no_shows']++;
                } catch (DomainException) {
                }
            }
        }

        return $out;
    }
}
