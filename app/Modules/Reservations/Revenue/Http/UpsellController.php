<?php

namespace App\Modules\Reservations\Revenue\Http;

use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Reservations\Revenue\Application\UpsellService;
use App\Modules\Reservations\Revenue\Domain\UpsellOffer;
use Illuminate\Http\Request;

/** Staff side of the upsell engine: make, accept and decline room-upgrade offers. */
class UpsellController extends RevenueController
{
    public function index(Request $request)
    {
        $this->need($request, 'revenue.view', 'revenue.upsell', 'revenue.manage');
        $data = $request->validate(['reservation_id' => ['nullable', 'integer'], 'status' => ['nullable', 'string']]);

        return UpsellOffer::when($data['reservation_id'] ?? null, fn ($q, $v) => $q->where('reservation_id', $v))
            ->when($data['status'] ?? null, fn ($q, $v) => $q->where('status', $v))->orderByDesc('id')->paginate(50);
    }

    public function offer(Request $request, Reservation $reservation, UpsellService $svc)
    {
        $this->need($request, 'revenue.upsell', 'revenue.manage');
        $data = $request->validate(['stage' => ['required', 'in:booking,pre_arrival,check_in']]);

        return response()->json(['data' => $svc->offer($reservation, $data['stage'], 'staff')->values()], 201);
    }

    public function accept(Request $request, UpsellOffer $offer, UpsellService $svc)
    {
        $this->need($request, 'revenue.upsell', 'revenue.manage');
        $data = $request->validate(['room_id' => ['nullable', 'integer']]);

        return $svc->accept($offer, $request->user(), $data['room_id'] ?? null);
    }

    public function decline(Request $request, UpsellOffer $offer, UpsellService $svc)
    {
        $this->need($request, 'revenue.upsell', 'revenue.manage');

        return $svc->decline($offer, $request->user());
    }

    /** Offer upgrades to everyone arriving in N days (run daily by the scheduler or by hand). */
    public function preArrivalRun(Request $request, UpsellService $svc)
    {
        $this->need($request, 'revenue.manage');
        $data = $request->validate(['days_ahead' => ['sometimes', 'integer', 'between:0,30']]);

        return ['reservations_offered' => $svc->offerPreArrival($data['days_ahead'] ?? 3)];
    }
}
