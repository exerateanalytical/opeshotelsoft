<?php

namespace App\Modules\GuestPortal\Http;

use App\Modules\GuestPortal\Application\GuestOrderService;
use App\Modules\GuestPortal\Domain\GuestOrder;
use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;

/** Signed in-stay ordering page sent by WhatsApp (no login: the URL signature is the credential). */
class GuestOrderController
{
    public function __construct(private TenantContext $ctx, private GuestOrderService $orders) {}

    public function page(Request $request, int $reservation)
    {
        $r = $this->reservation($reservation);
        $lang = in_array($request->query('lang'), ['fr', 'en'], true) ? $request->query('lang') : 'fr';

        return $this->orders->asHotelOf($r, function () use ($r, $lang, $request) {
            $outlet = $this->orders->outlet();
            $until = now()->createFromTimestamp((int) $request->query('expires', now()->addDay()->timestamp));

            return Inertia::render('Messaging/GuestOrder', [
                'lang' => $lang,
                'hotel' => Hotel::withoutGlobalScopes()->find($r->hotel_id)?->only(['name', 'phone']),
                'stay' => ['number' => $r->number, 'room' => $r->room?->number, 'in_house' => $r->status === 'checked_in', 'currency' => $r->currency ?? 'XAF'],
                'outlet' => $outlet?->name,
                'menu' => $this->orders->menu($outlet, $lang),
                'orders' => GuestOrder::where('reservation_id', $r->id)->latest('id')->limit(10)->get(['id', 'total', 'status', 'created_at']),
                'submit_url' => URL::temporarySignedRoute('guest.order.place', $until, ['reservation' => $r->id, 'hotel' => $r->hotel_id]),
            ]);
        });
    }

    public function place(Request $request, int $reservation)
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:30'], 'items.*.menu_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:20'], 'items.*.notes' => ['nullable', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:500'], 'client_uuid' => ['nullable', 'string', 'max:64'],
        ]);
        $order = $this->orders->place($this->reservation($reservation), $data['items'], $data['notes'] ?? null, $data['client_uuid'] ?? null);

        return response()->json(['id' => $order->id, 'total' => $order->total, 'status' => $order->status, 'check_id' => $order->pos_check_id], 201);
    }

    private function reservation(int $id): Reservation
    {
        $r = $this->ctx->withoutScope(fn () => Reservation::with('room')->findOrFail($id));
        // The signed link carries the hotel too: refuse a link whose hotel does not own the reservation (legacy links without it stay valid).
        abort_if(request()->query('hotel') !== null && (int) request()->query('hotel') !== (int) $r->hotel_id, 403);

        return $r;
    }
}
