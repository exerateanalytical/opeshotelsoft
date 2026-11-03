<?php

namespace App\Modules\Reservations\Revenue\Application;

/**
 * Deterministic rate-suggestion maths: the same inputs always give the same answer.
 * No database access here; SuggestionService gathers the inputs.
 *
 * Input keys: current (int), occupancy_pct, pace_pct, dow (0-6), events [{name, impact_pct}], competitor_avg (?int)
 * Settings keys: high_occupancy_pct, low_occupancy_pct, high_occupancy_uplift, low_occupancy_discount, pace_threshold_pct,
 *                pace_uplift, weekend_uplift, weekend_days, competitor_undercut_pct, max_change_pct, rounding
 */
class SuggestionEngine
{
    /** @return array{suggested:int, change_pct:float, reasons: array<int, array{factor:string, pct:float, detail:string}>} */
    public function evaluate(array $s, array $in): array
    {
        $reasons = [];
        $add = function (string $factor, float $pct, string $detail) use (&$reasons) {
            if (abs($pct) > 0.0001) {
                $reasons[] = ['factor' => $factor, 'pct' => round($pct, 2), 'detail' => $detail];
            }
        };

        $occ = (float) $in['occupancy_pct'];
        if ($occ >= $s['high_occupancy_pct']) {
            $add('occupancy', (float) $s['high_occupancy_uplift'], "occupancy {$this->n($occ)}% >= {$s['high_occupancy_pct']}%");
        } elseif ($occ <= $s['low_occupancy_pct']) {
            $add('occupancy', -(float) $s['low_occupancy_discount'], "occupancy {$this->n($occ)}% <= {$s['low_occupancy_pct']}%");
        }

        if ((float) $in['pace_pct'] >= $s['pace_threshold_pct']) {
            $add('pace', (float) $s['pace_uplift'], "{$this->n((float) $in['pace_pct'])}% of rooms picked up in 7 days");
        }

        if (in_array((int) $in['dow'], array_map('intval', (array) $s['weekend_days']), true)) {
            $add('weekday', (float) $s['weekend_uplift'], 'high-demand weekday');
        }

        foreach ($in['events'] as $e) {
            $add('event', (float) $e['impact_pct'], (string) $e['name']);
        }

        $max = (float) $s['max_change_pct'];
        $pct = array_sum(array_column($reasons, 'pct'));

        if (($in['competitor_avg'] ?? null) && $in['current'] > 0) {
            $target = $in['competitor_avg'] * (1 - (float) $s['competitor_undercut_pct'] / 100);
            $gap = ($target - $in['current']) / $in['current'] * 100;
            // Move halfway to the market, never more than the cap on its own.
            $add('competitor', max(-$max, min($max, $gap / 2)), "competitor average {$in['competitor_avg']}");
            $pct = array_sum(array_column($reasons, 'pct'));
        }

        $pct = max(-$max, min($max, $pct));
        $step = max(1, (int) $s['rounding']);
        $suggested = max($step, (int) (round($in['current'] * (1 + $pct / 100) / $step) * $step));

        return [
            'suggested' => $suggested,
            'change_pct' => $in['current'] > 0 ? round(($suggested - $in['current']) / $in['current'] * 100, 2) : 0.0,
            'reasons' => $reasons,
        ];
    }

    private function n(float $v): string
    {
        return rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
    }
}
