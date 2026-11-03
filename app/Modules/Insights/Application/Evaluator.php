<?php

namespace App\Modules\Insights\Application;

use App\Platform\Events\StoredEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Leakage rules. Event-driven rules run from InsightsModule subscribers (inside the
 * event's hotel context); the others run from scan() via `insights:scan`.
 * Other modules' data is read only (query builder, always filtered by hotel or by an id from the event).
 */
class Evaluator
{
    public function __construct(private AlertService $alerts) {}

    public static function money(int $v): string
    {
        return number_format($v, 0, ',', ' ').' FCFA';
    }

    private function hotel(int $hotelId): object
    {
        return DB::table('hotels')->where('id', $hotelId)->first(['id', 'timezone', 'check_out_time', 'business_date']);
    }

    private function local(int $hotelId, $at): CarbonImmutable
    {
        return CarbonImmutable::parse($at)->setTimezone($this->hotel($hotelId)->timezone ?: 'Africa/Douala');
    }

    // ---- Event rules --------------------------------------------------------

    /** pos.line_voided after the line was sent to kitchen/bar, or a whole check voided with sent lines. */
    public function onVoid(StoredEvent $e): void
    {
        if (! ($cfg = Rules::get('void_after_fire'))) {
            return;
        }
        $p = $e->payload;
        $checkId = $p['check_id'] ?? $p['id'];
        if ($e->type === 'pos.line_voided') {
            if (empty($p['fired'])) {
                return;
            }
            $amount = (int) $p['amount'];
            $key = "line:{$p['line_id']}";
        } else {
            $fired = DB::table('pos_check_lines')->where('pos_check_id', $checkId)->whereNotNull('fired_at')->where('voided', false);
            if (! $fired->exists()) {
                return;
            }
            $amount = (int) $fired->sum('amount');
            $key = "check:$checkId";
        }
        if ($amount < $cfg['min_amount']) {
            return;
        }
        $check = DB::table('pos_checks')->where('id', $checkId)->first(['number', 'business_date']);
        $this->alerts->raise('void_after_fire', $key, "Void after sending to kitchen/bar: check #{$check?->number}", [
            'amount' => $amount, 'detail' => 'Reason: '.($p['reason'] ?? '-').' / '.self::money($amount),
            'business_date' => $check?->business_date, 'source_event_id' => $e->id,
            'evidence' => [['label' => "POS check #{$check?->number}", 'type' => 'pos_check', 'id' => $checkId, 'url' => '/pos']],
        ]);
    }

    public function onComp(StoredEvent $e): void
    {
        $p = $e->payload;
        if (! ($cfg = Rules::get('comp_above')) || (int) $p['amount'] < $cfg['min_amount']) {
            return;
        }
        $check = DB::table('pos_checks')->where('id', $p['check_id'])->first(['number', 'business_date']);
        $this->alerts->raise('comp_above', "event:{$e->id}", "Complimentary items above threshold: check #{$check?->number}", [
            'amount' => (int) $p['amount'], 'detail' => self::money((int) $p['amount']).' offered (threshold '.self::money($cfg['min_amount']).')',
            'business_date' => $check?->business_date, 'source_event_id' => $e->id,
            'evidence' => [['label' => "POS check #{$check?->number}", 'type' => 'pos_check', 'id' => $p['check_id'], 'url' => '/pos']],
        ]);
    }

    public function onSettled(StoredEvent $e): void
    {
        $p = $e->payload;
        if (str_ends_with((string) $p['number'], '-COMP')) {
            return;
        }
        $discount = (int) ($p['discount'] ?? 0);
        if ($discount > 0 && ($cfg = Rules::get('discount_above'))) {
            $base = $discount + (int) $p['total'];
            $pct = $base > 0 ? round($discount * 100 / $base, 1) : 0;
            if ($discount >= $cfg['min_amount'] || $pct >= $cfg['percent']) {
                $reason = DB::table('pos_checks')->where('id', $p['id'])->value('discount_reason');
                $this->alerts->raise('discount_above', "check:{$p['id']}", "Discount {$pct}% on check #{$p['number']}", [
                    'amount' => $discount, 'detail' => self::money($discount).' off '.self::money($base).' / '.($reason ?: '-'),
                    'business_date' => $p['business_date'], 'source_event_id' => $e->id,
                    'evidence' => [['label' => "POS check #{$p['number']}", 'type' => 'pos_check', 'id' => $p['id'], 'url' => '/pos']],
                ]);
            }
        }
        $this->afterHours($e, 'pos_check', $p['id'], "POS check #{$p['number']} settled", (int) $p['total'], $p['business_date']);
    }

    /** Move to a dearer room type while tonight keeps the old price (free upgrade). */
    public function onRoomMoved(StoredEvent $e): void
    {
        if (! Rules::get('room_move_no_charge')) {
            return;
        }
        $p = $e->payload;
        $from = DB::table('rooms')->where('hotel_id', $e->hotel_id)->where('number', $p['from_room'])->first(['room_type_id']);
        $to = DB::table('rooms')->where('hotel_id', $e->hotel_id)->where('number', $p['to_room'])->first(['room_type_id']);
        if (! $from || ! $to || $from->room_type_id === $to->room_type_id) {
            return;
        }
        $r = DB::table('reservations')->where('id', $p['reservation_id'])->first(['id', 'number', 'rate_plan_id']);
        $date = $this->hotel($e->hotel_id)->business_date ?? now()->toDateString();
        $night = (int) DB::table('reservation_nights')->where('reservation_id', $r->id)->where('date', $date)->value('amount');
        $rate = fn ($typeId) => (int) (DB::table('rates')->where('rate_plan_id', $r->rate_plan_id)->where('room_type_id', $typeId)->where('date', $date)->value('amount')
            ?: DB::table('room_types')->where('id', $typeId)->value('base_rate'));
        $newRate = $rate($to->room_type_id);
        if ($newRate <= $night || $newRate <= $rate($from->room_type_id)) {
            return; // downgrade, same price level, or re-rated
        }
        $this->alerts->raise('room_move_no_charge', "event:{$e->id}", "Room move {$p['from_room']} to {$p['to_room']} without rate change", [
            'amount' => $newRate - $night, 'detail' => 'Night charged '.self::money($night).', new room rate '.self::money($newRate).' / '.($p['reason'] ?? ''),
            'business_date' => $date, 'source_event_id' => $e->id,
            'evidence' => [['label' => "Reservation {$r->number}", 'type' => 'reservation', 'id' => $r->id, 'url' => "/reservations/{$r->id}"]],
        ]);
    }

    public function onShiftClosed(StoredEvent $e): void
    {
        $p = $e->payload;
        if (! ($cfg = Rules::get('cashier_variance')) || abs((int) $p['variance']) < max(1, $cfg['min_amount'])) {
            return;
        }
        $date = DB::table('cashier_shifts')->where('id', $p['id'])->value('business_date');
        $who = DB::table('users')->where('id', $p['user_id'])->value('name');
        $v = (int) $p['variance'];
        $this->alerts->raise('cashier_variance', "shift:{$p['id']}", ($v < 0 ? 'Cash short' : 'Cash over')." on shift #{$p['id']} ($who)", [
            'amount' => abs($v), 'severity' => $v < 0 ? 'high' : 'medium', 'detail' => 'Variance '.self::money($v),
            'business_date' => $date, 'source_event_id' => $e->id,
            'evidence' => [['label' => "Cashier shift #{$p['id']}", 'type' => 'cashier_shift', 'id' => $p['id'], 'url' => '/billing/cashier']],
        ]);
    }

    /** Checked out after check-out time + grace with no late check-out fee on the stay's folios. */
    public function onCheckedOut(StoredEvent $e): void
    {
        if (! ($cfg = Rules::get('late_checkout_uncharged'))) {
            return;
        }
        $r = DB::table('reservations')->where('id', $e->payload['id'])->first(['id', 'number', 'checked_out_at']);
        if (! $r?->checked_out_at) {
            return;
        }
        $hotel = $this->hotel($e->hotel_id);
        $out = $this->local($e->hotel_id, $r->checked_out_at);
        $due = $out->setTimeFromTimeString($hotel->check_out_time ?: '12:00')->addMinutes($cfg['grace_minutes']);
        if ($out->lessThanOrEqualTo($due)) {
            return;
        }
        $codes = DB::table('checkout_fee_rules')->where('hotel_id', $e->hotel_id)->where('kind', 'late')->pluck('charge_code')->all();
        $charged = DB::table('folio_lines')->join('folios', 'folios.id', '=', 'folio_lines.folio_id')
            ->where('folios.reservation_id', $r->id)->where('folio_lines.type', 'charge')->where('folio_lines.reversed', false)
            ->where(fn ($q) => $q->whereIn('folio_lines.code', $codes)->orWhere('folio_lines.description', 'ilike', '%late%')->orWhere('folio_lines.description', 'ilike', '%tardi%'))
            ->exists();
        if ($charged) {
            return;
        }
        $this->alerts->raise('late_checkout_uncharged', "reservation:{$r->id}", "Late check-out at {$out->format('H:i')} not charged: {$r->number}", [
            'detail' => 'Check-out time '.substr($hotel->check_out_time ?: '12:00', 0, 5).' + '.$cfg['grace_minutes'].' min grace',
            'business_date' => $hotel->business_date, 'source_event_id' => $e->id,
            'evidence' => [['label' => "Reservation {$r->number}", 'type' => 'reservation', 'id' => $r->id, 'url' => "/reservations/{$r->id}"]],
        ]);
    }

    /** Folio postings: reversals of posted charges (bill reopened and corrected) and after-hours postings. */
    public function onFolioLine(StoredEvent $e): void
    {
        $p = $e->payload;
        $evidence = [['label' => "Folio {$p['folio_number']}", 'type' => 'folio', 'id' => $p['folio_id'], 'url' => '/billing/folios']];
        if ($p['type'] === 'reversal' && ($cfg = Rules::get('reopened_check')) && abs((int) $p['amount']) >= $cfg['min_amount']) {
            $reason = DB::table('folio_lines')->where('id', $p['line_id'])->value('reason');
            $this->alerts->raise('reopened_check', "folio_line:{$p['line_id']}", "Posted charge reversed on folio {$p['folio_number']}", [
                'amount' => abs((int) $p['amount']), 'detail' => $p['description'].' / '.($reason ?: '-'),
                'business_date' => $p['business_date'], 'source_event_id' => $e->id, 'evidence' => $evidence,
            ]);
        }
        // Room nights are posted by the night audit, which legitimately runs late.
        if (in_array($p['type'], ['charge', 'reversal', 'refund'], true) && ($p['department'] ?? null) !== 'rooms') {
            $this->afterHours($e, 'folio', $p['folio_id'], "{$p['description']} on folio {$p['folio_number']}", abs((int) $p['amount']), $p['business_date'], $evidence, "folio_line:{$p['line_id']}");
        }
    }

    /** POS has no reopen action yet; once it emits pos.check_reopened this raises the alert. */
    public function onCheckReopened(StoredEvent $e): void
    {
        if (! Rules::get('reopened_check')) {
            return;
        }
        $p = $e->payload;
        $label = 'POS check #'.($p['number'] ?? $p['id']);
        $this->alerts->raise('reopened_check', "pos_check:{$p['id']}:{$e->id}", "Settled $label reopened", [
            'amount' => (int) ($p['total'] ?? 0), 'detail' => $p['reason'] ?? null, 'source_event_id' => $e->id,
            'evidence' => [['label' => $label, 'type' => 'pos_check', 'id' => $p['id'], 'url' => '/pos']],
        ]);
    }

    private function afterHours(StoredEvent $e, string $type, $id, string $what, int $amount, ?string $date, ?array $evidence = null, ?string $key = null): void
    {
        if (! ($cfg = Rules::get('after_hours_posting'))) {
            return;
        }
        $t = $this->local($e->hotel_id, $e->occurred_at)->format('H:i');
        $inside = $cfg['start'] <= $cfg['end'] ? ($t >= $cfg['start'] && $t < $cfg['end']) : ($t >= $cfg['start'] || $t < $cfg['end']);
        if (! $inside) {
            return;
        }
        $this->alerts->raise('after_hours_posting', $key ?? "$type:$id", "After-hours posting at $t: $what", [
            'amount' => $amount, 'detail' => "Window {$cfg['start']}-{$cfg['end']}", 'business_date' => $date, 'source_event_id' => $e->id,
            'evidence' => $evidence ?? [['label' => ucfirst(str_replace('_', ' ', $type))." #$id", 'type' => $type, 'id' => $id, 'url' => '/pos']],
        ]);
    }

    // ---- Scheduled rules ----------------------------------------------------

    /** Run the scan rules for the active hotel and a business date. Returns the number of findings. */
    public function scan(int $hotelId, string $date): int
    {
        $rules = Rules::forHotel();
        $n = 0;
        if ($rules['occupied_no_posting']['enabled']) {
            $n += $this->occupiedNoPosting($hotelId, $date);
        }
        if ($rules['rate_below_floor']['enabled']) {
            $n += $this->rateBelowFloor($hotelId, $date, $rules['rate_below_floor']);
        }
        if ($rules['bar_stock_gap']['enabled']) {
            $n += $this->barStockGap($hotelId, $date, $rules['bar_stock_gap']);
        }

        return $n;
    }

    /** In-house for the night of $date with no room charge posted on that business date. */
    private function occupiedNoPosting(int $hotelId, string $date): int
    {
        $rows = DB::table('reservations')->where('hotel_id', $hotelId)->where('status', 'checked_in')
            ->where('arrival', '<=', $date)->where('departure', '>', $date)
            ->whereNotExists(fn ($q) => $q->from('folio_lines')->join('folios', 'folios.id', '=', 'folio_lines.folio_id')
                ->whereColumn('folios.reservation_id', 'reservations.id')->where('folio_lines.business_date', $date)
                ->where('folio_lines.type', 'charge')->where('folio_lines.department', 'rooms'))
            ->get(['id', 'number', 'room_id']);
        foreach ($rows as $r) {
            $room = DB::table('rooms')->where('id', $r->room_id)->value('number');
            $amount = (int) DB::table('reservation_nights')->where('reservation_id', $r->id)->where('date', $date)->value('amount');
            $this->alerts->raise('occupied_no_posting', "reservation:{$r->id}:$date", "Room $room occupied with no room charge on $date", [
                'amount' => $amount, 'detail' => "Reservation {$r->number}", 'business_date' => $date,
                'evidence' => [['label' => "Reservation {$r->number}", 'type' => 'reservation', 'id' => $r->id, 'url' => "/reservations/{$r->id}"]],
            ]);
        }

        return $rows->count();
    }

    /** Night price below the rate grid by more than X %, or below an absolute floor. */
    private function rateBelowFloor(int $hotelId, string $date, array $cfg): int
    {
        $rows = DB::table('reservation_nights as n')->join('reservations as r', 'r.id', '=', 'n.reservation_id')
            ->leftJoin('rates as g', fn ($j) => $j->on('g.rate_plan_id', '=', 'r.rate_plan_id')->on('g.room_type_id', '=', 'r.room_type_id')->on('g.date', '=', 'n.date'))
            ->join('room_types as t', 't.id', '=', 'r.room_type_id')
            ->where('r.hotel_id', $hotelId)->where('n.date', $date)->whereIn('r.status', ['checked_in', 'confirmed', 'checked_out'])
            ->get(['r.id', 'r.number', 'n.amount', 'g.amount as grid', 't.base_rate']);
        $n = 0;
        foreach ($rows as $row) {
            $grid = (int) ($row->grid ?: $row->base_rate);
            $floor = max((int) $cfg['floor_amount'], (int) ceil($grid * (100 - $cfg['percent']) / 100));
            if ($grid <= 0 || (int) $row->amount >= $floor) {
                continue;
            }
            $n++;
            $this->alerts->raise('rate_below_floor', "reservation:{$row->id}:$date", "Rate below floor on {$row->number}: ".self::money((int) $row->amount), [
                'amount' => $grid - (int) $row->amount, 'detail' => 'Grid '.self::money($grid).', floor '.self::money($floor), 'business_date' => $date,
                'evidence' => [['label' => "Reservation {$row->number}", 'type' => 'reservation', 'id' => $row->id, 'url' => "/reservations/{$row->id}"]],
            ]);
        }

        return $n;
    }

    /** Bar stores: stock lost outside sales (wastage, count shortfalls) against POS sales at cost on the day. */
    private function barStockGap(int $hotelId, string $date, array $cfg): int
    {
        $stores = DB::table('stores')->where('hotel_id', $hotelId)->where('kind', 'bar')->get(['id', 'code', 'name']);
        $n = 0;
        foreach ($stores as $s) {
            $moves = DB::table('stock_movements')->where('hotel_id', $hotelId)->where('date', $date)->where('from_store_id', $s->id);
            $sold = (int) abs((clone $moves)->where('kind', 'sale')->sum('value'));
            $lost = (int) abs((clone $moves)->whereIn('kind', ['wastage', 'adjustment'])->sum('value'));
            $pct = $sold > 0 ? round($lost * 100 / $sold, 1) : 100.0;
            if ($lost <= 0 || $lost < $cfg['min_amount'] || $pct < $cfg['percent']) {
                continue;
            }
            $n++;
            $this->alerts->raise('bar_stock_gap', "store:{$s->id}:$date", "{$s->name}: stock used beyond POS sales ({$pct}%)", [
                'amount' => $lost, 'severity' => $pct >= 2 * $cfg['percent'] ? 'high' : 'medium', 'business_date' => $date,
                'detail' => 'Unexplained consumption '.self::money($lost).' at cost vs POS sales '.self::money($sold).' at cost',
                'evidence' => [['label' => "Store {$s->code}", 'type' => 'store', 'id' => $s->id, 'url' => '/stock']],
            ]);
        }

        return $n;
    }
}
