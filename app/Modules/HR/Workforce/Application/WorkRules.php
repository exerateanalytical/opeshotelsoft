<?php

namespace App\Modules\HR\Workforce\Application;

use Illuminate\Support\Carbon;

/**
 * Pure labour-law checks on a list of shifts (Cameroon defaults, tunable per hotel in WorkforceSettings):
 * rest between shifts, daily and weekly hours, weekly rest day, consecutive night shifts.
 * A shift is ['date' => 'Y-m-d', 'starts_at' => 'H:i', 'ends_at' => 'H:i'].
 */
class WorkRules
{
    public const DEFAULTS = [
        'rest_hours_min' => 11, 'max_weekly_hours' => 48, 'max_daily_hours' => 10, 'max_days_per_week' => 6,
        'max_consecutive_nights' => 5, 'night_start' => '22:00', 'night_end' => '06:00',
    ];

    public static function window(array $s): array
    {
        $start = Carbon::parse($s['date'].' '.$s['starts_at']);
        $end = Carbon::parse($s['date'].' '.$s['ends_at']);
        if ($end->lte($start)) {
            $end->addDay(); // overnight shift
        }

        return [$start, $end];
    }

    public static function minutes(array $s): int
    {
        [$a, $b] = self::window($s);

        return (int) $a->diffInMinutes($b);
    }

    /** Minutes of the shift inside the night window. */
    public static function nightMinutes(array $s, array $cfg): int
    {
        [$a, $b] = self::window($s);
        [$nh, $nm] = array_map('intval', explode(':', $cfg['night_start']));
        [$eh, $em] = array_map('intval', explode(':', $cfg['night_end']));
        $n = 0;
        for ($t = $a->copy(); $t->lt($b); $t->addMinutes(15)) {
            $m = $t->hour * 60 + $t->minute;
            $s0 = $nh * 60 + $nm;
            $e0 = $eh * 60 + $em;
            $n += ($s0 > $e0 ? ($m >= $s0 || $m < $e0) : ($m >= $s0 && $m < $e0)) ? 15 : 0;
        }

        return $n;
    }

    public static function isNight(array $s, array $cfg): bool
    {
        return self::nightMinutes($s, $cfg) >= 180; // 3h or more inside the night window
    }

    /**
     * @param  array<int, array{date: string, starts_at: string, ends_at: string}>  $shifts  one employee's shifts
     * @return array<int, array{rule: string, date: string, message: string}>
     */
    public static function violations(array $shifts, array $cfg = []): array
    {
        $cfg += self::DEFAULTS;
        usort($shifts, fn ($a, $b) => [$a['date'], $a['starts_at']] <=> [$b['date'], $b['starts_at']]);
        $out = [];
        $perDay = [];
        $perWeek = [];
        $daysPerWeek = [];
        $prevEnd = null;
        foreach ($shifts as $s) {
            [$start, $end] = self::window($s);
            $mins = (int) $start->diffInMinutes($end);
            $perDay[$s['date']] = ($perDay[$s['date']] ?? 0) + $mins;
            $week = Carbon::parse($s['date'])->startOfWeek()->toDateString();
            $perWeek[$week] = ($perWeek[$week] ?? 0) + $mins;
            $daysPerWeek[$week][$s['date']] = true;
            if ($prevEnd) {
                if ($start->lt($prevEnd)) {
                    $out[] = ['rule' => 'overlap', 'date' => $s['date'], 'message' => 'Overlapping shifts.'];
                } elseif ($prevEnd->diffInMinutes($start) < $cfg['rest_hours_min'] * 60) {
                    $out[] = ['rule' => 'rest', 'date' => $s['date'], 'message' => 'Less than '.$cfg['rest_hours_min'].'h rest between shifts.'];
                }
            }
            $prevEnd = $prevEnd && $prevEnd->gt($end) ? $prevEnd : $end;
        }
        foreach ($perDay as $d => $m) {
            if ($m > $cfg['max_daily_hours'] * 60) {
                $out[] = ['rule' => 'daily_hours', 'date' => $d, 'message' => 'More than '.$cfg['max_daily_hours'].'h in one day.'];
            }
        }
        foreach ($perWeek as $w => $m) {
            if ($m > $cfg['max_weekly_hours'] * 60) {
                $out[] = ['rule' => 'weekly_hours', 'date' => $w, 'message' => 'More than '.$cfg['max_weekly_hours'].'h in the week.'];
            }
            if (count($daysPerWeek[$w]) > $cfg['max_days_per_week']) {
                $out[] = ['rule' => 'weekly_rest', 'date' => $w, 'message' => 'No weekly rest day.'];
            }
        }
        // consecutive night shifts
        $nightDates = collect($shifts)->filter(fn ($s) => self::isNight($s, $cfg))->pluck('date')->unique()->sort()->values();
        $run = 0;
        $last = null;
        foreach ($nightDates as $d) {
            $run = $last && Carbon::parse($last)->addDay()->toDateString() === $d ? $run + 1 : 1;
            if ($run > $cfg['max_consecutive_nights']) {
                $out[] = ['rule' => 'night', 'date' => $d, 'message' => 'More than '.$cfg['max_consecutive_nights'].' consecutive night shifts.'];
            }
            $last = $d;
        }

        return $out;
    }

