<?php

namespace App\Modules\Reservations\Http;

use App\Modules\Reservations\Application\ReservationService;
use App\Modules\Reservations\Domain\Reservation;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReservationController
{
    public function __construct(private ReservationService $service) {}

    public function index(Request $request)
    {
        $this->can($request, 'view');
        $q = Reservation::with(['guest:id,first_name,last_name,vip', 'roomType:id,code,name', 'room:id,number', 'ratePlan:id,code']);

        foreach (['status', 'channel', 'room_type_id', 'company_id', 'guest_id', 'booking_ref', 'room_block_id'] as $f) {
            if ($v = $request->query($f)) {
                is_array($v) ? $q->whereIn($f, $v) : $q->where($f, $v);
            }
        }
        if ($d = $request->query('arriving')) {
            $q->whereDate('arrival', $d);
        }
        if ($d = $request->query('departing')) {
            $q->whereDate('departure', $d);
        }
        if ($d = $request->query('in_house_on')) {
            $q->where('arrival', '<=', $d)->where('departure', '>', $d)->where('status', 'checked_in');
        }
        if ($term = $request->query('q')) {
            $q->where(fn ($w) => $w->where('number', 'ilike', "%$term%")->orWhere('external_ref', 'ilike', "%$term%")
                ->orWhereHas('guest', fn ($g) => $g->where('last_name', 'ilike', "%$term%")->orWhere('first_name', 'ilike', "%$term%")));
        }

        return $q->orderBy('arrival')->orderBy('id')->paginate(min((int) $request->query('per_page', 50), 200));
    }

    public function show(Request $request, Reservation $reservation)
    {
        $this->can($request, 'view');

        return $reservation->load('guest', 'company', 'roomType', 'room', 'ratePlan', 'nights');
    }

    public function store(Request $request)
    {
        $this->can($request, 'manage');
        $hotelId = app(TenantContext::class)->requireHotelId();
        $data = $request->validate([
            'guest_id' => ['required', 'integer'],
            'room_type_id' => ['required', 'integer', Rule::exists('room_types', 'id')->where('hotel_id', $hotelId)],
            'rate_plan_id' => ['required', 'integer', Rule::exists('rate_plans', 'id')->where('hotel_id', $hotelId)],
            'arrival' => ['required', 'date', 'after_or_equal:yesterday'],
            'departure' => ['required', 'date', 'after:arrival'],
            'adults' => ['sometimes', 'integer', 'between:1,12'],
            'children' => ['sometimes', 'integer', 'between:0,12'],
            'rooms' => ['sometimes', 'integer', 'between:1,50'],
            'company_id' => ['nullable', 'integer'],
            'agent_id' => ['nullable', 'integer'],
            'room_block_id' => ['nullable', 'integer'],
            'room_id' => ['nullable', 'integer'],
            'channel' => ['sometimes', Rule::in(['direct', 'phone', 'email', 'walk_in', 'website', 'app', 'corporate', 'travel_agent'])],
            'source' => ['nullable', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in(['tentative', 'confirmed'])],
            'guarantee' => ['nullable', Rule::in(['card', 'deposit', 'company', 'none'])],
            'eta' => ['nullable', 'date_format:H:i'],
            'special_requests' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        // Several rooms in one booking share a booking reference.
        $rooms = $data['rooms'] ?? 1;
        unset($data['rooms']);
        $created = [];
        $ref = null;
        foreach (range(1, $rooms) as $i) {
            $r = $this->service->create($data + ['booking_ref' => $ref]);
            $ref = $r->booking_ref;
            $created[] = $r;
            unset($data['room_id']);
        }

        return response()->json($rooms === 1 ? $created[0] : ['booking_ref' => $ref, 'data' => $created], 201);
    }

    public function update(Request $request, Reservation $reservation)
    {
        $this->can($request, 'manage');
        $data = $request->validate([
            'arrival' => ['sometimes', 'date'],
            'departure' => ['sometimes', 'date'],
            'room_type_id' => ['sometimes', 'integer'],
            'rate_plan_id' => ['sometimes', 'integer'],
            'adults' => ['sometimes', 'integer', 'between:1,12'],
            'children' => ['sometimes', 'integer', 'between:0,12'],
            'special_requests' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'eta' => ['nullable', 'date_format:H:i'],
            'guarantee' => ['nullable', 'string'],
            'company_id' => ['nullable', 'integer'],
            'agent_id' => ['nullable', 'integer'],
        ]);

        return $this->service->modify($reservation, $data);
    }

    public function cancel(Request $request, Reservation $reservation)
    {
        $this->can($request, 'manage');
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255'], 'waive_fee' => ['sometimes', 'boolean']]);
        if (($data['waive_fee'] ?? false) && ! $request->user()->can('reservations.waive_fees')) {
            abort(403, 'Waiving cancellation fees needs manager permission.');
        }

        return $this->service->cancel($reservation, $data['reason'] ?? null, $data['waive_fee'] ?? false);
    }

    public function cancellationQuote(Request $request, Reservation $reservation)
    {
        $this->can($request, 'view');

        return ['fee' => $this->service->cancellationFee($reservation), 'currency' => $reservation->currency];
    }

    public function noShow(Request $request, Reservation $reservation)
    {
        $this->can($request, 'manage');

        return $this->service->markNoShow($reservation);
    }

    public function assignRoom(Request $request, Reservation $reservation)
    {
        $this->can($request, 'manage');
        $data = $request->validate(['room_id' => ['required', 'integer']]);

        return $this->service->assignRoom($reservation, $data['room_id'])->load('room');
    }

    private function can(Request $request, string $ability): void
    {
        $u = $request->user();
        abort_unless($u->can('reservations.manage') || ($ability === 'view' && $u->can('reservations.view')), 403);
    }
}
