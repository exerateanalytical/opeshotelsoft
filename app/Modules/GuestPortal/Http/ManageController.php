<?php

namespace App\Modules\GuestPortal\Http;

use App\Modules\GuestPortal\Application\PublicBookingResolver;
use App\Modules\GuestPortal\Application\ManageService;
use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Reservations\Experience\Application\DigitalCheckin;
use App\Modules\Reservations\Experience\Application\PurchaseService;
use App\Modules\Tenancy\Domain\Group;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Http\Request;

/**
 * Self-service endpoints behind the manage-booking page. Authorised per booking by the manage token (or, for the
 * review page only, confirmation number + last name). Group scoped by slug; every call runs in the booking's hotel.
 */
class ManageController
{
    public function __construct(private TenantContext $ctx, private ManageService $manage) {}

    public function options(Request $request, string $group)
    {
        return $this->run($request, $group, fn (Reservation $r) => $this->manage->options($r, is_string($request->input('lang')) ? $request->input('lang') : null));
    }

    public function modify(Request $request, string $group)
    {
        $d = $request->validate(['arrival' => ['required', 'date'], 'departure' => ['required', 'date'], 'apply' => ['sometimes', 'boolean']]);

        return $this->run($request, $group, fn (Reservation $r) => $this->manage->modify($r, $d['arrival'], $d['departure'], (bool) ($d['apply'] ?? false)));
    }

    public function service(Request $request, string $group, PurchaseService $purchases)
    {
        $d = $request->validate(['code' => ['required', 'string', 'max:24'], 'quantity' => ['sometimes', 'integer', 'between:1,20']]);

        return $this->run($request, $group, fn (Reservation $r) => ['purchase' => $purchases->buyService($r, $d['code'], $d['quantity'] ?? 1)], 201);
    }

    /** Quote (apply=false) or buy an early check-in / late check-out. */
    public function timing(Request $request, string $group, PurchaseService $purchases)
    {
        $d = $request->validate(['kind' => ['required', 'in:early_checkin,late_checkout'], 'time' => ['required', 'date_format:H:i'], 'apply' => ['sometimes', 'boolean']]);

        return $this->run($request, $group, fn (Reservation $r) => ($d['apply'] ?? false)
            ? ['purchase' => $purchases->buyRequest($r, $d['kind'], $d['time'])]
            : ['quote' => $purchases->quote($r, $d['kind'], $d['time'])], ($d['apply'] ?? false) ? 201 : 200);
    }

    public function pay(Request $request, string $group)
    {
        $d = $request->validate(['amount' => ['nullable', 'integer', 'min:1']]);

        return $this->run($request, $group, fn (Reservation $r) => ['url' => $this->manage->payLink($r, $d['amount'] ?? null)]);
    }

    public function checkin(Request $request, string $group, DigitalCheckin $checkin)
    {
        $d = $request->validate([
            'id_type' => ['required', 'in:passport,national_id,residence_permit,driving_licence'], 'id_number' => ['required', 'string', 'max:60'],
            'nationality' => ['nullable', 'string', 'size:2'], 'id_country' => ['nullable', 'string', 'size:2'], 'id_expiry' => ['nullable', 'date'],
            'birth_date' => ['nullable', 'date', 'before:today'], 'address' => ['nullable', 'string', 'max:255'], 'eta' => ['required', 'date_format:H:i'],
            'notes' => ['nullable', 'string', 'max:500'], 'id_photo' => ['nullable', 'string'], 'signature' => ['nullable', 'string'], 'card_accepted' => ['sometimes', 'boolean'],
        ]);

        return $this->run($request, $group, function (Reservation $r) use ($checkin, $d) {
            $row = $checkin->submit($r->loadMissing('guest'), $d);

            return ['status' => 'received', 'submitted_at' => $row->submitted_at, 'readiness' => $checkin->readiness($r->refresh())];
        }, 201);
    }

    public function review(Request $request, string $group)
    {
        $d = $request->validate(['rating' => ['required', 'integer', 'between:1,5'], 'comment' => ['nullable', 'string', 'max:1000']]);

        return $this->run($request, $group, function (Reservation $r) use ($d) {
            $this->manage->review($r, $d['rating'], $d['comment'] ?? null);

            return ['status' => 'received'];
        }, 201, allowLastName: true);
    }

    /** Resolve and authorise the booking, run $fn inside its hotel context. */
    private function run(Request $request, string $slug, callable $fn, int $status = 200, bool $allowLastName = false)
    {
        $saved = [$this->ctx->groupId(), $this->ctx->hotelId(), $this->ctx->allowedHotelIds(), $this->ctx->actor()];
        try {
            return $this->runIn($request, $slug, $fn, $status, $allowLastName);
        } finally {
            $this->ctx->set(...$saved);
        }
    }

    private function runIn(Request $request, string $slug, callable $fn, int $status, bool $allowLastName)
    {
        $g = Group::where('slug', $slug)->where('status', 'active')->firstOrFail();
        $this->ctx->set($g->id, null, null);
        $in = $request->validate(['confirmation' => ['required', 'string'], 'token' => [$allowLastName ? 'nullable' : 'required', 'string'], 'last_name' => ['nullable', 'string'], 'hotel' => ['nullable', 'string', 'max:20']]);
        // Booking reference or confirmation number; the token (or, for reviews only, the last name) proves ownership and picks the right hotel.
        abort_unless(! empty($in['token']) || ($allowLastName && ! empty($in['last_name'])), 403, 'Invalid link.');
        $r = app(PublicBookingResolver::class)->resolve($g->id, $in['confirmation'], $in['hotel'] ?? null, $in['last_name'] ?? null, $in['token'] ?? null, 403);

        $hotel = Hotel::withoutGlobalScopes()->findOrFail($r->hotel_id);
        $this->ctx->set($hotel->group_id, $hotel->id, [$hotel->id]);
        return response()->json($fn(Reservation::with("guest", "ratePlan")->findOrFail($r->id)), $status);
    }
}
