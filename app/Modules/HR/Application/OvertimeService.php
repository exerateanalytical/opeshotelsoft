<?php

namespace App\Modules\HR\Application;

use App\Modules\HR\Domain\Attendance;
use App\Modules\HR\Domain\Employee;
use App\Modules\HR\Domain\OvertimeEntry;
use App\Modules\HR\Domain\Shift;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Domain\DomainException;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Overtime = worked minutes outside the scheduled shift (or beyond the standard day when
 * no shift is scheduled). Each minute is classified holiday > sunday > night > weekday,
 * multipliers come from the country rule pack. Entries need manager approval before payroll.
 */
class OvertimeService
{
    public function __construct(private EventBus $bus, private TenantContext $ctx, private PublicHolidays $holidays, private PayrollService $payroll) {}

    /**
     * Pure classification of one attendance against an optional shift window.
     *
     * @return array<string, int> category => minutes
     */
    public function classify(Carbon $in, Carbon $out, ?Carbon $shiftStart, ?Carbon $shiftEnd, array $rules, callable $isHoliday): array
    {
        $result = ['weekday' => 0, 'night' => 0, 'sunday' => 0, 'holiday' => 0];
        [$nsH, $nsM] = array_map('intval', explode(':', $rules['night_start'] ?? '22:00'));
        [$neH, $neM] = array_map('intval', explode(':', $rules['night_end'] ?? '06:00'));
        $nightStart = $nsH * 60 + $nsM;
        $nightEnd = $neH * 60 + $neM;
        $standard = (int) ($rules['standard_day_minutes'] ?? 480);
        $worked = 0;

        for ($t = $in->copy(); $t->lt($out); $t->addMinute()) {
            $worked++;
            $inShift = $shiftStart && $t->gte($shiftStart) && $t->lt($shiftEnd);
            $isOt = $shiftStart ? ! $inShift : $worked > $standard;
            if (! $isOt) {
                continue;
            }
            $mod = $t->hour * 60 + $t->minute;
            $night = $nightStart > $nightEnd ? ($mod >= $nightStart || $mod < $nightEnd) : ($mod >= $nightStart && $mod < $nightEnd);
            $cat = match (true) {
                $isHoliday($t->toDateString()) => 'holiday',
                $t->isSunday() => 'sunday',
                $night => 'night',
                default => 'weekday',
            };
            $result[$cat]++;
        }

        return array_filter($result);
    }

    /** Create or refresh pending overtime entries for closed attendances in the range. */
    public function compute(string $from, string $to): array
    {
        $hotel = Hotel::findOrFail($this->ctx->requireHotelId());
        $rules = PayrollService::paramsOf($this->payroll->rulePackFor($hotel->country))['overtime'];
        $holidays = $this->holidays->between($hotel->country, Carbon::parse($from)->subDay()->toDateString(), Carbon::parse($to)->addDay()->toDateString());
        $isHoliday = fn (string $d) => isset($holidays[$d]);
        $created = [];

        DB::transaction(function () use ($from, $to, $rules, $isHoliday, &$created) {
            $attendances = Attendance::whereBetween('date', [$from, $to])->whereNotNull('clock_out')->get();
            foreach ($attendances as $a) {
                if (OvertimeEntry::where('attendance_id', $a->id)->where('status', '!=', 'pending')->exists()) {
                    continue; // already decided: never silently change an approved amount
                }
                $date = $a->date->toDateString();
                $shift = Shift::where('employee_id', $a->employee_id)->whereDate('date', $date)->orderBy('starts_at')->first();
                [$ss, $se] = [null, null];
                if ($shift) {
                    $ss = Carbon::parse($date.' '.$shift->starts_at);
                    $se = Carbon::parse($date.' '.$shift->ends_at);
                    if ($se->lte($ss)) {
                        $se->addDay(); // overnight shift
                    }
                }
                $mins = $this->classify($a->clock_in->copy(), $a->clock_out->copy(), $ss, $se, $rules, $isHoliday);
                OvertimeEntry::where('attendance_id', $a->id)->delete();
                foreach ($mins as $cat => $m) {
                    $created[] = OvertimeEntry::create(['employee_id' => $a->employee_id, 'attendance_id' => $a->id, 'date' => $date, 'category' => $cat, 'minutes' => $m]);
                }
                $a->update(['overtime_minutes' => array_sum($mins)]);
            }
        });

        return $created;
    }

    public function decide(OvertimeEntry $entry, bool $approve): OvertimeEntry
    {
        if ($entry->status !== 'pending') {
            throw new DomainException('This overtime is already decided.', 'invalid-state');
        }
        $actor = $this->ctx->actor();
        if ($actor && Employee::where('id', $entry->employee_id)->where('user_id', $actor->id)->exists()) {
            throw new DomainException('You cannot approve your own overtime.', 'sod');
        }
        $entry->update(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $actor?->id, 'decided_at' => now()]);
        $this->bus->record('hr.overtime_'.($approve ? 'approved' : 'rejected'), ['id' => $entry->id, 'employee_id' => $entry->employee_id, 'category' => $entry->category, 'minutes' => $entry->minutes], 'overtime_entry', $entry->id);

        return $entry;
    }
}
