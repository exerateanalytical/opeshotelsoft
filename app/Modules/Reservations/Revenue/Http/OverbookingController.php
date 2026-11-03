<?php

namespace App\Modules\Reservations\Revenue\Http;

use App\Modules\Property\Domain\RoomType;
use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Reservations\Revenue\Application\OverbookingService;
use App\Modules\Reservations\Revenue\Domain\OverbookingPolicy;
use App\Modules\Reservations\Revenue\Domain\WalkRecord;
use Illuminate\Http\Request;

/** Overbooking caps per room type, applying limits to dates, the exposure list and walking guests. */
class OverbookingController extends RevenueController
{
    public function policies(Request $request, OverbookingService $svc)
    {
        $this->need($request, 'revenue.view', 'revenue.manage');
        $policies = OverbookingPolicy::get()->keyBy('room_type_id');

        return ['data' => RoomType::where('active', true)->orderBy('sort')->get()->map(fn ($t) => [
            'room_type_id' => $t->id, 'room_type' => $t->name, 'max_rooms' => $policies[$t->id]->max_rooms ?? 0,
            'max_percent' => $policies[$t->id]->max_percent ?? 0, 'notes' => $policies[$t->id]->notes ?? null, 'cap' => $svc->cap($t->id),
        ])->values()];
    }

    public function savePolicy(Request $request, RoomType $roomType, OverbookingService $svc)
    {
        $this->need($request, 'revenue.manage');
        $data = $request->validate([
            'max_rooms' => ['required', 'integer', 'between:0,200'], 'max_percent' => ['required', 'numeric', 'between:0,50'], 'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $p = OverbookingPolicy::updateOrCreate(['room_type_id' => $roomType->id], $data);

        return $p->toArray() + ['cap' => $svc->cap($roomType->id)];
    }

    public function apply(Request $request, OverbookingService $svc)
    {
        $this->need($request, 'revenue.manage');
        $data = $request->validate([
            'room_type_id' => ['required', 'integer'], 'from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from', 'before:'.now()->addDays(400)->toDateString()],
            'limit' => ['required', 'integer', 'min:0', 'max:200'],
        ]);

        return ['applied' => $svc->apply($data['room_type_id'], $data['from'], $data['to'], $data['limit']), 'requested' => $data['limit']];
    }

    public function exposure(Request $request, OverbookingService $svc)
    {
        $this->need($request, 'revenue.view', 'revenue.manage', 'frontdesk.manage');
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from', 'before:'.now()->addDays(400)->toDateString()]]);

        return ['data' => $svc->exposure($data['from'], $data['to'])];
    }

    public function walks(Request $request)
    {
        $this->need($request, 'revenue.view', 'revenue.manage', 'frontdesk.manage');

        return WalkRecord::orderByDesc('id')->paginate(50);
    }

    public function walk(Request $request, Reservation $reservation, OverbookingService $svc)
    {
        $this->need($request, 'revenue.upsell', 'revenue.manage', 'frontdesk.manage');
        $data = $request->validate(['partner_hotel' => ['nullable', 'string', 'max:150'], 'compensation' => ['nullable', 'integer', 'min:0'], 'note' => ['nullable', 'string', 'max:1000']]);

        return response()->json($svc->walk($reservation, $request->user(), $data['partner_hotel'] ?? null, $data['compensation'] ?? null, $data['note'] ?? null), 201);
    }
}
