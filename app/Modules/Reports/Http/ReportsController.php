<?php

namespace App\Modules\Reports\Http;

use App\Modules\FrontOffice\Domain\FolioLine;
use App\Modules\Property\Domain\Room;
use App\Modules\Property\Domain\RoomType;
use App\Modules\Reservations\Application\Inventory;
use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Tenancy\TenantContext;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Read-side KPIs for hotel and group dashboards (USALI definitions). */
class ReportsController
{
    public function __construct(private TenantContext $ctx) {}

    public function dashboard(Request $request, Inventory $inventory)
    {
        $this->can($request);
        $hotel = Hotel::findOrFail($this->ctx->requireHotelId());

        return $this->hotelKpis($hotel, $inventory);
    }

    /** Group view: KPIs per hotel and totals for all hotels the user can access. */
    public function group(Request $request, Inventory $inventory)
    {
        $this->can($request);
        $rows = [];
        $allowed = $this->ctx->allowedHotelIds(); // null means every hotel in the group
        foreach (Hotel::when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed))->orderBy('name')->get() as $hotel) {
            $saved = [$this->ctx->groupId(), $this->ctx->hotelId(), $this->ctx->allowedHotelIds(), $this->ctx->actor()];
            $this->ctx->set($hotel->group_id, $hotel->id, $saved[2], $saved[3]);
            try {
                $k = $this->hotelKpis($hotel, $inventory, withSeries: false);
            } finally {
                $this->ctx->set(...$saved);
            }
            $rows[] = ['hotel' => $hotel->only(['id', 'code', 'name', 'city'])] + $k['today'];
        }
        $rooms = array_sum(array_column($rows, 'rooms_available'));
        $sold = array_sum(array_column($rows, 'rooms_sold'));
        $rev = array_sum(array_column($rows, 'room_revenue'));

        return [
            'hotels' => $rows,
            'total' => [
                'rooms_available' => $rooms, 'rooms_sold' => $sold,
                'occupancy_percent' => $rooms ? round($sold / $rooms * 100, 1) : 0,
                'room_revenue' => $rev, 'total_revenue' => array_sum(array_column($rows, 'total_revenue')),
                'adr' => $sold ? intdiv($rev, $sold) : 0, 'revpar' => $rooms ? intdiv($rev, $rooms) : 0,
                'arrivals' => array_sum(array_column($rows, 'arrivals')), 'departures' => array_sum(array_column($rows, 'departures')),
            ],
        ];
    }

    /** Occupancy & revenue on the books for the next N days (pickup / forecast). */
    public function forecast(Request $request, Inventory $inventory)
    {
        $this->can($request);
        $hotel = Hotel::findOrFail($this->ctx->requireHotelId());
        $days = min((int) $request->query('days', 30), 365);
        $from = $hotel->business_date->toDateString();
        $to = $hotel->business_date->copy()->addDays($days - 1)->toDateString();
        $types = RoomType::where('active', true)->get();
        $capacity = Room::where('active', true)->count();

        $sold = [];
        foreach ($types as $t) {
            foreach ($inventory->grid($hotel->id, $t->id, $from, $to) as $d => $g) {
                $sold[$d] = ($sold[$d] ?? 0) + $g['sold'];
            }
        }
        $revenue = DB::table('reservation_nights as n')->join('reservations as r', 'r.id', '=', 'n.reservation_id')
            ->where('r.hotel_id', $hotel->id)->whereIn('r.status', Reservation::ACTIVE)->whereBetween('n.date', [$from, $to])
            ->selectRaw('n.date, sum(n.amount) as revenue')->groupBy('n.date')->pluck('revenue', 'date');

        return collect(CarbonPeriod::create($from, $to))->map(function ($day) use ($sold, $revenue, $capacity) {
            $d = $day->toDateString();
            $s = $sold[$d] ?? 0;
            $rev = (int) ($revenue[$d] ?? $revenue[$d.' 00:00:00'] ?? 0);

            return ['date' => $d, 'rooms_sold' => $s, 'occupancy_percent' => $capacity ? round($s / $capacity * 100, 1) : 0, 'room_revenue' => $rev, 'adr' => $s ? intdiv($rev, $s) : 0];
        })->values();
    }

    /** Production by channel / segment over a period. */
    public function channels(Request $request)
    {
        $this->can($request);
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date']]);

        return DB::table('reservation_nights as n')->join('reservations as r', 'r.id', '=', 'n.reservation_id')
            ->where('r.hotel_id', $this->ctx->requireHotelId())->whereNotIn('r.status', ['cancelled', 'no_show'])
            ->whereBetween('n.date', [$data['from'], $data['to']])
            ->selectRaw('r.channel, count(*) as room_nights, sum(n.amount) as revenue, count(distinct r.id) as bookings')
            ->groupBy('r.channel')->orderByDesc('revenue')->get();
    }

    private function hotelKpis(Hotel $hotel, Inventory $inventory, bool $withSeries = true): array
    {
        $d = $hotel->business_date->toDateString();
        $rooms = Room::where('active', true)->count();
        $ooo = Room::where('hk_status', 'out_of_order')->count();
        $available = max(0, $rooms - $ooo);
        $inHouse = Reservation::where('status', 'checked_in')->count();
        $roomRev = (int) DB::table('reservation_nights as n')->join('reservations as r', 'r.id', '=', 'n.reservation_id')
            ->where('r.hotel_id', $hotel->id)->whereIn('r.status', ['checked_in', 'checked_out'])->whereDate('n.date', $d)->sum('n.amount');
        $totalRev = (int) FolioLine::where('hotel_id', $hotel->id)->whereDate('business_date', $d)->whereIn('type', ['charge', 'reversal'])->sum('amount')
            + (int) DB::table('pos_checks')->where('hotel_id', $hotel->id)->whereDate('business_date', $d)->where('status', 'settled')->where('settlement', 'payment')->sum('total');

        $today = [
            'business_date' => $d,
            'rooms_total' => $rooms, 'out_of_order' => $ooo, 'rooms_available' => $available, 'rooms_sold' => $inHouse,
            'occupancy_percent' => $available ? round($inHouse / $available * 100, 1) : 0,
            'room_revenue' => $roomRev, 'total_revenue' => $totalRev,
            'adr' => $inHouse ? intdiv($roomRev, $inHouse) : 0, 'revpar' => $available ? intdiv($roomRev, $available) : 0,
            'arrivals' => Reservation::whereIn('status', ['confirmed', 'tentative'])->whereDate('arrival', $d)->count(),
            'departures' => Reservation::where('status', 'checked_in')->whereDate('departure', '<=', $d)->count(),
            'dirty_rooms' => Room::whereIn('hk_status', ['vacant_dirty', 'occupied_dirty'])->count(),
            'open_work_orders' => DB::table('work_orders')->where('hotel_id', $hotel->id)->whereNotIn('status', ['done', 'cancelled'])->count(),
            'guest_ledger' => (int) DB::table('folios')->where('hotel_id', $hotel->id)->where('status', 'open')->where('kind', '!=', 'city_ledger')->sum('balance'),
            'city_ledger' => (int) DB::table('folios')->where('hotel_id', $hotel->id)->where('kind', 'city_ledger')->sum('balance'),
        ];
        if (! $withSeries) {
            return ['today' => $today];
        }

        $history = DB::table('business_days')->where('hotel_id', $hotel->id)->where('status', 'closed')->orderByDesc('date')->limit(30)->get(['date', 'summary'])
            ->map(fn ($r) => ['date' => substr($r->date, 0, 10)] + array_intersect_key(json_decode($r->summary, true) ?? [], array_flip(['occupancy_percent', 'room_revenue', 'total_revenue', 'adr', 'revpar'])))
            ->reverse()->values();

        return ['today' => $today, 'last_30_days' => $history];
    }

    private function can(Request $request): void
    {
        $u = $request->user();
        abort_unless($u->can('reports.view') || $u->can('reports.manage') || $u->can('frontdesk.manage'), 403);
    }
}
