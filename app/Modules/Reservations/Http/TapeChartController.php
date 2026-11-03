<?php

namespace App\Modules\Reservations\Http;

use App\Modules\Property\Domain\Room;
use App\Modules\Reservations\Domain\Reservation;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** GET reservations/tape-chart?from&to — rooms, reservation bars and out-of-order blocks in one call. */
class TapeChartController
{
    public function __invoke(Request $request)
    {
        $u = $request->user();
        abort_unless($u->can('reservations.manage') || $u->can('reservations.view'), 403);
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after:from']]);
        $from = Carbon::parse($data['from'])->startOfDay();
        $to = Carbon::parse($data['to'])->startOfDay();
        abort_if($from->diffInDays($to) > 62, 422, 'At most 62 days at a time.');
        $hotelId = app(TenantContext::class)->requireHotelId();

        $rooms = Room::with('roomType:id,code,name')->where('active', true)->get()
            ->sortBy(fn ($r) => [$r->roomType->code ?? '', str_pad($r->number, 10, '0', STR_PAD_LEFT)])->values()
            ->map(fn (Room $r) => [
                'id' => $r->id, 'number' => $r->number, 'floor' => $r->floor, 'hk_status' => $r->hk_status, 'occupied' => (bool) $r->occupied,
                'room_type_id' => $r->room_type_id, 'room_type' => $r->roomType?->only(['id', 'code', 'name']),
            ]);

        $bars = Reservation::with('guest:id,first_name,last_name,vip')
            ->whereIn('status', ['tentative', 'confirmed', 'checked_in', 'checked_out'])
            ->where('arrival', '<', $to->toDateString())->where('departure', '>', $from->toDateString())
            ->orderBy('arrival')->get()
            ->map(fn (Reservation $r) => [
                'id' => $r->id, 'number' => $r->number, 'status' => $r->status, 'room_id' => $r->room_id, 'room_type_id' => $r->room_type_id,
                'arrival' => $r->arrival->toDateString(), 'departure' => $r->departure->toDateString(),
                'guest' => trim(($r->guest->first_name ?? '').' '.($r->guest->last_name ?? '')), 'vip' => (bool) ($r->guest->vip ?? false),
                'adults' => $r->adults, 'children' => $r->children,
            ]);

        // Out-of-order blocks: open work orders that took the room out of order, plus rooms flagged OOO without one.
        $blocks = DB::table('work_orders')->where('hotel_id', $hotelId)->where('room_out_of_order', true)
            ->whereNotIn('status', ['done', 'cancelled'])->whereNotNull('room_id')
            ->where('created_at', '<', $to)->where(fn ($q) => $q->whereNull('due_at')->orWhere('due_at', '>=', $from))
            ->get(['id', 'number', 'room_id', 'title', 'created_at', 'due_at'])
            ->map(fn ($w) => [
                'room_id' => $w->room_id, 'from' => Carbon::parse($w->created_at)->toDateString(),
                'to' => Carbon::parse($w->due_at ?? $to)->addDay()->toDateString(), 'reason' => "{$w->number} {$w->title}",
            ]);
        $covered = $blocks->pluck('room_id')->all();
        foreach ($rooms as $r) {
            if (in_array($r['hk_status'], ['out_of_order', 'out_of_service'], true) && ! in_array($r['id'], $covered, true)) {
                $blocks->push(['room_id' => $r['id'], 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'reason' => str_replace('_', ' ', $r['hk_status'])]);
            }
        }

        return [
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'rooms' => $rooms, 'bars' => $bars, 'out_of_order' => $blocks->values(),
        ];
    }
}
