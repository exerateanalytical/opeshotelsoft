<?php

namespace App\Modules\Insights\Application;

use App\Modules\Insights\Domain\Alert;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Owner KPIs for one hotel and business date, in a handful of aggregate queries so the
 * mobile owner page stays fast. Amounts are gross (TTC) in FCFA.
 * Revenue = folio charges/reversals (room charges from POS included once) + POS checks paid directly.
 */
class OwnerMetrics
{
    public function __construct(private TenantContext $ctx) {}

    public function forHotel(int $hotelId, string $date): array
    {
        $folio = DB::table('folio_lines')->join('folios', 'folios.id', '=', 'folio_lines.folio_id')
            ->where('folios.hotel_id', $hotelId)->where('folio_lines.business_date', $date)
            ->selectRaw("coalesce(sum(case when folio_lines.type in ('charge','reversal') then folio_lines.amount end),0) as charges")
            ->selectRaw("coalesce(sum(case when folio_lines.type in ('charge','reversal') and folio_lines.department = 'rooms' then folio_lines.amount end),0) as rooms")
            ->first();
        $pos = (int) DB::table('pos_checks')->where('hotel_id', $hotelId)->where('business_date', $date)
            ->where('status', 'settled')->where('settlement', 'payment')->sum('total');

        $rooms = (int) DB::table('rooms')->where('hotel_id', $hotelId)->where('active', true)->count();
        $occupied = (int) DB::table('reservations')->where('hotel_id', $hotelId)->whereIn('status', ['checked_in', 'checked_out'])
            ->where('arrival', '<=', $date)->where('departure', '>', $date)->count();

        $cash = DB::table('folio_lines')->join('folios', 'folios.id', '=', 'folio_lines.folio_id')
            ->where('folios.hotel_id', $hotelId)->where('folio_lines.business_date', $date)->whereIn('folio_lines.type', ['payment', 'refund'])
            ->groupBy('folio_lines.code')->selectRaw('folio_lines.code as method, -sum(folio_lines.amount) as amount')->get()
            ->concat(DB::table('pos_payments')->join('pos_checks', 'pos_checks.id', '=', 'pos_payments.pos_check_id')
                ->where('pos_checks.hotel_id', $hotelId)->where('pos_checks.business_date', $date)->where('pos_checks.status', 'settled')
                ->groupBy('pos_payments.method_code')->selectRaw('pos_payments.method_code as method, sum(pos_payments.amount) as amount')->get())
            ->groupBy('method')->map(fn ($g, $m) => ['method' => (string) $m, 'amount' => (int) $g->sum('amount')])->sortByDesc('amount')->values()->all();

        $roomRevenue = (int) $folio->rooms;

        return [
            'hotel_id' => $hotelId, 'date' => $date,
            'revenue' => (int) $folio->charges + $pos, 'room_revenue' => $roomRevenue, 'pos_revenue' => $pos,
            'rooms' => $rooms, 'occupied' => $occupied,
            'occupancy' => $rooms > 0 ? round($occupied * 100 / $rooms, 1) : 0.0,
            'adr' => $occupied > 0 ? intdiv($roomRevenue, $occupied) : 0,
            'revpar' => $rooms > 0 ? intdiv($roomRevenue, $rooms) : 0,
            'cash_by_method' => $cash,
            'open_alerts' => Alert::withoutGlobalScopes()->where('hotel_id', $hotelId)->where('status', 'open')->count(),
            'top_alerts' => $this->topAlerts([$hotelId], 5),
        ];
    }

    /** Open alerts, most serious and costly first. */
    public function topAlerts(array $hotelIds, int $limit): array
    {
        return Alert::withoutGlobalScopes()->whereIn('hotel_id', $hotelIds)->where('status', 'open')
            ->orderByRaw("case severity when 'high' then 0 when 'medium' then 1 else 2 end")->orderByDesc('amount')->orderByDesc('id')
            ->limit($limit)->get(['id', 'hotel_id', 'rule', 'severity', 'title', 'amount', 'occurred_at'])->toArray();
    }

    /** One row per hotel the actor may see, plus group totals. */
    public function group(string $date, ?array $hotelIds = null): array
    {
        $hotels = DB::table('hotels')->where('group_id', $this->ctx->groupId())
            ->when($hotelIds !== null, fn ($q) => $q->whereIn('id', $hotelIds))->orderBy('name')->get(['id', 'code', 'name', 'business_date']);
        $rows = $hotels->map(fn ($h) => ['code' => $h->code, 'name' => $h->name] + $this->forHotel($h->id, $date ?: ($h->business_date ?? now()->toDateString())))->all();
        $c = collect($rows);
        $rooms = (int) $c->sum('rooms');
        $occ = (int) $c->sum('occupied');

        return [
            'date' => $date, 'hotels' => $rows,
            'totals' => [
                'revenue' => (int) $c->sum('revenue'), 'room_revenue' => (int) $c->sum('room_revenue'), 'rooms' => $rooms, 'occupied' => $occ,
                'occupancy' => $rooms > 0 ? round($occ * 100 / $rooms, 1) : 0.0, 'adr' => $occ > 0 ? intdiv((int) $c->sum('room_revenue'), $occ) : 0,
                'open_alerts' => (int) $c->sum('open_alerts'),
            ],
            'top_alerts' => $this->topAlerts($hotels->pluck('id')->all(), 10),
        ];
    }
}
