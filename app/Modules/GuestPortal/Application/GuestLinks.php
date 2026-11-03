<?php

namespace App\Modules\GuestPortal\Application;

use App\Modules\FrontOffice\Domain\Folio;
use App\Modules\Payments\Application\PayLinkService;
use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Tenancy\Domain\Group;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/** Guest-facing links sent in messages (manage booking, online check-in, in-stay ordering, pay, review). */
class GuestLinks
{
    public function manage(Reservation $r, string $lang = 'fr', string $anchor = ''): string
    {
        $slug = Group::withoutGlobalScopes()->find($r->group_id)?->slug;

        return url("/book/$slug/manage").'?'.http_build_query(['c' => $r->number, 'lang' => $lang]).$anchor;
    }

    public function checkin(Reservation $r, string $lang = 'fr'): string
    {
        return $this->manage($r, $lang, '#checkin');
    }

    /** Signed mobile ordering page, valid until the day after departure. */
    public function order(Reservation $r, string $lang = 'fr'): string
    {
        $until = Carbon::parse($r->departure)->addDay()->endOfDay();

        return URL::temporarySignedRoute('guest.order', $until, ['reservation' => $r->id, 'lang' => $lang]);
    }

    /**
     * Signed pay link (Payments PayLinkService, page /pay/{code}/{sig}) for the open guest folio.
     * Falls back to the booking site's manage page if no link can be created.
     */
    public function pay(Reservation $r, int $amount, string $lang = 'fr'): string
    {
        try {
            $folio = Folio::where('reservation_id', $r->id)->where('kind', 'guest')->where('status', 'open')->first();
            if ($folio) {
                $svc = app(PayLinkService::class);

                return $svc->url($svc->create('folio', $folio->id, min($amount, $svc->due('folio', $folio->id)), ['payer_phone' => $r->guest?->phone, 'valid_days' => 2]));
            }
        } catch (\Throwable) {
            // fall back to the manage page
        }

        return $this->manage($r, $lang, '#pay');
    }

    public function review(Reservation $r, string $lang = 'fr'): string
    {
        return config('notifications.whatsapp.review_url') ?: $this->manage($r, $lang, '#review');
    }

    /** Same stateless token as the public Booking API's manage token. */
    public static function manageToken(Reservation $r): string
    {
        return hash_hmac('sha256', $r->id.'|'.$r->number.'|'.$r->guest_id, (string) config('app.key'));
    }
}
