<?php

namespace App\Modules\Reservations\Revenue\Http;

use App\Modules\Property\Domain\RoomType;
use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Reservations\Revenue\Application\UpsellService;
use App\Modules\Reservations\Revenue\Domain\UpsellOffer;
use App\Modules\Tenancy\Domain\Group;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Http\Request;

/**
 * Guest-facing upgrade offers (booking site, pre-arrival email/WhatsApp link, guest app).
 * Same credential as the manage-booking API: confirmation number + the stateless manage token.
 */
class PublicUpsellController
{
    public function __construct(private TenantContext $ctx) {}

    public function offers(Request $request, string $group, UpsellService $svc)
    {
        $data = $request->validate(['confirmation' => ['required', 'string'], 'token' => ['required', 'string'], 'stage' => ['sometimes', 'in:booking,pre_arrival']]);

        return $this->inHotel($data, function (Reservation $r) use ($svc, $data) {
            $open = UpsellOffer::where('reservation_id', $r->id)->where('status', 'offered')->get();
            if ($open->isEmpty()) {
                $open = $svc->offer($r, $data['stage'] ?? 'pre_arrival', 'web');
            }

            return ['offers' => $open->map(fn ($o) => $this->present($o))->values()];
        });
    }

    public function accept(Request $request, string $group, UpsellService $svc)
    {
        $data = $request->validate(['confirmation' => ['required', 'string'], 'token' => ['required', 'string'], 'offer_id' => ['required', 'integer']]);

        return $this->inHotel($data, function (Reservation $r) use ($svc, $data) {
            $offer = UpsellOffer::where('reservation_id', $r->id)->findOrFail($data['offer_id']);
            $offer = $svc->accept($offer);

            return ['status' => 'accepted', 'offer' => $this->present($offer), 'total' => $r->refresh()->total_amount];
        });
    }

    public function decline(Request $request, string $group, UpsellService $svc)
    {
        $data = $request->validate(['confirmation' => ['required', 'string'], 'token' => ['required', 'string'], 'offer_id' => ['required', 'integer']]);

        return $this->inHotel($data, function (Reservation $r) use ($svc, $data) {
            $svc->decline(UpsellOffer::where('reservation_id', $r->id)->findOrFail($data['offer_id']));

            return ['status' => 'declined'];
        });
    }

    private function present(UpsellOffer $o): array
    {
        return [
            'id' => $o->id, 'stage' => $o->stage, 'status' => $o->status, 'price_per_night' => $o->price_per_night, 'nights' => $o->nights, 'total' => $o->total,
            'to_room_type' => RoomType::find($o->to_room_type_id)?->only(['id', 'code', 'name', 'amenities', 'description']),
        ];
    }

    private function inHotel(array $data, callable $fn): mixed
    {
        $slug = request()->route('group');
        $g = Group::where('slug', $slug)->where('status', 'active')->firstOrFail();
        $this->ctx->set($g->id, null, null);
        $r = app(\App\Modules\GuestPortal\Application\PublicBookingResolver::class)->resolve($g->id, $data['confirmation'], request()->input('hotel'), null, $data['token']);

        $hotel = Hotel::withoutGlobalScopes()->findOrFail($r->hotel_id);
        $saved = [$this->ctx->groupId(), $this->ctx->hotelId(), $this->ctx->allowedHotelIds(), $this->ctx->actor()];
        $this->ctx->set($hotel->group_id, $hotel->id, [$hotel->id]);
        try {
            return $fn(Reservation::findOrFail($r->id));
        } finally {
            $this->ctx->set(...$saved);
        }
    }
}
