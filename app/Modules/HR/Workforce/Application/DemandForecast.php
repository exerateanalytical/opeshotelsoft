<?php

namespace App\Modules\HR\Workforce\Application;

use App\Modules\HR\Workforce\Domain\DeptRule;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Expected workload per day and department from reservation data (read query on the active hotel only):
 * arrivals, departures and in-house guests per day, converted to required staff by each department's rule.
 */
class DemandForecast
{
    /** Reservations that count as expected occupancy. */
    private const COUNTED = ['confirmed', 'checked_in', 'checked_out'];

    public function __construct(private TenantContext $ctx) {}

    /** @return array<string, array{arrivals: int, departures: int, inhouse: int}> */
    public function occupancy(string $from, string $to): array
    {
        $hotelId = $this->ctx->requireHotelId();
        $days = [];
        for ($d = Carbon::parse($from); $d->lte(Carbon::parse($to)); $d->addDay()) {
            $days[$d->toDateString()] = ['arrivals' => 0, 'departures' => 0, 'inhouse' => 0];
        }
        $rows = DB::table('reservations')->where('hotel_id', $hotelId)->whereIn('status', self::COUNTED)
            ->where('arrival', '<=', $to)->where('departure', '>=', $from)
            ->get(['arrival', 'departure', 'adults', 'children']);
        foreach ($rows as $r) {
            foreach ($days as $date => &$v) {
                if ($r->arrival === $date) {
                    $v['arrivals']++;
                }
                if ($r->departure === $date) {
                    $v['departures']++;
                }
                if ($r->arrival <= $date && $r->departure > $date) {
                    $v['inhouse']++;
                }
            }
            unset($v);
        }

        return $days;
    }

    /**
     * Required headcount per department per day.
     *
     * @return array<int, array{date: string, arrivals: int, departures: int, inhouse: int, departments: array<int, array{department_id: int, driver: string, units: int, required: int}>}>
     */
    public function forecast(string $from, string $to): array
    {
        $occ = $this->occupancy($from, $to);
        $rules = DeptRule::all();
        $out = [];
        foreach ($occ as $date => $o) {
            $depts = [];
            foreach ($rules as $rule) {
                $units = $rule->driver === 'none' ? 0 : (int) ($o[$rule->driver] ?? 0);
                $required = max((int) $rule->min_staff, $rule->driver === 'none' ? 0 : (int) ceil($units / max(1, (int) $rule->units_per_staff)));
                $depts[] = ['department_id' => $rule->department_id, 'driver' => $rule->driver, 'units' => $units, 'required' => $required];
            }
            $out[] = ['date' => $date] + $o + ['departments' => $depts];
        }

        return $out;
    }
}
