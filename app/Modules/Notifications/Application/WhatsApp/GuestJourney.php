<?php

namespace App\Modules\Notifications\Application\WhatsApp;

use App\Modules\FrontOffice\Domain\Folio;
use App\Modules\GuestPortal\Application\GuestLinks;
use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Carbon;

/**
 * Automated WhatsApp guest journey: booking confirmation, pre-arrival with online
 * check-in link, welcome with in-stay ordering link, bill and pay link on the
 * departure day, post-stay review request. Enabled with NOTIFY_WHATSAPP_JOURNEY=true
 * (config notifications.whatsapp.journey); guests must have opted in to WhatsApp.
 */
class GuestJourney
{
    public function __construct(private TenantContext $ctx, private WhatsAppMessenger $wa, private GuestLinks $links) {}

    public static function enabled(): bool
    {
        return filter_var(config('notifications.whatsapp.journey') ?? env('NOTIFY_WHATSAPP_JOURNEY', false), FILTER_VALIDATE_BOOLEAN);
    }

    public function step(string $journey, int $reservationId, array $extra = [], bool $once = true): void
    {
        if (! self::enabled()) {
            return;
        }
        $r = $this->ctx->withoutScope(fn () => Reservation::with('room')->find($reservationId));
        if (! $r) {
            return;
        }
        $this->asHotel($r->hotel_id, function () use ($journey, $r, $extra, $once) {
            $vars = $this->varsFor($journey, $r);
            if ($vars !== null) {
                $this->wa->journey($journey, $r, $extra + $vars, $once);
            }
        });
    }

    /** Daily run: pre-arrival (arriving tomorrow) and bill + pay link (departing today). */
    public function daily(): int
    {
        if (! self::enabled()) {
            return 0;
        }
        $n = 0;
        foreach ($this->ctx->withoutScope(fn () => Hotel::where('status', 'active')->get()) as $hotel) {
            $today = Carbon::parse($hotel->business_date ?? now());
            $ids = $this->ctx->withoutScope(fn () => [
                'pre_arrival' => Reservation::where('hotel_id', $hotel->id)->whereDate('arrival', $today->copy()->addDay())->where('status', 'confirmed')->pluck('id'),
                'bill' => Reservation::where('hotel_id', $hotel->id)->whereDate('departure', $today)->where('status', 'checked_in')->pluck('id'),
            ]);
            foreach ($ids as $journey => $list) {
                foreach ($list as $id) {
                    $this->step($journey, $id);
                    $n++;
                }
            }
        }

        return $n;
    }

    /** Template variables for a journey step (hotel in context); null when the step does not apply (e.g. nothing to pay). */
    public function varsFor(string $journey, Reservation $r): ?array
    {
        $lang = $this->lang($r);
        $g = $r->guest()->withoutGlobalScopes()->first();
        $base = ['guest_name' => trim("$g?->first_name $g?->last_name"), 'hotel_name' => Hotel::withoutGlobalScopes()->find($r->hotel_id)?->name,
            'number' => $r->number, 'reference' => $r->public_code, 'arrival' => $r->arrival?->toDateString(), 'departure' => $r->departure?->toDateString()];
        $vars = match ($journey) {
            'booking_confirmation' => ['manage_url' => $this->links->manage($r, $lang)],
            'pre_arrival' => ['checkin_url' => $this->links->checkin($r, $lang)],
            'welcome' => ['room' => $r->room?->number ?? '-', 'order_url' => $this->links->order($r, $lang)],
            'bill' => $this->bill($r, $lang),
            'review' => ['review_url' => $this->links->review($r, $lang)],
            default => [],
        };

        return $vars === null ? null : $vars + $base;
    }

    private function bill(Reservation $r, string $lang): ?array
    {
        $balance = (int) Folio::where('reservation_id', $r->id)->where('kind', 'guest')->where('status', 'open')->sum('balance');
        if ($balance <= 0) {
            return null;
        }

        return ['balance' => number_format($balance, 0, ',', ' '), 'currency' => $r->currency ?? 'XAF', 'pay_url' => $this->links->pay($r, $balance, $lang)];
    }

    private function lang(Reservation $r): string
    {
        $g = $r->guest()->withoutGlobalScopes()->first();

        return str_starts_with(strtolower((string) $g?->language), 'en') ? 'en' : 'fr';
    }

    private function asHotel(int $hotelId, callable $fn): void
    {
        $saved = [$this->ctx->groupId(), $this->ctx->hotelId(), $this->ctx->allowedHotelIds(), $this->ctx->actor()];
        $this->ctx->forHotel(Hotel::withoutGlobalScopes()->findOrFail($hotelId));
        try {
            $fn();
        } finally {
            $this->ctx->set(...$saved);
        }
    }
}