    /**
     * Greedy auto-rostering.
     *
     * @param  array<int, array{date: string, department_id: int, template_id: ?int, starts_at: string, ends_at: string, headcount: int, role?: ?string}>  $slots
     * @param  array<int, array{id: int, department_id: ?int, hours_per_week: int}>  $employees
     * @param  array<int, array<int, string>>  $absent  employee_id => dates on leave
     * @param  array<int, array<int, array>>  $existing  employee_id => shifts already planned outside this roster (neighbouring weeks)
     * @return array{shifts: array<int, array>, warnings: array<int, array>}
     */
    public static function plan(array $slots, array $employees, array $absent, array $existing, array $cfg = []): array
    {
        $cfg += self::DEFAULTS;
        usort($slots, fn ($a, $b) => [$a['date'], $a['starts_at'], $a['department_id']] <=> [$b['date'], $b['starts_at'], $b['department_id']]);
        $assigned = $existing; // employee_id => shifts
        $out = [];
        $warnings = [];
        foreach ($slots as $slot) {
            $week = Carbon::parse($slot['date'])->startOfWeek()->toDateString();
            $cand = ['date' => $slot['date'], 'starts_at' => $slot['starts_at'], 'ends_at' => $slot['ends_at']];
            $dur = self::minutes($cand);
            $filled = 0;
            for ($i = 0; $i < $slot['headcount']; $i++) {
                $pool = collect($employees)->filter(fn ($e) => $e['department_id'] === $slot['department_id'] && ! in_array($slot['date'], $absent[$e['id']] ?? [], true));
                $ranked = $pool->map(function ($e) use ($assigned, $week, $dur, $slot) {
                    $mine = $assigned[$e['id']] ?? [];
                    $weekMin = collect($mine)->filter(fn ($s) => Carbon::parse($s['date'])->startOfWeek()->toDateString() === $week)->sum(fn ($s) => self::minutes($s));
                    $sameDay = collect($mine)->contains(fn ($s) => $s['date'] === $slot['date']);

                    return $e + ['_over' => $weekMin + $dur > ($e['hours_per_week'] ?: 40) * 60 ? 1 : 0, '_week' => $weekMin, '_day' => $sameDay ? 1 : 0];
                })->sortBy([['_over', 'asc'], ['_day', 'asc'], ['_week', 'asc'], ['id', 'asc']]);
                $picked = null;
                foreach ($ranked as $e) {
                    $mine = $assigned[$e['id']] ?? [];
                    if (count(self::violations([...$mine, $cand], $cfg)) <= count(self::violations($mine, $cfg))) {
                        $picked = $e;
                        break;
                    }
                }
                $row = $cand + ['department_id' => $slot['department_id'], 'template_id' => $slot['template_id'] ?? null, 'role' => $slot['role'] ?? null];
                if ($picked) {
                    $assigned[$picked['id']][] = $cand;
                    $out[] = $row + ['employee_id' => $picked['id']];
                    $filled++;
                } else {
                    $out[] = $row + ['employee_id' => null];
                }
            }
            if ($filled < $slot['headcount']) {
                $warnings[] = ['rule' => 'understaffed', 'date' => $slot['date'], 'department_id' => $slot['department_id'], 'message' => ($slot['headcount'] - $filled).' open shift(s) '.$slot['starts_at'].'-'.$slot['ends_at'].' could not be filled within the labour rules.'];
            }
        }

        return ['shifts' => $out, 'warnings' => $warnings];
    }
}
